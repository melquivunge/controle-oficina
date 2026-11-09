<?php

namespace App\Services;

use App\Models\CatalogoItem;
use App\Models\CatalogoPrecoHistorico;
use App\Models\CatalogoPrecoUnidade;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de serviços e produtos. Autorização no servidor:
 * - gerir: `gerir_catalogo` ou `configurar`, sempre com `ver_valores` (mecânico nunca, mesmo com concessão adulterada);
 * - alterar o desconto máximo: `configurar` ou `aprovar_desconto` (quem tem o limite não pode afrouxá-lo sozinho);
 * - preço por unidade: somente das unidades que o usuário acessa.
 */
class Catalogo
{
    public const MAX_LINHAS_CSV = 5000;

    private const ALIASES_UNIDADE = [
        'roda' => 'roda', 'por roda' => 'roda', 'rodas' => 'roda',
        'jogo' => 'jogo', 'por jogo' => 'jogo', 'jogos' => 'jogo',
        'unidade' => 'unidade', 'un' => 'unidade', 'und' => 'unidade', 'unid' => 'unidade', 'peça' => 'unidade', 'peca' => 'unidade',
        'hora' => 'hora', 'h' => 'hora', 'horas' => 'hora', 'por hora' => 'hora',
    ];

    public static function podeGerir(User $user): bool
    {
        return ($user->pode('gerir_catalogo') || $user->pode('configurar')) && $user->pode('ver_valores');
    }

    public static function podeDefinirLimite(User $user): bool
    {
        return self::podeGerir($user) && ($user->pode('configurar') || $user->pode('aprovar_desconto'));
    }

    public function autorizarGestao(User $user): void
    {
        if (! self::podeGerir($user)) {
            throw new AuthorizationException('Você não tem permissão para gerenciar o catálogo.');
        }
    }

