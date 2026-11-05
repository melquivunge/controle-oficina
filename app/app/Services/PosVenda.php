<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\Ordem;
use App\Models\PosVendaContato;
use App\Models\User;
use App\Support\Fuso;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Pós-venda (decisões de 30/10/2026, item 8): atendimentos ENTREGUES no período e contatos com o cliente.
 * Autorização sempre no servidor: permissão pos_venda (mecânico nunca) + acesso à unidade da OS.
 */
class PosVenda
{
    public const SITUACOES = [
        'sem_contato' => 'Sem contato registrado',
        'sem_sucesso' => 'Tentado, sem falar com o cliente',
        'contatado' => 'Contatado',
        'retorno' => 'Com retorno agendado',
        'problema' => 'Com problema em aberto',
    ];

    /** Tipo de pendência => coluna que a caracteriza. */
    public const PENDENCIAS = ['pos_venda_problema' => 'problema_servico', 'pos_venda_negociacao' => 'negociacao_fora_padrao'];

    public static function hoje(): string
    {
        return CarbonImmutable::now(Fuso::LOCAL)->toDateString();
    }

    public static function temVendedor(): bool
    {
        return Schema::hasColumn('ordens', 'vendedor_id');
    }

    /** Período padrão: mês atual (horário local). @return array{0: string, 1: string} */
    public static function periodo(?string $de, ?string $ate): array
    {
        $agora = CarbonImmutable::now(Fuso::LOCAL);
        $de = $de ?: $agora->startOfMonth()->toDateString();
        $ate = $ate ?: $agora->endOfMonth()->toDateString();

        return $de <= $ate ? [$de, $ate] : [$ate, $de];
    }

    /**
     * Atendimentos entregues no período, nas unidades visíveis ao usuário.
     * Filtros: de, ate (Y-m-d), unidade (id), situacao (SITUACOES), vendedor (id, se a coluna existir).
     */
    public function consulta(User $user, array $filtros): Builder
    {
        $this->exigirAcesso($user);
        [$de, $ate] = self::periodo($filtros['de'] ?? null, $filtros['ate'] ?? null);
        if (! empty($filtros['unidade']) && ! $user->acessaUnidade((int) $filtros['unidade'])) {
            throw new AuthorizationException('Sem acesso a esta unidade.');
        }
        $ultimo = 'pos_venda_contatos.id = (SELECT MAX(c2.id) FROM pos_venda_contatos c2 WHERE c2.ordem_id = ordens.id)';

        return Ordem::visiveisPara($user)
            ->join('entregas', 'entregas.ordem_id', '=', 'ordens.id')
            ->where('ordens.status', StatusOrdem::Entregue->value)
            ->where('entregas.entregue_em', '>=', Fuso::inicioDoDia($de))
            ->where('entregas.entregue_em', '<', Fuso::inicioDoDia($ate)->addDay())
            ->when($filtros['unidade'] ?? null, fn ($q, $u) => $q->where('ordens.unidade_id', (int) $u))
            ->when(self::temVendedor() && ! empty($filtros['vendedor']), fn ($q) => $q->where('ordens.vendedor_id', (int) $filtros['vendedor']))
            ->when($filtros['situacao'] ?? null, function (Builder $q, string $s) use ($ultimo) {
                $contatos = fn ($s) => $s->from('pos_venda_contatos')->whereColumn('pos_venda_contatos.ordem_id', 'ordens.id');
                match ($s) {
                    'sem_contato' => $q->whereNotExists($contatos),
                    'sem_sucesso' => $q->whereExists($contatos)->whereNotExists(fn ($s) => $contatos($s)->where('resultado', 'atendeu')),
                    'contatado' => $q->whereExists(fn ($s) => $contatos($s)->where('resultado', 'atendeu')),
                    'retorno' => $q->whereExists(fn ($s) => $contatos($s)->whereRaw($ultimo)->whereNotNull('retorno_em')),
                    'problema' => $q->whereExists(fn ($s) => $contatos($s)->where(fn ($w) => $this->problemaAberto($w))),
                    default => null,
                };
            })
            ->select('ordens.*', 'entregas.entregue_em as entregue_em');
    }

