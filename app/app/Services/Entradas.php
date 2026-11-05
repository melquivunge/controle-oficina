<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\Anexo;
use App\Models\Cliente;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Veiculo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Entradas
{
    /**
     * Registra a entrada do veículo e gera ordem com número único por unidade.
     *
     * @param  array{unidade_id:int, nome:string, telefone:string, placa:string, motivo:string, cliente_id?:?int, modelo?:?string, quilometragem?:?int, observacoes?:?string, contingencia?:bool, horario_original?:?\DateTimeInterface, contingencia_nota?:?string}  $dados  já validados
     * @param  list<UploadedFile>  $fotos
     */
    public function registrar(User $ator, array $dados, array $fotos = []): Ordem
    {
        if (! $ator->pode('registrar_entrada') || ! $ator->acessaUnidade($dados['unidade_id'])) {
            throw new AuthorizationException('Sem permissão para registrar entrada nesta unidade.');
        }
        $unidade = Unidade::where('ativa', true)->findOrFail($dados['unidade_id']);

        $arquivos = [];
        try {
            return DB::transaction(function () use ($ator, $dados, $fotos, $unidade, &$arquivos) {
                $cliente = $this->cliente($dados, $ator);
                // Sem veículo/placa: o cliente trouxe só o item (ex.: jogo de rodas); a OS é identificada pela descrição.
                $semVeiculo = ! empty($dados['sem_veiculo']);
                $veiculo = $semVeiculo ? null : $this->veiculo($dados, $cliente);
                $sequencia = $this->proximoNumero($unidade->id);

                $ordem = new Ordem([
                    'motivo' => $dados['motivo'],
                    'quilometragem' => $dados['quilometragem'] ?? null,
                    'observacoes' => $dados['observacoes'] ?? null,
                ]);
                $ordem->unidade_id = $unidade->id;
                $ordem->sequencia = $sequencia;
                $ordem->numero = sprintf('%s-%05d', $unidade->codigo, $sequencia);
                $ordem->cliente_id = $cliente->id;
                $ordem->veiculo_id = $veiculo?->id;
                $ordem->item_descricao = $semVeiculo ? trim((string) ($dados['item_descricao'] ?? '')) : null;
                $ordem->status = StatusOrdem::Entrada;
                $ordem->criado_por = $ator->id;
                $ordem->contingencia = (bool) ($dados['contingencia'] ?? false);
                // Contingência: entrada_em é o horário original; created_at registra a regularização.
                $ordem->entrada_em = $ordem->contingencia ? $dados['horario_original'] : now();
                $ordem->contingencia_nota = $ordem->contingencia ? ($dados['contingencia_nota'] ?? null) : null;
                $ordem->save();

                foreach ($fotos as $foto) {
                    $arquivos[] = $this->anexar($ordem, $foto, $ator)->caminho;
                }

                Auditoria::registrar($ordem->contingencia ? 'ordem_criada_contingencia' : 'ordem_criada', $ordem, depois: [
                    'numero' => $ordem->numero, 'cliente_id' => $cliente->id, 'veiculo_id' => $veiculo?->id,
                    'placa' => $veiculo?->placa, 'item_descricao' => $ordem->item_descricao, 'entrada_em' => $ordem->entrada_em->toIso8601String(), 'anexos' => count($fotos),
                ], motivo: $ordem->contingencia_nota, ator: $ator);
                app(Mensagens::class)->enfileirar($ordem, 'entrada', app(Orcamentos::class)->novoLink($ordem, $ator));

                return $ordem;
            });
        } catch (\Throwable $e) {
            // Transação desfeita: remove arquivos já gravados para não deixar anexos órfãos.
            Storage::disk('local')->delete($arquivos);
            throw $e;
        }
    }

    public function anexar(Ordem $ordem, UploadedFile $arquivo, User $ator): Anexo
    {
        $caminho = $arquivo->storeAs("ordens/{$ordem->unidade_id}/{$ordem->id}", Str::uuid().'.'.$arquivo->extension(), 'local');

        $anexo = new Anexo;
        $anexo->forceFill([
            'unidade_id' => $ordem->unidade_id,
            'ordem_id' => $ordem->id,
            'caminho' => $caminho,
            'nome_original' => Str::limit($arquivo->getClientOriginalName(), 190, ''),
            'mime' => $arquivo->getMimeType(),
            'tamanho' => $arquivo->getSize(),
            'enviado_por' => $ator->id,
        ])->save();

        return $anexo;
    }

    /** Número sequencial por unidade, atômico sob concorrência (upsert com lock de linha). */
    public function proximoNumero(int $unidadeId): int
    {
        return (int) DB::selectOne(
            'INSERT INTO unidade_sequencias (unidade_id, ultimo_numero) VALUES (?, 1)
             ON CONFLICT (unidade_id) DO UPDATE SET ultimo_numero = unidade_sequencias.ultimo_numero + 1
             RETURNING ultimo_numero',
            [$unidadeId]
        )->ultimo_numero;
    }

    private function cliente(array $dados, User $ator): Cliente
    {
        // Endereço opcional informado na entrada: só aceito de quem vê contato do cliente (mecânico nunca).
        $endereco = $ator->pode('ver_contato_cliente') ? array_filter(\App\Support\Endereco::normalizar($dados['endereco'] ?? []), fn ($v) => $v !== null) : [];
        $cliente = $this->clienteBase($dados);
        if ($endereco) {
            $antes = $cliente->only(\App\Support\Endereco::CAMPOS);
            $cliente->fill($endereco + array_fill_keys(\App\Support\Endereco::CAMPOS, null));
            if ($cliente->isDirty()) {
                $cliente->save();
                Auditoria::registrar('cliente_alterado', $cliente, $antes, $cliente->only(\App\Support\Endereco::CAMPOS), 'Endereço informado na entrada', unidadeId: $dados['unidade_id'], ator: $ator);
            }
        }

        return $cliente;
    }

    private function clienteBase(array $dados): Cliente
    {
        if (! empty($dados['cliente_id'])) {
            return Cliente::ativos()->lockForUpdate()->findOrFail($dados['cliente_id']);
        }

        // Reaproveita cadastro com mesmo telefone e nome; caso contrário cria novo (duplicidades são mescladas depois).
        $existente = Cliente::ativos()->where('telefone', $dados['telefone'])
            ->whereRaw('lower(nome) = ?', [mb_strtolower($dados['nome'])])->first();
        if ($existente) {
            return $existente;
        }
        $cliente = Cliente::create(['nome' => $dados['nome'], 'telefone' => $dados['telefone']]);
        Auditoria::registrar('cliente_criado', $cliente, depois: $cliente->only('nome', 'telefone'), unidadeId: $dados['unidade_id']);

        return $cliente;
    }

    private function veiculo(array $dados, Cliente $cliente): Veiculo
    {
        $veiculo = Veiculo::where('placa', $dados['placa'])->lockForUpdate()->first();
        if (! $veiculo) {
            $veiculo = new Veiculo(['placa' => $dados['placa'], 'modelo' => $dados['modelo'] ?? null]);
            $veiculo->cliente_id = $cliente->id;
            $veiculo->save();
            Auditoria::registrar('veiculo_criado', $veiculo, depois: ['placa' => $veiculo->placa, 'cliente_id' => $cliente->id], unidadeId: $dados['unidade_id']);

            return $veiculo;
        }
        if ($veiculo->cliente_id !== $cliente->id) {
            if (empty($dados['confirmar_transferencia'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['confirmar_transferencia' => 'Esta placa pertence a outro cliente. Confirme a transferência ou use o cadastro existente.']);
            }
            $antes = ['cliente_id' => $veiculo->cliente_id];
            $veiculo->cliente_id = $cliente->id;
            $veiculo->save();
            Auditoria::registrar('veiculo_transferido', $veiculo, $antes, ['cliente_id' => $cliente->id], 'Veículo registrado na entrada com outro cliente', unidadeId: $dados['unidade_id']);
        }
        if (! $veiculo->modelo && ! empty($dados['modelo'])) {
            $veiculo->update(['modelo' => $dados['modelo']]);
        }

        return $veiculo;
    }
}