    /**
     * Cria ou altera um item. $dados: nome, categoria, unidade_venda, preco (texto), desconto_max (texto|''|null),
     * ativo (bool), adicionais (ids), precos_unidade ([unidade_id => texto|'']).
     */
    public function salvar(User $ator, array $dados, ?CatalogoItem $item = null): CatalogoItem
    {
        $this->autorizarGestao($ator);
        $nome = trim((string) ($dados['nome'] ?? ''));
        $categoria = trim((string) ($dados['categoria'] ?? ''));
        $erros = [];
        if ($nome === '' || mb_strlen($nome) > 150) {
            $erros['nome'] = 'Informe o nome (até 150 caracteres).';
        }
        if ($categoria === '' || mb_strlen($categoria) > 60) {
            $erros['categoria'] = 'Informe a categoria (até 60 caracteres).';
        }
        $unidadeVenda = (string) ($dados['unidade_venda'] ?? '');
        if (! array_key_exists($unidadeVenda, CatalogoItem::UNIDADES_VENDA)) {
            $erros['unidade_venda'] = 'Unidade de venda inválida.';
        }
        try {
            $preco = Dinheiro::deTexto((string) ($dados['preco'] ?? ''));
        } catch (\InvalidArgumentException) {
            $erros['preco'] = 'Preço inválido. Exemplo: 180,00';
        }
        // Tipo fiscal (09/11/2026): serviço vai na NFS-e; peça/produto na NF-e (com NCM).
        $tipo = (string) ($dados['tipo'] ?? ($item->tipo ?? 'servico'));
        if (! array_key_exists($tipo, \App\Support\DadosFiscais::TIPOS_ITEM)) {
            $erros['tipo'] = 'Escolha Serviço ou Peça/produto.';
        }
        $ncm = preg_replace('/\D/', '', (string) ($dados['ncm'] ?? ($item->ncm ?? '')));
        if ($ncm !== '' && strlen($ncm) !== 8) {
            $erros['ncm'] = 'NCM tem 8 dígitos (ex.: 8708.70.90).';
        }
        $limiteInformado = array_key_exists('desconto_max', $dados);
        $limite = null;
        if ($limiteInformado) {
            try {
                $limite = self::percentual((string) $dados['desconto_max']);
            } catch (\InvalidArgumentException) {
                $erros['desconto_max'] = 'Desconto máximo entre 0 e 100%, com até 2 casas. Deixe em branco para usar a regra geral.';
            }
        }
        $nomeEmUso = CatalogoItem::whereRaw('lower(nome) = ?', [mb_strtolower($nome)])->when($item, fn ($q) => $q->where('id', '<>', $item->id))->exists();
        if ($nome !== '' && $nomeEmUso) {
            $erros['nome'] = 'Já existe um item com este nome no catálogo.';
        }
        $adicionais = array_values(array_unique(array_map('intval', array_filter((array) ($dados['adicionais'] ?? []), fn ($v) => $v !== '' && $v !== null))));
        if ($item && in_array($item->id, $adicionais, true)) {
            $erros['adicionais'] = 'Um item não pode ser adicional dele mesmo.';
        }
        if ($adicionais && CatalogoItem::whereIn('id', $adicionais)->count() !== count($adicionais)) {
            $erros['adicionais'] = 'Adicional inexistente.';
        }
        $precosUnidade = [];
        foreach ((array) ($dados['precos_unidade'] ?? []) as $unidadeId => $texto) {
            $texto = trim((string) $texto);
            $precosUnidade[(int) $unidadeId] = null;
            if ($texto === '') {
                continue;
            }
            try {
                $precosUnidade[(int) $unidadeId] = Dinheiro::deTexto($texto);
            } catch (\InvalidArgumentException) {
                $erros["precos_unidade.$unidadeId"] = 'Preço da unidade inválido. Exemplo: 180,00';
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
        // Limite: só quem pode definir. Para os demais o campo é ignorado se não mudou; se mudou, 403.
        $limiteAtual = $item?->desconto_max_percentual === null ? null : bcadd((string) $item->desconto_max_percentual, '0', 2);
        if ($limiteInformado && $limite !== $limiteAtual && ! self::podeDefinirLimite($ator)) {
            throw new AuthorizationException('Somente o proprietário ou quem aprova descontos altera o desconto máximo.');
        }
        foreach ($precosUnidade as $unidadeId => $_) {
            if (! $ator->acessaUnidade($unidadeId)) {
                throw new AuthorizationException('Você só pode definir preço das unidades em que atua.');
            }
        }

        return DB::transaction(function () use ($ator, $item, $nome, $categoria, $unidadeVenda, $preco, $limiteInformado, $limite, $limiteAtual, $adicionais, $precosUnidade, $dados, $tipo, $ncm) {
            $novo = ! $item;
            $item = $item ? CatalogoItem::lockForUpdate()->findOrFail($item->id) : new CatalogoItem(['criado_por' => $ator->id]);
            $antes = $novo ? null : $item->only('nome', 'categoria', 'unidade_venda', 'preco_centavos', 'desconto_max_percentual', 'ativo');
            $precoAnterior = $novo ? null : (int) $item->preco_centavos;
            $item->fill([
                'nome' => $nome, 'categoria' => $categoria, 'unidade_venda' => $unidadeVenda, 'preco_centavos' => $preco, 'tipo' => $tipo, 'ncm' => $tipo === 'produto' && $ncm !== '' ? $ncm : null,
                'ativo' => (bool) ($dados['ativo'] ?? ($item->ativo ?? true)),
            ]);
            if ($limiteInformado) {
                $item->desconto_max_percentual = $limite;
            }
            $item->save();
            $limiteNovo = $item->desconto_max_percentual === null ? null : bcadd((string) $item->desconto_max_percentual, '0', 2);
            if ($novo || $precoAnterior !== $preco || $limiteAtual !== $limiteNovo) {
                $this->historico($item, null, $precoAnterior, $preco, $novo ? null : $limiteAtual, $limiteNovo, 'manual', $ator);
            }
            if (array_key_exists('adicionais', $dados)) {
                $item->adicionais()->sync($adicionais);
            }
            foreach ($precosUnidade as $unidadeId => $valor) {
                $this->definirPrecoUnidade($item, $unidadeId, $valor, $ator);
            }
            Auditoria::registrar($novo ? 'catalogo_item_criado' : 'catalogo_item_alterado', $item, $antes,
                $item->only('nome', 'categoria', 'unidade_venda', 'preco_centavos', 'desconto_max_percentual', 'ativo', 'tipo', 'ncm') + ['adicionais' => $adicionais],
                unidadeId: $ator->unidade_id, ator: $ator);

            return $item->fresh(['adicionais', 'precosUnidade']);
        });
    }

    private function definirPrecoUnidade(CatalogoItem $item, int $unidadeId, ?int $valor, User $ator): void
    {
        $atual = CatalogoPrecoUnidade::where('catalogo_item_id', $item->id)->where('unidade_id', $unidadeId)->lockForUpdate()->first();
        $anterior = $atual ? (int) $atual->preco_centavos : null;
        if ($anterior === $valor) {
            return;
        }
        if ($valor === null) {
            $atual->delete();
        } elseif ($atual) {
            $atual->update(['preco_centavos' => $valor]);
        } else {
            CatalogoPrecoUnidade::create(['catalogo_item_id' => $item->id, 'unidade_id' => $unidadeId, 'preco_centavos' => $valor]);
        }
        $this->historico($item, $unidadeId, $anterior, $valor, null, null, 'manual', $ator);
        Auditoria::registrar('catalogo_preco_unidade', $item, ['preco_centavos' => $anterior], ['preco_centavos' => $valor], unidadeId: $unidadeId, ator: $ator);
    }

    private function historico(CatalogoItem $item, ?int $unidadeId, ?int $precoAntes, ?int $precoDepois, ?string $limAntes, ?string $limDepois, string $origem, User $ator): void
    {
        CatalogoPrecoHistorico::create([
            'catalogo_item_id' => $item->id, 'unidade_id' => $unidadeId,
            'preco_anterior_centavos' => $precoAntes, 'preco_novo_centavos' => $precoDepois,
            'desconto_max_anterior' => $limAntes, 'desconto_max_novo' => $limDepois,
            'origem' => $origem, 'alterado_por' => $ator->id, 'alterado_em' => now(),
        ]);
    }

    /**
     * Importação CSV simples: nome;categoria;unidade;preço;desconto_max (cabeçalho opcional; vírgula também aceita como separador
     * quando não houver ";"). Linhas válidas são gravadas (nome já existente = atualização); as inválidas voltam no relatório.
     *
     * @return array{criados:int, atualizados:int, inalterados:int, recusadas:list<array{linha:int, conteudo:string, motivos:list<string>}>}
     */
    public function importarCsv(User $ator, string $conteudo): array
    {
        $this->autorizarGestao($ator);
        if (str_starts_with($conteudo, "\xEF\xBB\xBF")) {
            $conteudo = substr($conteudo, 3);
        }
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252'); // planilha salva pelo Excel em pt-BR
        }
        $linhas = preg_split('/\r\n|\n|\r/', $conteudo);
        if (count($linhas) > self::MAX_LINHAS_CSV + 1) {
            throw ValidationException::withMessages(['arquivo' => 'Arquivo com linhas demais (máximo '.self::MAX_LINHAS_CSV.').']);
        }
        $podeLimite = self::podeDefinirLimite($ator);
        $relatorio = ['criados' => 0, 'atualizados' => 0, 'inalterados' => 0, 'recusadas' => []];
        $validas = [];
        $vistos = [];
        foreach ($linhas as $i => $bruta) {
            $numero = $i + 1;
            if (trim($bruta) === '') {
                continue;
            }
            $sep = str_contains($bruta, ';') ? ';' : ',';
            $cols = array_map('trim', str_getcsv($bruta, $sep, '"', ''));
            if ($i === 0 && mb_strtolower($cols[0] ?? '') === 'nome') {
                continue; // cabeçalho
            }
            $motivos = [];
            if (count($cols) < 4 || count($cols) > 5) {
                $motivos[] = 'Esperadas 4 ou 5 colunas: nome;categoria;unidade;preço;desconto_max.';
            } else {
                [$nome, $categoria, $unidade, $preco] = $cols;
                $desconto = $cols[4] ?? '';
                if ($nome === '' || mb_strlen($nome) > 150) {
                    $motivos[] = 'Nome vazio ou com mais de 150 caracteres.';
                }
                if ($categoria === '' || mb_strlen($categoria) > 60) {
                    $motivos[] = 'Categoria vazia ou com mais de 60 caracteres.';
                }
                $unidadeVenda = self::ALIASES_UNIDADE[mb_strtolower($unidade)] ?? null;
                if (! $unidadeVenda) {
                    $motivos[] = "Unidade “{$unidade}” inválida (use roda, jogo, unidade ou hora).";
                }
                try {
                    $precoCentavos = Dinheiro::deTexto($preco);
                } catch (\InvalidArgumentException) {
                    $motivos[] = "Preço “{$preco}” inválido (ex.: 180,00).";
                }
                try {
                    $limite = self::percentual($desconto);
                } catch (\InvalidArgumentException) {
                    $motivos[] = "Desconto máximo “{$desconto}” inválido (0 a 100).";
                }
                if (isset($limite) && $limite !== null && ! $podeLimite) {
                    $motivos[] = 'Sem permissão para definir desconto máximo (deixe a coluna em branco).';
                }
                $chave = mb_strtolower($nome);
                if ($nome !== '' && isset($vistos[$chave])) {
                    $motivos[] = "Nome repetido no arquivo (linha {$vistos[$chave]}).";
                }
                $vistos[$chave] ??= $numero;
            }
            if ($motivos) {
                $relatorio['recusadas'][] = ['linha' => $numero, 'conteudo' => mb_substr($bruta, 0, 200), 'motivos' => $motivos];
            } else {
                $validas[] = compact('nome', 'categoria', 'unidadeVenda', 'precoCentavos', 'limite');
            }
            unset($nome, $categoria, $unidade, $preco, $desconto, $unidadeVenda, $precoCentavos, $limite);
        }

        DB::transaction(function () use ($validas, $ator, $podeLimite, &$relatorio) {
            foreach ($validas as $v) {
                $item = CatalogoItem::whereRaw('lower(nome) = ?', [mb_strtolower($v['nome'])])->lockForUpdate()->first();
                if (! $item) {
                    $item = CatalogoItem::create(['nome' => $v['nome'], 'categoria' => $v['categoria'], 'unidade_venda' => $v['unidadeVenda'],
                        'preco_centavos' => $v['precoCentavos'], 'desconto_max_percentual' => $v['limite'], 'ativo' => true, 'criado_por' => $ator->id]);
                    $this->historico($item, null, null, $v['precoCentavos'], null, $v['limite'], 'importacao', $ator);
                    $relatorio['criados']++;

                    continue;
                }
                $limAntes = $item->desconto_max_percentual === null ? null : bcadd((string) $item->desconto_max_percentual, '0', 2);
                // Coluna de desconto em branco mantém o limite atual (não apaga limite definido na tela).
                $limDepois = $v['limite'] ?? $limAntes;
                $precoAntes = (int) $item->preco_centavos;
                $item->fill(['categoria' => $v['categoria'], 'unidade_venda' => $v['unidadeVenda'], 'preco_centavos' => $v['precoCentavos'], 'desconto_max_percentual' => $limDepois]);
                if (! $item->isDirty()) {
                    $relatorio['inalterados']++;

                    continue;
                }
                $item->save();
                if ($precoAntes !== $v['precoCentavos'] || $limAntes !== $limDepois) {
                    $this->historico($item, null, $precoAntes, $v['precoCentavos'], $limAntes, $limDepois, 'importacao', $ator);
                }
                $relatorio['atualizados']++;
            }
            Auditoria::registrar('catalogo_importado', null, null, [
                'criados' => $relatorio['criados'], 'atualizados' => $relatorio['atualizados'], 'inalterados' => $relatorio['inalterados'],
                'recusadas' => count($relatorio['recusadas']),
            ], unidadeId: $ator->unidade_id, ator: $ator);
        });

        return $relatorio;
    }

    /** "10" | "10,5" | "10%" → "10.50"; vazio → null (regra geral). */
    public static function percentual(?string $texto): ?string
    {
        $t = str_replace([',', '%', ' '], ['.', '', ''], trim((string) $texto));
        if ($t === '') {
            return null;
        }
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $t) || bccomp($t, '100', 2) > 0) {
            throw new \InvalidArgumentException('Percentual inválido.');
        }

        return bcadd($t, '0', 2);
    }
}
