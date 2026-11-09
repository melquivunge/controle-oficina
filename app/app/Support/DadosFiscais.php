<?php

namespace App\Support;

use App\Models\Empresa;

/**
 * Configuração fiscal da empresa (09/11/2026). Padrões = o que o sistema antigo (SGA) usava nas notas emitidas
 * (extraído dos XML: NFS-e Nacional DF e NF-e 4.00). O contador confere e ajusta em Configurações → Dados fiscais.
 */
final class DadosFiscais
{
    public const TIPOS_ITEM = ['servico' => 'Serviço', 'produto' => 'Peça/produto'];

    public const NOTAS = ['nfse' => 'NFS-e (serviços)', 'nfe' => 'NF-e (peças/produtos)'];

    public const PADRAO = [
        'regime' => 'simples', // Simples Nacional (NFS-e opSimpNac=3 ME/EPP; NF-e CRT=1)
        'inscricao_municipal' => '', 'inscricao_estadual' => '',
        'municipio_ibge' => '5300108', // Brasília/DF
        'nfse_serie' => '3', 'nfse_proximo_numero' => '', 'nfe_proximo_numero' => '',
        'nfse_c_trib_nac' => '140101', 'nfse_c_trib_mun' => '1401', 'nfse_c_nbs' => '120013110', 'nfse_aliquota_iss' => '2.00',
        'nfe_serie' => '1', 'nfe_cfop_dentro' => '5102', 'nfe_cfop_fora' => '6102', 'nfe_csosn' => '102', 'nfe_ncm_padrao' => '87087090',
        'nfe_natureza' => 'VENDA DE MERCADORIA',
    ];

    public const ROTULOS = [
        'regime' => 'Regime tributário', 'inscricao_municipal' => 'Inscrição municipal (CF/DF)', 'inscricao_estadual' => 'Inscrição estadual (CF/DF)',
        'municipio_ibge' => 'Código IBGE do município', 'nfse_c_trib_nac' => 'Código de tributação nacional', 'nfse_c_trib_mun' => 'Código de tributação municipal',
        'nfse_c_nbs' => 'Código NBS',
        'nfse_serie' => 'Série da DPS (NFS-e)', 'nfse_proximo_numero' => 'Próximo número da DPS em produção', 'nfe_proximo_numero' => 'Próximo número da NF-e em produção', 'nfse_aliquota_iss' => 'Alíquota do ISS (%)', 'nfe_serie' => 'Série da NF-e', 'nfe_cfop_dentro' => 'CFOP venda dentro do DF',
        'nfe_cfop_fora' => 'CFOP venda para fora do DF', 'nfe_csosn' => 'CSOSN (Simples Nacional)', 'nfe_ncm_padrao' => 'NCM padrão das peças', 'nfe_natureza' => 'Natureza da operação',
    ];

    /** @return array<string, string> */
    public static function atuais(): array
    {
        return array_merge(self::PADRAO, array_intersect_key((array) (Empresa::atual()->fiscal ?? []), self::PADRAO));
    }

    /**
     * Divide o total aprovado em valor de serviços (NFS-e) e de peças (NF-e). O desconto geral é rateado
     * proporcionalmente; a soma das partes é sempre igual ao total do orçamento.
     *
     * @param  iterable<array{tipo:string,total_centavos:int}|object>  $itens
     * @return array{nfse:int, nfe:int}
     */
    public static function partes(iterable $itens, int $descontoCentavos): array
    {
        $serv = 0;
        $prod = 0;
        foreach ($itens as $i) {
            $i = (array) (is_object($i) && method_exists($i, 'toArray') ? $i->toArray() : $i);
            ($i['tipo'] ?? 'servico') === 'produto' ? $prod += (int) $i['total_centavos'] : $serv += (int) $i['total_centavos'];
        }
        $sub = $serv + $prod;
        $descServ = $sub > 0 ? intdiv($descontoCentavos * $serv * 2 + $sub, $sub * 2) : 0; // arredonda ao centavo

        return ['nfse' => $serv - $descServ, 'nfe' => $prod - ($descontoCentavos - $descServ)];
    }
}
