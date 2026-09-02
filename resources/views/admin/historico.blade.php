@extends('layouts.admin')
@section('title', 'Histórico')

@section('content')
    <h1 style="font-family:Sora,sans-serif;font-size:22px;margin:0 0 6px;color:var(--text)">Histórico do painel</h1>
    <p style="font-size:13px;color:var(--muted);margin:0 0 20px;line-height:1.55">
        Tudo que foi feito por aqui, inclusive as tentativas de acesso que falharam.
        Este registro não é apagável pela interface — é ele que responde "quem fez isso".
    </p>

    <div class="card" style="padding:8px 20px">
        @forelse ($acoes as $acao)
            <div style="padding:13px 0;border-bottom:1px solid var(--line)">
                <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap">
                    <span style="color:{{ $acao->ehAlerta() ? 'var(--neg)' : 'var(--text)' }};font-weight:600;font-size:14px">
                        {{ $acao->rotulo() }}
                    </span>
                    @if ($acao->alvo_descricao)
                        <span style="color:var(--muted);font-size:13px">{{ $acao->alvo_descricao }}</span>
                    @endif
                    <span style="margin-left:auto;font-size:12px;color:var(--muted)">{{ $acao->created_at->format('d/m/Y H:i:s') }}</span>
                </div>
                @if ($acao->motivo)
                    <div style="font-size:12px;color:var(--text);margin-top:4px">Motivo: {{ $acao->motivo }}</div>
                @endif
                <div style="font-size:11px;color:var(--muted);margin-top:4px">
                    {{ $acao->admin?->name ?? 'não autenticado' }}
                    @if ($acao->ip) · IP {{ $acao->ip }} @endif
                </div>
            </div>
        @empty
            <p style="font-size:13px;color:var(--muted);padding:14px 0;margin:0">Nada registrado ainda.</p>
        @endforelse
    </div>

    <div style="margin-top:18px">{{ $acoes->links() }}</div>
@endsection
