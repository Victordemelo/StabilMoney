@extends('layouts.admin')
@section('title', 'Pessoas')

@section('content')
    <h1 style="font-family:Sora,sans-serif;font-size:22px;margin:0 0 6px;color:var(--text)">Pessoas</h1>
    <p style="font-size:13px;color:var(--muted);margin:0 0 20px">
        Uma linha por família. O painel mostra a estrutura da conta — nunca valores.
    </p>

    <form method="GET" action="{{ route('painel.pessoas') }}"
          style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
        <input class="input" type="search" name="q" value="{{ $busca }}" placeholder="Buscar por nome ou e-mail"
               style="flex:1 1 240px">
        <select class="input" name="filtro" style="flex:0 1 180px">
            @foreach (['todos' => 'Todos', 'ativos' => 'Ativos', 'banidos' => 'Banidos', 'sem_confirmar' => 'E-mail não confirmado'] as $valor => $rotulo)
                <option value="{{ $valor }}" @selected($filtro === $valor)>{{ $rotulo }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-primary" style="flex:0 0 auto">Filtrar</button>
    </form>

    @forelse ($familias as $titular)
        @php($banido = $titular->banned_at !== null)
        <div class="card" style="padding:18px;margin-bottom:12px;{{ $banido ? 'border-color:var(--neg)' : '' }}">

            <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">
                <div class="ab" style="background:var(--brand-500);flex:0 0 auto">{{ mb_strtoupper(mb_substr($titular->name, 0, 1)) }}</div>

                <div style="flex:1 1 220px;min-width:0">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <strong style="font-size:15px;color:var(--text)">{{ $titular->name }}</strong>
                        @if ($banido)
                            <span style="background:var(--neg);color:#fff;font:600 10px/1 system-ui,sans-serif;padding:4px 8px;border-radius:999px">BANIDO</span>
                        @endif
                        @if (! $titular->email_verified_at)
                            <span style="background:var(--line);color:var(--muted);font:600 10px/1 system-ui,sans-serif;padding:4px 8px;border-radius:999px">E-MAIL NÃO CONFIRMADO</span>
                        @endif
                    </div>
                    <div style="font-size:13px;color:var(--muted);margin-top:3px">{{ $titular->email }}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:6px">
                        Cadastrou-se {{ $titular->created_at->format('d/m/Y') }}
                        @if ($acessos->has($titular->id))
                            · último acesso {{ $acessos[$titular->id]->diffForHumans() }}
                        @else
                            · nunca acessou
                        @endif
                    </div>
                </div>

                {{-- Contagens: quantos registros, nunca quanto dinheiro. --}}
                <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--muted)">
                    @foreach ([
                        'contas' => $titular->accounts_count,
                        'lançamentos' => $titular->transactions_count,
                        'metas' => $titular->goals_count,
                        'investimentos' => $titular->investments_count,
                    ] as $rotulo => $qtd)
                        <div style="text-align:center">
                            <div style="font-family:Sora,sans-serif;font-size:16px;font-weight:700;color:var(--text)">{{ $qtd }}</div>
                            <div>{{ $rotulo }}</div>
                        </div>
                    @endforeach
                </div>

                <a class="btn-ghost" href="{{ route('painel.pessoa', $titular->id) }}"
                   style="flex:0 0 auto;padding:9px 16px;font-size:13px;text-decoration:none">Abrir</a>
            </div>

            @if ($titular->dependents->isNotEmpty())
                <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line)">
                    <div style="font-size:11px;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">
                        {{ $titular->dependents->count() }} dependente(s)
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @foreach ($titular->dependents as $dep)
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid var(--line);
                                         border-radius:999px;font-size:12px;color:var(--text)">
                                {{ $dep->name }}
                                @if ($dep->relationship)
                                    <span style="color:var(--muted)">· {{ $dep->relationshipLabel() }}</span>
                                @endif
                                @if ($dep->banned_at)
                                    <span style="color:var(--neg);font-weight:600">banido</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="empty-state">
            <h3>Ninguém por aqui</h3>
            <p>Nenhuma pessoa corresponde a esse filtro.</p>
        </div>
    @endforelse

    <div style="margin-top:18px">{{ $familias->links() }}</div>
@endsection
