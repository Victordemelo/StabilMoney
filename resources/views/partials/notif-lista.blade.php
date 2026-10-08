{{-- O conteúdo do balão de notificações (vencidas primeiro, depois os próximos 7 dias): o
     mesmo no sino do computador (topbar) e no do celular (mobile-top). --}}
<div class="notif-head"><strong>Vencimentos</strong><span class="notif-sub">{{ $temVencida ? 'há contas vencidas' : 'próximos 7 dias' }}</span></div>
@forelse ($vencimentos as $v)
    @php($dias = (int) $v['diasRestantes'])
    <div class="notif-item">
        <span class="ni-ico ni-{{ $v['tipo'] }}">
            @if ($v['tipo'] === 'fatura')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg>
            @elseif ($v['tipo'] === 'conta_fixa')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4l3 2M21 12a9 9 0 1 1-9-9c2.5 0 4.8 1 6.4 2.7M21 4v4h-4"/></svg>
            @endif
        </span>
        <div class="ni-txt">
            <strong>{{ $v['nome'] }}</strong>
            <span>
                @if ($dias < 0)<em class="ni-late">vencida</em>@elseif ($dias === 0)<em class="ni-late">vence hoje</em>@else vence em {{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}@endif
                · {{ $v['due']->translatedFormat('d \d\e M') }}
            </span>
        </div>
        <b class="ni-val">@brl($v['valor'])</b>
    </div>
@empty
    <div class="notif-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 2v3M16 2v3M4 5h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/><path d="m9.4 13.6 1.9 1.9 3.6-4"/></svg>
        <p>Nada perto de vencer</p>
        <span>Faturas de cartão, contas fixas e recorrências a vencer nos próximos 7 dias aparecem aqui.</span>
    </div>
@endforelse
<a class="notif-ver" href="{{ route('faturas.index') }}" data-pjax>Abrir Contas a pagar</a>
