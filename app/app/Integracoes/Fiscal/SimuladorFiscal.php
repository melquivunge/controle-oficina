<?php

namespace App\Integracoes\Fiscal;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SIMULADO — documentos SEM VALIDADE FISCAL. Regras de teste: valor terminado em 13 centavos é rejeitado;
 * config oficina.fiscal.simular_indisponivel=true simula provedor fora do ar.
 */
class SimuladorFiscal implements EmissorFiscal
{
    public function nome(): string
    {
        return 'simulado';
    }

    public function consultar(string $chave): ?array
    {
        $l = DB::table('simulador_fiscal')->where('chave', $chave)->first();

        return $l ? ['status' => $l->status, 'numero' => $l->numero, 'protocolo' => $l->protocolo, 'mensagem' => $l->mensagem, 'documento' => null] : null;
    }

    public function emitir(string $chave, array $dados): array
    {
        if (config('oficina.fiscal.simular_indisponivel')) {
            throw new RuntimeException('Simulação: emissor fiscal indisponível.');
        }
        if ($anterior = $this->consultar($chave)) {
            return $anterior;
        }
        $rejeita = $dados['valor_centavos'] % 100 === 13;
        $numero = $rejeita ? null : 'SIM-'.str_pad((string) (DB::table('simulador_fiscal')->count() + 1), 6, '0', STR_PAD_LEFT);
        $r = ['status' => $rejeita ? 'rejeitado' : 'autorizado', 'numero' => $numero, 'protocolo' => $rejeita ? null : 'PROT-SIM-'.Str::upper(Str::random(10)),
            'mensagem' => $rejeita ? 'Simulação: rejeição de teste (valor terminado em 13 centavos).' : null];
        DB::table('simulador_fiscal')->insert(['chave' => $chave] + array_diff_key($r, ['documento' => 1]) + ['criado_em' => now()]);
        $r['documento'] = $rejeita ? null : $this->documento($numero, $r, $chave, $dados);

        return $r;
    }

    public function cancelar(string $chave, string $motivo): void
    {
        DB::table('simulador_fiscal')->where('chave', $chave)->update(['status' => 'cancelado', 'mensagem' => $motivo]);
    }

    /** Documento A4 de demonstração (layout inspirado no DANFSe), com tarja de simulação; nunca é documento real. */
    private function documento(string $numero, array $r, string $chave, array $d): string
    {
        $empresa = Empresa::atual();
        $logo = null;
        if ($empresa->logo_caminho && Storage::disk('local')->exists($empresa->logo_caminho) && Storage::disk('local')->size($empresa->logo_caminho) <= 512 * 1024) {
            $mime = Storage::disk('local')->mimeType($empresa->logo_caminho);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($empresa->logo_caminho));
            }
        }
        $verificacao = implode('-', str_split(strtoupper(substr(hash('sha256', 'verificacao|'.$chave), 0, 16)), 4));

        return view('fiscal.documento-simulado', ['numero' => $numero, 'protocolo' => $r['protocolo'], 'verificacao' => $verificacao,
            'emitido' => now(), 'empresa' => $empresa, 'logo' => $logo, 'd' => $d])->render();
    }
}
