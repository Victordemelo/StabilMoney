@extends('layouts.app')

@section('title', 'Métodos de Pagamento')

@section('content')
    <div class="section-head">
        <h2>Métodos de Pagamento</h2>
        <span class="sub">Seus cartões, contas e carteiras vivem aqui</span>
        <div class="head-actions">
            <a class="btn-primary" href="{{ route('accounts.create') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Nova conta
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="flash-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <ul>
                @foreach ($errors->all() as $erro)
                    <li>{{ $erro }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($accounts->isEmpty())
        <div class="grid">
            <div class="card span12">
                <div class="empty-state">
                    <div class="pico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg>
                    </div>
                    <h3>Nenhum método de pagamento ainda</h3>
                    <p>Cadastre um cartão, conta ou carteira para começar a registrar transações.</p>
                    <a class="btn-primary" href="{{ route('accounts.create') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Criar primeira conta
                    </a>
                </div>
            </div>
        </div>
    @else
        <div class="grid">
            @foreach ($accounts as $conta)
                <div class="card span4 acct-card">
                    {{-- Visual do cartão: imagem do banco, ou gradiente padrão p/ contas antigas sem banco --}}
                    @if ($conta->bankImageUrl())
                        <div class="bankcard"><img src="{{ $conta->bankImageUrl() }}" alt="{{ $conta->bankLabel() }}" loading="lazy"></div>
                    @else
                        <div class="cc"
                             @if ($conta->color)
                                 style="background: linear-gradient(135deg, {{ $conta->color }} 0%, color-mix(in srgb, {{ $conta->color }} 55%, #07140E) 60%, color-mix(in srgb, {{ $conta->color }} 38%, #07140E) 100%)"
                             @endif>
                            <div class="cc-top"><span class="net">{{ $conta->name }}</span></div>
                            <div class="cc-chip"></div>
                            <div class="cc-bot">
                                <div><div class="lbl">Saldo em conta</div><div class="cc-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available)</div></div>
                            </div>
                        </div>
                    @endif

                    <div class="acct-info">
                        <div class="acct-info-top">
                            <span class="acct-name">{{ $conta->name }}</span>
                            <span class="acct-type">{{ $conta->typeLabel() }}{{ $conta->bankLabel() ? ' · ' . $conta->bankLabel() : '' }}</span>
                        </div>

                        @if ($conta->isDebit())
                            {{-- Cartão de débito espelha o DISPONÍVEL das vinculadas (não o
                                 bruto): o que está guardado em metas/investimentos não pode
                                 aparecer como saldo gastável no cartão. --}}
                            <div class="acct-sub">
                                <span>Corrente <b>@brl($conta->availableChecking)</b></span>
                                <span>Poupança <b>@brl($conta->availableSavings)</b></span>
                            </div>
                            <div class="acct-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available) <span class="acct-balance-lbl">total disponível</span></div>
                        @elseif ($conta->isCard())
                            <div class="acct-balance">@brl($conta->availableLimitDisplay) <span class="acct-balance-lbl">limite disponível</span></div>
                        @else
                            {{-- "Saldo em conta" = disponível (já fora metas e investimentos):
                                 é o número que o usuário pode de fato gastar. --}}
                            <div class="acct-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available) <span class="acct-balance-lbl">saldo em conta</span></div>
                            @if ($conta->reserved > 0)
                                <div class="acct-sub">
                                    <span>Guardado/investido <b>@brl($conta->reserved)</b></span>
                                </div>
                            @endif
                        @endif

                        {{-- Cheque especial: barra de uso ou o limite livre --}}
                        @if ($conta->overdraftLimitValue > 0)
                            @php $pctCheque = (int) min(100, round($conta->overdraftUsed / $conta->overdraftLimitValue * 100)); @endphp
                            <div class="fh-limit">
                                <div class="dp-bar cheque"><i style="width:{{ $pctCheque }}%"></i></div>
                                <div class="dp-meta">
                                    <span>Cheque especial usado</span>
                                    <span>@brl($conta->overdraftUsed) de @brl($conta->overdraftLimitValue)</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="acct-card-actions">
                        <a class="mini-btn" href="{{ route('accounts.edit', $conta) }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>
                            Editar
                        </a>
                        <form method="POST" action="{{ route('accounts.destroy', $conta) }}"
                              onsubmit="return confirm('Excluir esta conta? Contas com transações não podem ser excluídas.')">
                            @csrf
                            @method('DELETE')
                            <button class="mini-btn danger" type="submit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                                Excluir
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach

            {{-- Card pontilhado: adicionar conta --}}
            <a class="cc-add span4" href="{{ route('accounts.create') }}">
                <span class="plus">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                </span>
                Adicionar conta
            </a>
        </div>
    @endif
@endsection
