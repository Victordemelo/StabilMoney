@extends('layouts.app')

@section('title', 'Métodos de Pagamento — StabilMoney')

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
                    <div class="cc"
                         @if ($conta->color)
                             style="background: linear-gradient(135deg, {{ $conta->color }} 0%, color-mix(in srgb, {{ $conta->color }} 55%, #07140E) 60%, color-mix(in srgb, {{ $conta->color }} 38%, #07140E) 100%)"
                         @endif>
                        <div class="cc-top">
                            <span class="net">{{ $conta->name }}</span>
                            <span class="cc-emoji">{{ $conta->icon ?? '💳' }}</span>
                        </div>
                        <div class="cc-chip"></div>
                        <div class="cc-bot">
                            <div>
                                <div class="lbl">Saldo atual</div>
                                <div class="cc-balance">R$ {{ number_format($conta->balance, 2, ',', '.') }}</div>
                            </div>
                            <div style="text-align:right">
                                <div class="lbl">Tipo</div>
                                <div class="val">{{ $types[$conta->type] ?? $conta->type }}</div>
                            </div>
                        </div>
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
