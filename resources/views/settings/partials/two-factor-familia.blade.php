{{--
    Aparelhos com autenticador ligado na FAMÍLIA — rodapé do card de 2FA.

    Quem divide a conta divide o dinheiro: saber quem já protegeu o próprio login
    é informação útil, e cobra de quem ainda não protegeu. Só nome, papel e desde
    quando — nenhum segredo aparece aqui (`two_factor_secret` é `encrypted` e não
    chega nesta tela).

    Espera: $totpDaFamilia (SettingsController).
--}}
@php
    $ativos = $totpDaFamilia->where('ativo', true)->count();
    $total = $totpDaFamilia->count();
@endphp

<div class="tfa-familia">
    <div class="tfa-familia-head">
        <strong>Autenticadores da família</strong>
        <span class="chip">{{ $ativos }} de {{ $total }} {{ $total === 1 ? 'pessoa' : 'pessoas' }}</span>
    </div>

    <ul class="tfa-familia-lista">
        @foreach ($totpDaFamilia as $membro)
            <li class="{{ $membro->ativo ? 'is-on' : '' }}">
                <img class="tfa-familia-foto" src="{{ $membro->avatar }}" alt="" />

                <div class="tfa-familia-txt">
                    <strong>
                        {{ $membro->nome }}
                        @if ($membro->euMesmo)<em>· você</em>@endif
                    </strong>
                    <span>
                        {{ $membro->papel }} ·
                        @if ($membro->ativo)
                            protegido desde {{ $membro->desde->translatedFormat('j \d\e M \d\e Y') }}
                        @else
                            só com senha
                        @endif
                    </span>
                </div>

                @if ($membro->ativo)
                    <span class="tfa-selo on" title="Autenticador ativo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
                    </span>
                @else
                    <span class="tfa-selo off" title="Sem autenticador">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    </span>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($total === 1)
        <p class="tfa-familia-nota">
            Quando você cadastrar <a href="{{ route('dependentes') }}">dependentes</a>, o
            autenticador de cada um aparece aqui.
        </p>
    @endif
</div>
