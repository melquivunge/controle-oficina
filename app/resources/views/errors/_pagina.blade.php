{{-- Página de erro autossuficiente (CSS embutido, sem banco nem build), no visual do sistema. --}}
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>{{ $titulo }} · Controle de Oficina</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:#f3f5f9;font:15px/1.6 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2937}
.caixa{width:100%;max-width:460px;background:#fff;border:1px solid #e4e8ef;border-radius:18px;box-shadow:0 18px 40px rgba(15,27,45,.08);overflow:hidden}
.topo{display:flex;gap:10px;align-items:center;padding:16px 22px;background:#0f1b2d;color:#fff;font-weight:650;font-size:14px}
.topo span{display:grid;place-items:center;width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#2bb37d,#1b7d57)}
.corpo{padding:26px 24px 24px}.codigo{display:inline-block;padding:3px 10px;border-radius:99px;background:{{ $cor ?? '#eef1f6' }};color:{{ $corTexto ?? '#344054' }};font-size:12px;font-weight:700;letter-spacing:.04em}
h1{font-size:22px;letter-spacing:-.4px;margin:12px 0 6px}p{margin:0;color:#4b5563}
a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin-top:20px;padding:0 18px;border-radius:10px;background:#0f1b2d;color:#fff;text-decoration:none;font-weight:600;font-size:14px}
a:focus-visible{outline:3px solid #6ea8ff;outline-offset:2px}
</style></head>
<body><main class="caixa">
<div class="topo"><span aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M3 18V8l9-5 9 5v10M7 21V11h10v10M3 15h18"/></svg></span>Controle de Oficina</div>
<div class="corpo"><span class="codigo">{{ $codigo }}</span><h1>{{ $titulo }}</h1><p>{{ $texto }}</p>@if ($link ?? true)<a href="/">Voltar ao início</a>@endif</div>
</main></body></html>
