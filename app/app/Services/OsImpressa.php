<?php

namespace App\Services;

use App\Models\Devolucao;
use App\Models\Empresa;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Rules\Cnpj;
use App\Support\Endereco;
use App\Support\Normaliza;
use Illuminate\Support\Facades\Storage;

/**
 * Dados da OS impressa (A4). Não é documento fiscal. A versão do cliente (portal) usa os mesmos dados,
 * sem informações internas (observações internas da entrada e histórico).
 */
class OsImpressa
{
    public function dados(Ordem $ordem, bool $interno): array
    {
        $ordem->loadMissing('cliente', 'veiculo', 'unidade', 'vendedor');
        $empresa = Empresa::atual();
        $unidade = $ordem->unidade;
        $enderecoProprio = Endereco::preenchido($unidade);

        $orcamento = Orcamento::with('itens')->where('ordem_id', $ordem->id)->where('status', 'aprovado')->first();
        $pendente = $orcamento ? null : Orcamento::with('itens')->where('ordem_id', $ordem->id)->where('status', 'enviado')->first();
        $pagamentos = Pagamento::where('ordem_id', $ordem->id)->whereIn('status', ['confirmado', 'registrado'])->orderBy('recebido_em')->get();
        $devolucoes = Devolucao::where('ordem_id', $ordem->id)->where('status', 'concluida')
            ->whereHas('pagamento', fn ($q) => $q->whereIn('status', ['confirmado', 'registrado']))->orderBy('id')->get();

        return [
            'ordem' => $ordem,
            'interno' => $interno,
            'loja' => [
                'nome' => $empresa->nome_fantasia ?: $unidade->nome,
                'razao_social' => $empresa->razao_social,
                'unidade' => $unidade->nome,
                'cnpj' => Cnpj::exibicao($unidade->cnpj ?: $empresa->cnpj),
                'telefone' => Normaliza::telefoneExibicao($unidade->telefone ?: $empresa->telefone),
                'email' => $empresa->email,
                'endereco' => $enderecoProprio ? Endereco::texto($unidade) : Endereco::texto($empresa),
                'cor' => preg_match('/^#[0-9a-f]{6}$/', (string) $empresa->cor_principal) ? $empresa->cor_principal : '#1f2933',
                'logo' => $this->logoDataUri($empresa),
            ],
            'orcamento' => $orcamento ?? $pendente,
            'aprovado' => (bool) $orcamento,
            'resumo' => app(Financeiro::class)->resumo($ordem),
            'pagamentos' => $pagamentos,
            'devolucoes' => $devolucoes,
        ];
    }

    /** Logo embutida (data URI): o documento do portal não tem sessão; o arquivo continua privado no disco. */
    private function logoDataUri(Empresa $empresa): ?string
    {
        $c = $empresa->logo_caminho;
        if (! $c || ! Storage::disk('local')->exists($c)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($c);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($c));
    }
}
