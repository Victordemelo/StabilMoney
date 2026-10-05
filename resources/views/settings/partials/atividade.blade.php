{{--
    Aba "Atividade" — Configurações › Atividade (out/2026).

    Quem mexeu em quê, onde e quando: cada lançamento, cadastro, mudança na família e
    acesso (entrar, sair, senha, 2FA). O titular vê a família inteira; o dependente, só o
    que ele mesmo fez.

    A frase vem PRONTA do servidor (`atividades.descricao`, montada quando a ação
    aconteceu) e é impressa com {{ }}: ela leva nomes digitados pelo usuário.

    Espera: $atividades (paginador), $membros, $grupos, $filtroPessoa, $filtroTipo,
    $filtroDe, $filtroAte, $filtrando (SettingsController::atividade).
--}}
@php
    use App\Support\BrowserSessions;

    $titular = $user->isTitular();
    $icones = [
        'dinheiro' => '<path d="M3 7h18v10H3z"/><circle cx="12" cy="12" r="2.6"/><path d="M6.5 10v4M17.5 10v4"/>',
        'cadastros' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><path d="M17 13.5v7M13.5 17h7"/>',
        'familia' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 6.2a3.2 3.2 0 0 1 0 6M18.5 20a6.4 6.4 0 0 0-2-4.6"/>',
        'acesso' => '<path d="M12 3 5 6v5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6l-7-3Z"/><path d="M12 10v3"/><circle cx="12" cy="15.6" r=".6"/>',
    ];
@endphp

<div class="card span12">
    <div class="card-head">
        <h3>Atividade {{ $titular ? 'da família' : 'da sua conta' }}</h3>
        <span class="chip">Últimos 6 meses</span>
    </div>
    <p class="sec-card-desc">
        @if ($titular)
            Tudo o que aconteceu na conta: quem lançou, editou ou excluiu, quem entrou e de qual aparelho.
            Cada pessoa da família vê aqui só o que ela mesma fez.
        @else
            O que você fez na conta da família: lançamentos, cadastros e acessos. O titular também vê esta lista.
        @endif
    </p>

    <form class="filter-bar" method="GET" action="{{ route('settings', 'atividade') }}">
        @if ($titular && $membros->count() > 1)
            <div class="field">
                <label for="atv-pessoa">Pessoa</label>
                <select class="input" id="atv-pessoa" name="pessoa">
                    <option value="">Todas</option>
                    @foreach ($membros as $membro)
                        <option value="{{ $membro->id }}" @selected($filtroPessoa === $membro->id)>{{ $membro->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field">
            <label for="atv-tipo">Tipo</label>
            <select class="input" id="atv-tipo" name="tipo">
                <option value="">Tudo</option>
                @foreach ($grupos as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($filtroTipo === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="atv-de">De</label>
            <input class="input" type="date" id="atv-de" name="de" value="{{ $filtroDe }}" max="{{ now()->format('Y-m-d') }}">
        </div>
        <div class="field">
            <label for="atv-ate">Até</label>
            <input class="input" type="date" id="atv-ate" name="ate" value="{{ $filtroAte }}" max="{{ now()->format('Y-m-d') }}">
        </div>

        <button class="btn-ghost" type="submit">Filtrar</button>

        @if ($filtrando)
            <a class="btn-ghost filtro-limpar" href="{{ route('settings', 'atividade') }}">Limpar</a>
        @endif
    </form>
</div>

<div class="card span12">
    @if ($atividades->isEmpty())
        <div class="empty-state">
            <div class="pico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>
            </div>
            @if ($filtrando)
                <h3>Nada por aqui</h3>
                <p>Nenhuma atividade encontrada com esses filtros.</p>
                <a class="btn-ghost" href="{{ route('settings', 'atividade') }}">Limpar filtros</a>
            @else
                <h3>Nenhuma atividade ainda</h3>
                <p>Os próximos lançamentos, cadastros e acessos vão aparecer aqui.</p>
            @endif
        </div>
    @else
        <ul class="atv-lista">
            @foreach ($atividades as $atividade)
                <li class="atv-item">
                    <span class="atv-ico g-{{ $atividade->grupo }}" title="{{ $grupos[$atividade->grupo] ?? '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">{!! $icones[$atividade->grupo] ?? $icones['cadastros'] !!}</svg>
                    </span>
                    <div class="atv-txt">
                        <p class="atv-frase">{{ $atividade->descricao }}</p>

                        @if (! empty($atividade->mudancas))
                            <ul class="atv-mudancas" aria-label="O que mudou">
                                @foreach ($atividade->mudancas as $mudanca)
                                    <li>
                                        <strong>{{ $mudanca['rotulo'] ?? '' }}:</strong>
                                        @if (($mudanca['antes'] ?? null) === null && ($mudanca['depois'] ?? null) === null)
                                            alterado
                                        @elseif (($mudanca['antes'] ?? null) === null)
                                            {{ $mudanca['depois'] }}
                                        @else
                                            <span class="de">{{ $mudanca['antes'] }}</span> → {{ $mudanca['depois'] }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="atv-meta">
                            <span>{{ $atividade->autor_nome }}</span>
                            <time datetime="{{ $atividade->created_at->toIso8601String() }}">{{ $atividade->created_at->format('d/m/Y H:i') }}</time>
                            @if ($atividade->aparelho)
                                <span>{{ BrowserSessions::descrever($atividade->aparelho) }}</span>
                            @endif
                            {{-- O IP só aparece para quem fez a ação (decisão do Victor, out/2026):
                                 o titular vê o aparelho dos dependentes, não o endereço deles. --}}
                            @if ($atividade->ip && $atividade->user_id === auth()->id())
                                <span>IP {{ $atividade->ip }}</span>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        {{ $atividades->onEachSide(1)->links('transactions.pagination') }}
    @endif

    <p class="atv-rodape">
        O registro guarda a data, o aparelho e o IP de cada ação por 6 meses (o IP só aparece para quem
        fez a ação), para a segurança da conta
        e para a família saber quem mexeu em quê. Detalhes na
        <a href="{{ route('privacidade') }}">Política de Privacidade</a>.
    </p>
</div>
