@extends('layouts.admin')
@section('title', 'Visão geral')

@section('content')
    <h1 style="font-family:Sora,sans-serif;font-size:22px;margin:0 0 20px;color:var(--text)">Visão geral</h1>

    {{-- Contagens. NENHUM valor em dinheiro aparece no painel — ver AdminPanelService. --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:26px">
        @php($cartoes = [
            ['Famílias', $resumo['familias'], 'titulares cadastrados'],
            ['Dependentes', $resumo['dependentes'], 'contas vinculadas'],
            ['Novos (7 dias)', $resumo['novos7dias'], 'cadastros na semana'],
            ['Novos (30 dias)', $resumo['novos30dias'], 'cadastros no mês'],
            ['Sem confirmar', $resumo['semConfirmar'], 'e-mail não verificado'],
            ['Banidos', $resumo['banidos'], 'sem acesso'],
        ])
        @foreach ($cartoes as [$rotulo, $valor, $ajuda])
            <div class="card" style="padding:16px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">{{ $rotulo }}</div>
                <div style="font-family:Sora,sans-serif;font-size:26px;font-weight:700;color:{{ $rotulo === 'Banidos' && $valor > 0 ? 'var(--neg)' : 'var(--text)' }}">{{ $valor }}</div>
                <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ $ajuda }}</div>
            </div>
        @endforeach
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">

        {{-- Histórico de cadastros: barras em CSS puro, sem biblioteca. --}}
        <div class="card" style="padding:20px">
            <h2 style="font-family:Sora,sans-serif;font-size:15px;margin:0 0 4px;color:var(--text)">Quem chegou (30 dias)</h2>
            <p style="font-size:12px;color:var(--muted);margin:0 0 16px">Cadastros por dia, incluindo dependentes.</p>

            <div style="display:flex;align-items:flex-end;gap:2px;height:110px">
                @foreach ($historico as $dia)
                    <div title="{{ $dia['rotulo'] }}: {{ $dia['total'] }} cadastro(s)"
                         style="flex:1;min-width:0;height:{{ max(2, (int) round($dia['total'] / $picoDoHistorico * 100)) }}%;
                                background:{{ $dia['total'] ? 'var(--brand-500)' : 'var(--line)' }};
                                border-radius:3px 3px 0 0"></div>
                @endforeach
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:8px">
                <span>{{ $historico[0]['rotulo'] ?? '' }}</span>
                <span>{{ end($historico)['rotulo'] ?? '' }}</span>
            </div>
        </div>

        {{-- Últimos cadastros --}}
        <div class="card" style="padding:20px">
            <h2 style="font-family:Sora,sans-serif;font-size:15px;margin:0 0 16px;color:var(--text)">Cadastros recentes</h2>

            @forelse ($recentes as $pessoa)
                <div class="acct" style="margin-bottom:8px">
                    <div class="ab" style="background:var(--brand-500)">{{ mb_strtoupper(mb_substr($pessoa->name, 0, 1)) }}</div>
                    <div style="min-width:0">
                        <div class="an" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $pessoa->name }}</div>
                        <div class="at">
                            {{ $pessoa->account_owner_id ? 'dependente' : 'titular' }}
                            · {{ $pessoa->created_at->diffForHumans() }}
                        </div>
                    </div>
                    @if ($pessoa->account_owner_id === null)
                        <a class="mini-btn" href="{{ route('painel.pessoa', $pessoa->id) }}">Ver</a>
                    @endif
                </div>
            @empty
                <p style="font-size:13px;color:var(--muted);margin:0">Ninguém cadastrado ainda.</p>
            @endforelse
        </div>

        {{-- Últimas ações do painel --}}
        <div class="card" style="padding:20px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
                <h2 style="font-family:Sora,sans-serif;font-size:15px;margin:0;color:var(--text);flex:1">Últimas ações</h2>
                <a class="mini-btn" href="{{ route('painel.historico') }}">Tudo</a>
            </div>

            @forelse ($acoes as $acao)
                <div style="padding:9px 0;border-bottom:1px solid var(--line);font-size:13px">
                    <span style="color:{{ $acao->ehAlerta() ? 'var(--neg)' : 'var(--text)' }};font-weight:600">{{ $acao->rotulo() }}</span>
                    @if ($acao->alvo_descricao)
                        <span style="color:var(--muted)"> — {{ $acao->alvo_descricao }}</span>
                    @endif
                    <div style="font-size:11px;color:var(--muted);margin-top:3px">
                        {{ $acao->admin?->name ?? 'desconhecido' }} · {{ $acao->created_at->format('d/m/Y H:i') }}
                    </div>
                </div>
            @empty
                <p style="font-size:13px;color:var(--muted);margin:0">Nenhuma ação registrada.</p>
            @endforelse
        </div>
    </div>
@endsection
