<?php

namespace App\Services\WhatsApp;

use App\Enums\StatusOrdem;
use App\Jobs\EnviarMensagemWhatsApp;
use App\Models\Cliente;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use App\Services\Auditoria;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ações da equipe na Central. Toda ação confere no servidor se o usuário vê a conversa (unidade/triagem) e é auditada. */
class Atendimento
{
    public const ANEXOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function autorizar(User $user, WhatsappConversa $conversa): void
    {
        if (! $conversa->visivelPara($user)) {
            throw new AuthorizationException('Conversa de outra unidade ou sem permissão.');
        }
    }

    /** Resposta pela Central: outbox (status "enviando") + fila após o commit. Fora da janela de 24 h só modelo aprovado. */
    public function responder(WhatsappConversa $conversa, User $user, ?string $texto, ?UploadedFile $anexo = null): WhatsappMensagem
    {
        $this->autorizar($user, $conversa);
        $texto = $texto !== null ? trim($texto) : null;
        if (($texto === null || $texto === '') && ! $anexo) {
            throw ValidationException::withMessages(['texto' => 'Escreva a mensagem ou anexe um arquivo.']);
        }
        if (! $conversa->janelaAberta()) {
            throw ValidationException::withMessages(['texto' => 'Fora da janela de 24 h do WhatsApp: o cliente não escreve há mais de 24 h. Só é possível enviar um modelo aprovado.']);
        }
        $caminho = $mime = $nome = null;
        $tamanho = null;
        if ($anexo) {
            $mime = (string) $anexo->getMimeType(); // detectado pelo conteúdo, não pela extensão
            if (! isset(self::ANEXOS[$mime]) || $anexo->getSize() > (int) config('oficina.whatsapp.anexo_max_kb') * 1024) {
                throw ValidationException::withMessages(['anexo' => 'Anexo deve ser imagem (JPG, PNG, WebP) ou PDF de até '.intdiv((int) config('oficina.whatsapp.anexo_max_kb'), 1024).' MB.']);
            }
            $caminho = $anexo->storeAs('whatsapp/'.$conversa->id, Str::uuid().'.'.self::ANEXOS[$mime], 'local');
            $nome = mb_substr(preg_replace('/[^\pL\pN ._-]/u', '', $anexo->getClientOriginalName()) ?: 'anexo.'.self::ANEXOS[$mime], 0, 200);
            $tamanho = $anexo->getSize();
        }
        $tipo = $anexo ? (str_starts_with((string) $mime, 'image/') ? 'imagem' : 'documento') : 'texto';

        return $this->registrarSaida($conversa, $user, ['tipo' => $tipo, 'texto' => $texto ?: null, 'midia_caminho' => $caminho, 'midia_mime' => $mime,
            'midia_nome' => $nome, 'midia_tamanho' => $tamanho, 'midia_status' => $anexo ? 'baixada' : null]);
    }

    /** Modelo aprovado (cadastro simples no número): permitido também fora da janela de 24 h. */
    public function enviarModelo(WhatsappConversa $conversa, User $user, string $modelo): WhatsappMensagem
    {
        $this->autorizar($user, $conversa);
        $existe = collect($conversa->numero->modelos ?? [])->firstWhere('nome', $modelo);
        if (! $existe) {
            throw ValidationException::withMessages(['modelo' => 'Modelo não cadastrado neste número.']);
        }

        return $this->registrarSaida($conversa, $user, ['tipo' => 'modelo', 'modelo' => $modelo, 'texto' => $existe['descricao'] ?? null]);
    }