    /** Indicadores do período (mesmos filtros da lista) e alertas do usuário (problemas e retornos). */
    public function indicadores(User $user, array $filtros): array
    {
        $base = $this->consulta($user, array_diff_key($filtros, ['situacao' => 1]));
        $ids = (clone $base)->reorder()->select('ordens.id');
        $atendimentos = (clone $base)->reorder()->count('ordens.id');
        $contatados = PosVendaContato::query()->whereIn('ordem_id', $ids)->where('resultado', 'atendeu')->distinct()->count('ordem_id');
        // NPS: última nota de cada atendimento. Promotores 9–10, detratores 0–6.
        $notas = DB::table('pos_venda_contatos')->selectRaw('DISTINCT ON (ordem_id) ordem_id, nota')
            ->whereIn('ordem_id', $ids)->whereNotNull('nota')->orderBy('ordem_id')->orderByDesc('id')->pluck('nota');
        $respostas = $notas->count();
        $nps = $respostas ? (int) round(100 * ($notas->filter(fn ($n) => $n >= 9)->count() - $notas->filter(fn ($n) => $n <= 6)->count()) / $respostas) : null;
        $unidade = $filtros['unidade'] ?? null;
        $retornos = $this->retornosPendentes($user)->when($unidade, fn ($c) => $c->where('unidade_id', (int) $unidade));
        $hoje = self::hoje();

        return [
            'atendimentos' => $atendimentos,
            'contatados' => $contatados,
            'percentual_contatados' => $atendimentos ? (int) round(100 * $contatados / $atendimentos) : 0,
            'nps' => $nps,
            'respostas_nps' => $respostas,
            'problemas_abertos' => $this->problemasAbertos($user)->when($unidade, fn ($c) => $c->where('unidade_id', (int) $unidade))->count(),
            'retornos_hoje' => $retornos->filter(fn ($c) => $c->retorno_em->toDateString() === $hoje)->count(),
            'retornos_atrasados' => $retornos->filter(fn ($c) => $c->retorno_em->toDateString() < $hoje)->count(),
        ];
    }

    /** Contatos por OS (mais recente primeiro), para a lista e para a ficha de pós-venda. @return Collection<int, Collection> */
    public function contatosPorOrdem(Collection $ordemIds): Collection
    {
        return PosVendaContato::with('autor')->whereIn('ordem_id', $ordemIds)->orderByDesc('id')->get()->groupBy('ordem_id');
    }

    /** OS liberada para pós-venda deste usuário (403 outra unidade; 404 se não foi entregue). */
    public function autorizarOrdem(User $user, Ordem $ordem): void
    {
        $this->exigirAcesso($user);
        if (! $user->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Sem acesso a esta unidade.');
        }
        abort_unless($ordem->status === StatusOrdem::Entregue, 404);
    }

