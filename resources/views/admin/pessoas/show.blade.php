@extends('layouts.admin')
@section('title', $titular->name)

@section('content')
    <a href="{{ route('painel.pessoas') }}" style="font-size:13px;color:var(--muted);text-decoration:none">← Todas as pessoas</a>

    @php($banido = $titular->banned_at !== null)

    <div class="card" style="padding:22px;margin-top:14px;{{ $banido ? 'border-color:var(--neg)' : '' }}">
        <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div class="ab" style="background:var(--brand-500);flex:0 0 auto">{{ mb_strtoupper(mb_substr($titular->name, 0, 1)) }}</div>
            <div style="flex:1 1 240px;min-width:0">
                <h1 style="font-family:Sora,sans-serif;font-size:20px;margin:0;color:var(--text)">{{ $titular->name }}</h1>
                <div style="font-size:13px;color:var(--muted);margin-top:4px">{{ $titular->email }}</div>
                @if ($titular->phone)
                    <div style="font-size:13px;color:var(--muted)">{{ $titular->phone }}</div>
                @endif
            </div>
            @if ($banido)
                <span style="background:var(--neg);color:#fff;font:600 11px/1 system-ui,sans-serif;padding:6px 11px;border-radius:999px">BANIDO</span>
            @endif
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-top:20px;
                    padding-top:18px;border-top:1px solid var(--line)">
            @php($linhas = [
                'Cadastro' => $titular->created_at->format('d/m/Y H:i'),
                'E-mail confirmado' => $titular->email_verified_at ? $titular->email_verified_at->format('d/m/Y') : 'Não',
                'Último acesso' => $acessos->has($titular->id) ? $acessos[$titular->id]->diffForHumans() : 'Nunca',
                'Aceite dos termos' => $titular->terms_accepted_at
                    ? $titular->terms_accepted_at->format('d/m/Y') . ' (v' . $titular->terms_version . ')'
                    : '—',
            ])
            @foreach ($linhas as $rotulo => $valor)
                <div>
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">{{ $rotulo }}</div>
                    <div style="font-size:14px;color:var(--text);margin-top:3px">{{ $valor }}</div>
                </div>
            @endforeach
        </div>

        {{-- O que a família TEM, em quantidade. Valores nunca são consultados. --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px;margin-top:18px;
                    padding-top:18px;border-top:1px solid var(--line)">
            @foreach ([
                'Contas' => $titular->accounts_count,
                'Categorias' => $titular->categories_count,
                'Lançamentos' => $titular->transactions_count,
                'Metas' => $titular->goals_count,
                'Investimentos' => $titular->investments_count,
                'Dependentes' => $titular->dependents_count,
            ] as $rotulo => $qtd)
                <div style="text-align:center;padding:12px 8px;border:1px solid var(--line);border-radius:10px">
                    <div style="font-family:Sora,sans-serif;font-size:20px;font-weight:700;color:var(--text)">{{ $qtd }}</div>
                    <div style="font-size:11px;color:var(--muted);margin-top:2px">{{ $rotulo }}</div>
                </div>
            @endforeach
        </div>

        <p style="font-size:12px;color:var(--muted);margin:16px 0 0;line-height:1.5">
            Saldos, valores de lançamento, metas e investimentos não são exibidos nem consultados
            por este painel.
        </p>
    </div>

    {{-- Dependentes --}}
    <div class="card" style="padding:22px;margin-top:14px">
        <h2 style="font-family:Sora,sans-serif;font-size:16px;margin:0 0 14px;color:var(--text)">
            Dependentes ({{ $titular->dependents->count() }})
        </h2>

        @forelse ($titular->dependents as $dep)
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid var(--line)">
                <div class="ab" style="background:var(--c-lazer,var(--brand-500));flex:0 0 auto">{{ mb_strtoupper(mb_substr($dep->name, 0, 1)) }}</div>
                <div style="flex:1 1 200px;min-width:0">
                    <div style="font-size:14px;color:var(--text);font-weight:600">
                        {{ $dep->name }}
                        @if ($dep->banned_at)
                            <span style="color:var(--neg);font-size:11px">· banido</span>
                        @endif
                    </div>
                    <div style="font-size:12px;color:var(--muted)">
                        {{ $dep->email }}
                        @if ($dep->relationshipLabel()) · {{ $dep->relationshipLabel() }} @endif
                    </div>
                    <div style="font-size:11px;color:var(--muted);margin-top:2px">
                        {{ $dep->made_transactions_count }} lançamento(s) feitos ·
                        {{ $acessos->has($dep->id) ? 'último acesso ' . $acessos[$dep->id]->diffForHumans() : 'nunca acessou' }}
                    </div>
                </div>

                <div style="display:flex;gap:8px">
                    @if ($dep->banned_at)
                        <form method="POST" action="{{ route('painel.desbanir', $dep->id) }}">
                            @csrf
                            <button type="submit" class="btn-ghost" style="padding:7px 13px;font-size:12px">Desbanir</button>
                        </form>
                    @else
                        <details>
                            <summary class="btn-ghost" style="padding:7px 13px;font-size:12px;cursor:pointer;list-style:none">Banir</summary>
                            <form method="POST" action="{{ route('painel.banir', $dep->id) }}" style="margin-top:8px;min-width:240px">
                                @csrf
                                <input class="input" type="text" name="motivo" required minlength="5" maxlength="500"
                                       placeholder="Motivo (fica no histórico)">
                                <button type="submit" class="btn-primary" style="width:100%;margin-top:6px;font-size:12px">Confirmar banimento</button>
                            </form>
                        </details>
                    @endif
                </div>
            </div>
        @empty
            <p style="font-size:13px;color:var(--muted);margin:0">Nenhum dependente.</p>
        @endforelse
    </div>

    {{-- Ações de moderação sobre o titular --}}
    <div class="card" style="padding:22px;margin-top:14px;border-color:var(--neg)">
        <h2 style="font-family:Sora,sans-serif;font-size:16px;margin:0 0 6px;color:var(--text)">Moderação</h2>
        <p style="font-size:12px;color:var(--muted);margin:0 0 18px;line-height:1.55">
            Banir o titular tira o acesso dele <strong>e o dos dependentes</strong> — eles enxergam
            exatamente os mesmos dados da família. Nada é apagado, e dá para desbanir.
        </p>

        @if ($banido)
            <div class="flash-error" role="status" style="margin-bottom:14px">
                Banido em {{ $titular->banned_at->format('d/m/Y H:i') }}.
                @if ($titular->banned_reason) Motivo: {{ $titular->banned_reason }} @endif
            </div>
            <form method="POST" action="{{ route('painel.desbanir', $titular->id) }}">
                @csrf
                <button type="submit" class="btn-primary">Devolver o acesso</button>
            </form>
        @else
            <form method="POST" action="{{ route('painel.banir', $titular->id) }}" style="max-width:460px">
                @csrf
                <div class="field">
                    <label for="motivo">Motivo do banimento</label>
                    <input class="input" type="text" id="motivo" name="motivo" required minlength="5" maxlength="500"
                           placeholder="Ex.: uso indevido reportado em 06/08">
                </div>
                <button type="submit" class="btn-primary">Banir esta conta</button>
            </form>
        @endif

        <hr style="border:0;border-top:1px solid var(--line);margin:22px 0">

        <h3 style="font-size:14px;margin:0 0 6px;color:var(--neg)">Excluir definitivamente</h3>
        <p style="font-size:12px;color:var(--muted);margin:0 0 14px;line-height:1.55">
            Apaga a conta, os dependentes, as fotos e todos os dados financeiros. <strong>Não tem
            volta.</strong> Para confirmar, digite o e-mail da pessoa.
        </p>
        <form method="POST" action="{{ route('painel.excluir', $titular->id) }}" style="max-width:460px">
            @csrf
            @method('DELETE')
            <div class="field">
                <label for="confirmacao">Digite <code>{{ $titular->email }}</code></label>
                <input class="input" type="text" id="confirmacao" name="confirmacao" required autocomplete="off">
            </div>
            <button type="submit" class="btn-ghost" style="color:var(--neg);border-color:var(--neg)">
                Excluir esta conta para sempre
            </button>
        </form>
    </div>

    {{-- Histórico desta família --}}
    <div class="card" style="padding:22px;margin-top:14px">
        <h2 style="font-family:Sora,sans-serif;font-size:16px;margin:0 0 14px;color:var(--text)">Histórico de moderação</h2>

        @forelse ($acoes as $acao)
            <div style="padding:10px 0;border-bottom:1px solid var(--line);font-size:13px">
                <span style="color:{{ $acao->ehAlerta() ? 'var(--neg)' : 'var(--text)' }};font-weight:600">{{ $acao->rotulo() }}</span>
                @if ($acao->motivo)
                    <span style="color:var(--muted)"> — {{ $acao->motivo }}</span>
                @endif
                <div style="font-size:11px;color:var(--muted);margin-top:3px">
                    {{ $acao->admin?->name ?? 'desconhecido' }} · {{ $acao->created_at->format('d/m/Y H:i') }}
                    @if ($acao->ip) · IP {{ $acao->ip }} @endif
                </div>
            </div>
        @empty
            <p style="font-size:13px;color:var(--muted);margin:0">Nada registrado sobre esta família.</p>
        @endforelse
    </div>
@endsection