    private function registrarSaida(WhatsappConversa $conversa, User $user, array $dados): WhatsappMensagem
    {
        if (! $conversa->numero->ativo) {
            throw ValidationException::withMessages(['texto' => 'Este número está desativado: não envia mensagens.']);
        }

        return DB::transaction(function () use ($conversa, $user, $dados) {
            $c = WhatsappConversa::lockForUpdate()->findOrFail($conversa->id);
            $antes = $c->only(['situacao', 'responsavel_id']);
            $m = new WhatsappMensagem($dados + ['conversa_id' => $c->id, 'numero_id' => $c->numero_id, 'direcao' => 'saida', 'origem' => 'sistema',
                'status' => 'enviando', 'autor_id' => $user->id, 'ocorrida_em' => now()]);
            if ($c->aguardando_desde) {
                $m->aguardava_desde = $c->aguardando_desde;
                $m->tempo_resposta_segundos = max(0, (int) $c->aguardando_desde->diffInSeconds(now()));
                $c->aguardando_desde = null;
            }
            $m->save();
            $c->ultima_mensagem_em = now();
            if (in_array($c->situacao, ['nova', 'resolvida'], true)) {
                $c->forceFill(['situacao' => 'em_atendimento', 'resolvida_em' => null, 'motivo_resolucao' => null]);
            }
            $c->responsavel_id ??= $user->id; // quem responde primeiro assume a conversa
            $c->save();
            Auditoria::registrar('whatsapp_respondida', $c, $antes, ['mensagem_id' => $m->id, 'tipo' => $m->tipo, 'situacao' => $c->situacao, 'responsavel_id' => $c->responsavel_id]
                + ($m->tempo_resposta_segundos !== null ? ['tempo_resposta_segundos' => $m->tempo_resposta_segundos] : []), ator: $user);
            EnviarMensagemWhatsApp::dispatch($m->id)->afterCommit();

            return $m;
        });
    }

    public function tentarDeNovo(WhatsappMensagem $m, User $user): void
    {
        $this->autorizar($user, $m->conversa);
        DB::transaction(function () use ($m, $user) {
            $m = WhatsappMensagem::lockForUpdate()->findOrFail($m->id);
            if ($m->status !== 'falhou' || $m->origem !== 'sistema') {
                throw ValidationException::withMessages(['mensagem' => 'Só mensagens enviadas pela Central que falharam podem ser reenviadas.']);
            }
            if ($m->tipo !== 'modelo' && ! $m->conversa->janelaAberta()) {
                throw ValidationException::withMessages(['mensagem' => 'Fora da janela de 24 h: reenvie usando um modelo aprovado.']);
            }
            $m->update(['status' => 'enviando', 'ultimo_erro' => null, 'id_provedor' => null]);
            Auditoria::registrar('whatsapp_reenvio', $m->conversa, depois: ['mensagem_id' => $m->id, 'tentativas' => $m->tentativas], ator: $user);
            EnviarMensagemWhatsApp::dispatch($m->id)->afterCommit();
        });
    }

    /** Falha definitiva: se nenhuma outra resposta cobriu a espera, a conversa volta a contar como sem resposta. */
    public function restaurarEspera(WhatsappMensagem $m): void
    {
        if (! $m->aguardava_desde) {
            return;
        }
        $c = WhatsappConversa::lockForUpdate()->find($m->conversa_id);
        $outraResposta = WhatsappMensagem::where('conversa_id', $c->id)->where('direcao', 'saida')->where('id', '<>', $m->id)
            ->where('status', '<>', 'falhou')->where('ocorrida_em', '>=', $m->ocorrida_em)->exists();
        if (! $c->aguardando_desde && ! $outraResposta && $c->situacao !== 'resolvida') {
            $c->update(['aguardando_desde' => $m->aguardava_desde]);
        }
    }

    public function atribuir(WhatsappConversa $conversa, User $user, ?int $responsavelId): void
    {
        $this->autorizar($user, $conversa);
        $novo = $responsavelId ? User::find($responsavelId) : null;
        if ($responsavelId && (! $novo || ! $novo->ativo || ! $conversa->visivelPara($novo))) {
            throw ValidationException::withMessages(['responsavel_id' => 'Responsável precisa ter acesso a esta conversa.']);
        }
        $this->alterar($conversa, $user, 'whatsapp_atribuida', ['responsavel_id' => $novo?->id]);
    }

