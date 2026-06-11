@extends('layouts.app')

@section('title', $title . ' — StabilMoney')

@section('content')
    @php
        // Ícones por página — mesmos paths do protótipo v2 (design/project/app.js
        // → VIEW_META; "dependentes" vem do modal de dependente do protótipo)
        $icones = [
            'investimentos' => '<path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/>',
            'metas' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/>',
            'faturas' => '<path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6"/>',
            'dependentes' => '<circle cx="9" cy="8" r="3.4"/><path d="M2.5 20c0-3.4 2.9-5.6 6.5-5.6 1 0 2 .2 2.8.5M17 8.5v6M14 11.5h6"/>',
            'config' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.5M12 19v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2.5 12H5M19 12h2.5"/>',
        ];
        $icone = $icones[$page] ?? $icones['config'];
    @endphp

    <div class="section-head">
        <h2>{{ $title }}</h2>
    </div>

    <div class="placeholder">
        <div class="pico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">{!! $icone !!}</svg>
        </div>
        <h2>{{ $title }}</h2>
        <p>{{ $description }}</p>
        <a class="btn-ghost" href="{{ route('dashboard') }}" style="margin-top: 14px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M11 5l-7 7 7 7M4 12h16"/></svg>
            Voltar ao início
        </a>
    </div>
@endsection
