{{-- Mini-gráfico de linha (SVG). Parâmetros: $valores (list<int>), $cor (classe: verde|azul|roxo|ambar). --}}
@php($v = array_values($valores) ?: [0])
@php($max = max(max($v), 1))
@php($n = max(count($v) - 1, 1))
@php($pts = collect($v)->map(fn ($y, $i) => round($i * 100 / $n, 2).','.round(30 - $y * 26 / $max, 2))->implode(' '))
<svg class="spark spark-{{ $cor }}" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <polygon points="0,32 {{ $pts }} 100,32" class="spark-area"/>
    <polyline points="{{ $pts }}" class="spark-linha" vector-effect="non-scaling-stroke"/>
</svg>