    public function mudarSituacao(WhatsappConversa $conversa, User $user, string $situacao, ?string $motivo): void
    {
        $this->autorizar($user, $conversa);
        if (! array_key_exists($situacao, WhatsappConversa::SITUACOES) || $situacao === 'nova') {
            throw ValidationException::withMessages(['situacao' => 'Situação inválida.']);
        }
        $motivo = $motivo !== null ? trim($motivo) : null;
        if ($situacao === 'resolvida' && mb_strlen((string) $motivo) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Informe o motivo para resolver a conversa.']);
        }
        $campos = $situacao === 'resolvida'
            ? ['situacao' => 'resolvida', 'resolvida_em' => now(), 'motivo_resolucao' => mb_substr($motivo, 0, 500), 'aguardando_desde' => null]
            : ['situacao' => $situacao, 'resolvida_em' => null, 'motivo_resolucao' => null];
        $this->alterar($conversa, $user, 'whatsapp_situacao', $campos, $situacao === 'resolvida' ? $motivo : null);
    }

    /** Triagem → unidade. Somente conversas de número central; quem faz triagem (ou tem visão global e atende). */
    public function encaminhar(WhatsappConversa $conversa, User $user, int $unidadeId, ?string $observacao = null): void
    {
        $this->autorizar($user, $conversa);
        if (! $conversa->numero->central()) {
            throw ValidationException::withMessages(['unidade_id' => 'Conversa de número de unidade não é encaminhada.']);
        }
        if (! WhatsappConversa::fazTriagem($user) && ! ($user->visaoGlobal() && WhatsappConversa::atende($user))) {
            throw new AuthorizationException('Sem permissão de triagem.');
        }
        $unidade = Unidade::where('ativa', true)->find($unidadeId);
        if (! $unidade || $unidade->id === $conversa->unidade_id) {
            throw ValidationException::withMessages(['unidade_id' => 'Escolha uma unidade ativa diferente da atual.']);
        }
        $campos = ['unidade_id' => $unidade->id];
        $resp = $conversa->responsavel;
        if ($resp && ! $resp->acessaUnidade($unidade->id)) {
            $campos['responsavel_id'] = null;
        }
        $this->alterar($conversa, $user, 'whatsapp_encaminhada', $campos, $observacao ? mb_substr(trim($observacao), 0, 300) : null, $unidade->id);
    }

    /** Sugestão de encaminhamento: unidade da OS aberta mais recente do cliente vinculado. */
    public function sugestaoUnidade(WhatsappConversa $conversa): ?Unidade
    {
        if (! $conversa->cliente_id) {
            return null;
        }
        $ordem = Ordem::where('cliente_id', $conversa->cliente_id)->whereIn('status', StatusOrdem::abertas())
            ->whereHas('unidade', fn ($q) => $q->where('ativa', true))->orderByDesc('entrada_em')->orderByDesc('id')->first();

        return $ordem?->unidade;
    }

    public function vincularCliente(WhatsappConversa $conversa, User $user, ?int $clienteId): void
    {
        $this->autorizar($user, $conversa);
        $cliente = $clienteId ? Cliente::ativos()->find($clienteId) : null;
        if ($clienteId && ! $cliente) {
            throw ValidationException::withMessages(['cliente_id' => 'Cliente não encontrado.']);
        }
        $campos = ['cliente_id' => $cliente?->id];
        if ($conversa->ordem_id && $conversa->ordem?->cliente_id !== $cliente?->id) {
            $campos['ordem_id'] = null; // OS de outro cliente não fica vinculada
        }
        $this->alterar($conversa, $user, 'whatsapp_vinculo_cliente', $campos);
    }

