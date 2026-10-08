{{-- "Primeiros passos" (08/10/2026 — App\Support\PrimeirosPassos). Na Visão geral, enquanto falta
     algum passo e a pessoa não o escondeu; no Tutorial, sempre (é onde se revê). Os passos se
     marcam sozinhos pelos dados da família. Espera $passos, $contexto ('painel'|'tutorial') e,
     no Tutorial, $oculto. --}}
@php
    $pct = $passos['total'] > 0 ? (int) round($passos['feitos'] / $passos['total'] * 100) : 100;
    $check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 12.5 4 4 8-9"/></svg>';
@endphp
<div class="card span12 passos-card" role="region" aria-labelledby="passos-titulo-{{ $contexto }}" data-primeiros-passos>
    <div class="passos-head">
        <div>
            <h3 id="passos-titulo-{{ $contexto }}">Primeiros passos</h3>
            <p>Comece por aqui. Cada passo se marca sozinho quando você o faz.</p>
        </div>
        <span class="passos-progresso" aria-label="{{ $passos['feitos'] }} de {{ $passos['total'] }} passos feitos"><b>{{ $passos['feitos'] }}</b> de {{ $passos['total'] }}</span>
    </div>
    <div class="passos-barra" aria-hidden="true"><i style="width: {{ $pct }}%"></i></div>

    <ol class="passos-lista">
        @foreach ($passos['essenciais'] as $passo)
            <li @class(['passo', 'feito' => $passo['feito']])>
                <span class="passo-marca" aria-hidden="true">@if ($passo['feito']){!! $check !!}@endif</span>
                <div class="passo-txt">
                    <strong>{{ $passo['titulo'] }}<span class="sr-only">{{ $passo['feito'] ? ' — feito' : ' — falta fazer' }}</span></strong>
                    <span>{{ $passo['texto'] }}</span>
                </div>
                @unless ($passo['feito'])
                    <a class="btn primary" href="{{ $passo['url'] }}" @if ($passo['lancar']) data-launch-open @else data-pjax @endif>{{ $passo['acao'] }}</a>
                @endunless
            </li>
        @endforeach
    </ol>

    <p class="passos-depois">Depois, se quiser:</p>
    <ul class="passos-opcionais">
        @foreach ($passos['opcionais'] as $passo)
            <li>
                <a @class(['passo-chip', 'feito' => $passo['feito']]) href="{{ $passo['url'] }}" data-pjax title="{{ $passo['texto'] }}">
                    <span class="passo-marca" aria-hidden="true">@if ($passo['feito']){!! $check !!}@endif</span>
                    {{ $passo['titulo'] }}<span class="sr-only">{{ $passo['feito'] ? ' — feito' : ' — falta fazer' }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="passos-acoes">
        <button class="btn ghost" type="button" data-tutorial-iniciar>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M8 5.5v13l10-6.5-10-6.5Z"/></svg>
            Fazer o tour pelas telas
        </button>
        @if ($contexto === 'painel')
            <form method="POST" action="{{ route('primeiros-passos.ocultar') }}">
                @csrf
                @method('PATCH')
                <button class="passos-esconder" type="submit">Esconder</button>
            </form>
        @elseif ($oculto ?? false)
            <form method="POST" action="{{ route('primeiros-passos.mostrar') }}">
                @csrf
                @method('PATCH')
                <button class="passos-esconder" type="submit">Mostrar de novo na Visão geral</button>
            </form>
        @endif
    </div>
</div>
