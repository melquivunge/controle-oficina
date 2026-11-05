{{-- Variação em relação ao período anterior. Parâmetros: $atual, $anterior, $inverso (true quando subir é ruim). --}}
@if (($anterior ?? 0) > 0)
    @php($pct = round(($atual - $anterior) * 100 / $anterior))
    @php($bom = ($inverso ?? false) ? $pct <= 0 : $pct >= 0)
    <span @class(['variacao', 'boa' => $bom, 'ruim' => ! $bom])><span aria-hidden="true">{{ $pct >= 0 ? '↑' : '↓' }}</span> {{ abs($pct) }}% <span class="muted">vs. período anterior</span></span>
@elseif ($atual > 0)
    <span class="variacao"><span class="muted">Sem dados no período anterior</span></span>
@else
    <span class="variacao"><span class="muted">Sem movimento</span></span>
@endif