    /** Cliente novo a partir da conversa: telefone vem da conversa; recusa se já houver cadastro com o telefone (evita duplicar). */
    public function cadastrarCliente(WhatsappConversa $conversa, User $user, array $dados): Cliente
    {
        $this->autorizar($user, $conversa);
        if (! $user->pode('registrar_entrada')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Seu papel não cadastra clientes.');
        }
        if ($conversa->cliente_id) {
            throw ValidationException::withMessages(['nome' => 'Esta conversa já está vinculada a um cliente.']);
        }
        $cliente = DB::transaction(function () use ($conversa, $dados) {
            $existentes = Cliente::ativos()->whereIn('telefone', Recebimento::variacoesTelefone($conversa->contato_telefone))->lockForUpdate()->get();
            if ($existentes->isNotEmpty()) {
                throw ValidationException::withMessages(['nome' => 'Já existe cadastro com este telefone ('.$existentes->pluck('nome')->implode(', ').'). Use "Vincular cliente" para ligar a conversa a ele.']);
            }
            $c = new Cliente(['nome' => $dados['nome'], 'telefone' => $conversa->contato_telefone, 'email' => $dados['email'] ?? null, 'observacoes' => $dados['observacoes'] ?? null]);
            $c->documento = ($dados['documento'] ?? null) ?: null;
            $c->save();
            Auditoria::registrar('cliente_criado', $c, depois: $c->only('nome', 'telefone', 'email') + ['origem' => 'whatsapp', 'conversa_id' => $conversa->id], unidadeId: $conversa->unidade_id);
            if (! empty($dados['placa'])) {
                $v = new \App\Models\Veiculo(['placa' => $dados['placa'], 'marca' => $dados['marca'] ?? null, 'modelo' => $dados['modelo'] ?? null,
                    'ano' => $dados['ano'] ?? null, 'cor' => $dados['cor'] ?? null]);
                $v->cliente_id = $c->id;
                $v->save();
                Auditoria::registrar('veiculo_criado', $v, depois: ['placa' => $v->placa, 'cliente_id' => $c->id], unidadeId: $conversa->unidade_id);
            }

            return $c;
        });
        $this->vincularCliente($conversa, $user, $cliente->id);

        return $cliente;
    }

    public function vincularOrdem(WhatsappConversa $conversa, User $user, ?string $numeroOrdem): void
    {
        $this->autorizar($user, $conversa);
        $ordem = null;
        if ($numeroOrdem) {
            $ordem = Ordem::visiveisPara($user)->where('numero', $numeroOrdem)->first();
            if (! $ordem || ! $conversa->cliente_id || $ordem->cliente_id !== $conversa->cliente_id
                || ($conversa->unidade_id !== null && $ordem->unidade_id !== $conversa->unidade_id)) {
                throw ValidationException::withMessages(['ordem' => 'OS não encontrada entre as do cliente vinculado, desta unidade, que você pode ver.']);
            }
        }
        $this->alterar($conversa, $user, 'whatsapp_vinculo_ordem', ['ordem_id' => $ordem?->id]);
    }

    private function alterar(WhatsappConversa $conversa, User $user, string $acao, array $campos, ?string $motivo = null, ?int $unidadeAudit = null): void
    {
        DB::transaction(function () use ($conversa, $user, $acao, $campos, $motivo, $unidadeAudit) {
            $c = WhatsappConversa::lockForUpdate()->findOrFail($conversa->id);
            $antes = $c->only(array_keys($campos));
            $c->forceFill($campos)->save();
            Auditoria::registrar($acao, $c, $antes, $c->only(array_keys($campos)), motivo: $motivo, unidadeId: $unidadeAudit, ator: $user);
        });
        $conversa->refresh();
    }

    /** Responsáveis possíveis: usuários ativos que veem a conversa. */
    public function responsaveisPossiveis(WhatsappConversa $conversa)
    {
        return User::where('ativo', true)->with('unidade')->orderBy('name')->get()->filter(fn (User $u) => $conversa->visivelPara($u))->values();
    }

    public static function urlMidia(WhatsappMensagem $m): ?string
    {
        return $m->midia_status === 'baixada' && $m->midia_caminho && Storage::disk('local')->exists($m->midia_caminho) ? route('whatsapp.midia', $m) : null;
    }
}