    /**
     * @param  array{tentativa: string, resultado: string, nota?: ?int, comentario?: ?string, problema_servico?: bool, problema_descricao?: ?string,
     *               negociacao_fora_padrao?: bool, negociacao_descricao?: ?string, retorno_em?: ?string}  $dados
     */
    public function registrarContato(Ordem $ordem, User $ator, array $dados): PosVendaContato
    {
        $this->autorizarOrdem($ator, $ordem);
        $atendeu = ($dados['resultado'] ?? null) === 'atendeu';
        $erros = [];
        if (! array_key_exists($dados['tentativa'] ?? '', PosVendaContato::TENTATIVAS)) {
            $erros['tentativa'] = 'Escolha ligação ou mensagem.';
        }
        if (! array_key_exists($dados['resultado'] ?? '', PosVendaContato::RESULTADOS)) {
            $erros['resultado'] = 'Escolha o resultado do contato.';
        }
        $nota = isset($dados['nota']) && $dados['nota'] !== '' ? (int) $dados['nota'] : null;
        if ($nota !== null && (! $atendeu || $nota < 0 || $nota > 10)) {
            $erros['nota'] = 'A nota (0 a 10) só é registrada quando o cliente atendeu.';
        }
        $problema = (bool) ($dados['problema_servico'] ?? false);
        $negociacao = (bool) ($dados['negociacao_fora_padrao'] ?? false);
        $descProblema = trim((string) ($dados['problema_descricao'] ?? '')) ?: null;
        $descNegociacao = trim((string) ($dados['negociacao_descricao'] ?? '')) ?: null;
        if ($problema && ! $descProblema) {
            $erros['problema_descricao'] = 'Descreva o problema relatado.';
        }
        if ($negociacao && ! $descNegociacao) {
            $erros['negociacao_descricao'] = 'Descreva a negociação fora do padrão.';
        }
        $retorno = ($dados['retorno_em'] ?? null) ?: null;
        if ($retorno !== null && $retorno < self::hoje()) {
            $erros['retorno_em'] = 'O retorno deve ser hoje ou em data futura.';
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        return DB::transaction(function () use ($ordem, $ator, $dados, $nota, $problema, $negociacao, $descProblema, $descNegociacao, $retorno) {
            $c = PosVendaContato::create([
                'ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'registrado_por' => $ator->id,
                'tentativa' => $dados['tentativa'], 'resultado' => $dados['resultado'], 'nota' => $nota,
                'comentario' => trim((string) ($dados['comentario'] ?? '')) ?: null,
                'problema_servico' => $problema, 'problema_descricao' => $problema ? $descProblema : null,
                'negociacao_fora_padrao' => $negociacao, 'negociacao_descricao' => $negociacao ? $descNegociacao : null,
                'retorno_em' => $retorno, 'registrado_em' => now(),
            ]);
            Auditoria::registrar('pos_venda_contato', $ordem, depois: [
                'contato_id' => $c->id, 'tentativa' => $c->tentativa, 'resultado' => $c->resultado, 'nota' => $nota,
                'problema_servico' => $problema, 'problema_descricao' => $c->problema_descricao,
                'negociacao_fora_padrao' => $negociacao, 'negociacao_descricao' => $c->negociacao_descricao,
                'retorno_em' => $retorno,
            ], motivo: $c->comentario, ator: $ator);

            return $c;
        });
    }

    /**
     * Retornos agendados com data até hoje (inclui atrasados) cujo contato ainda é o último da OS
     * (registrar um novo contato encerra o retorno anterior). Vazio para quem não tem pos_venda.
     *
     * @return Collection<int, PosVendaContato> com ordem carregada, mais antigo primeiro
     */
    public function retornosPendentes(User $user): Collection
    {
        if (! $user->pode('pos_venda')) {
            return collect();
        }

        return PosVendaContato::visiveisPara($user)->with('ordem')
            ->whereNotNull('retorno_em')->where('retorno_em', '<=', self::hoje())
            ->whereRaw('pos_venda_contatos.id = (SELECT MAX(c2.id) FROM pos_venda_contatos c2 WHERE c2.ordem_id = pos_venda_contatos.ordem_id)')
            ->orderBy('retorno_em')->orderBy('id')->get();
    }

    /**
     * Contatos com problema no serviço e/ou negociação fora do padrão ainda sem resolução em Pendências.
     * Vazio para quem não tem pos_venda nem resolver_divergencias.
     *
     * @return Collection<int, PosVendaContato> com ordem carregada, mais recente primeiro
     */
    public function problemasAbertos(User $user): Collection
    {
        if (! $user->pode('pos_venda') && ! $user->pode('resolver_divergencias')) {
            return collect();
        }

        return PosVendaContato::visiveisPara($user)->with('ordem')->where(fn ($w) => $this->problemaAberto($w))->orderByDesc('id')->get();
    }

    /** Condição (sobre pos_venda_contatos) de problema ou negociação sem resolução registrada. */
    private function problemaAberto($q): void
    {
        foreach (self::PENDENCIAS as $tipo => $coluna) {
            $q->orWhere(fn ($q) => $q->where("pos_venda_contatos.{$coluna}", true)->whereNotExists(fn ($s) => $s->from('resolucoes_pendencias')
                ->where('resolucoes_pendencias.tipo', $tipo)->whereColumn('resolucoes_pendencias.referencia_id', 'pos_venda_contatos.id')));
        }
    }

    private function exigirAcesso(User $user): void
    {
        if (! $user->pode('pos_venda')) {
            throw new AuthorizationException('O pós-venda é feito pela recepção, gerente/supervisor ou proprietário.');
        }
    }
}
