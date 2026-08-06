{{-- Modal "De onde sai esse dinheiro?" — passo 2 de um lançamento cujo saldo
     disponível não cobre. O conteúdo é montado pelo JS (sm/funding.js) com o
     payload do 409, e NÃO por View Composer: assim o shell de toda página não
     precisa carregar saldos e investimentos que quase nunca serão usados. --}}
<div class="modal-scrim" id="fundingModal" data-funding-scrim data-close>
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico ico-out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/></svg>
            </span>
            <div>
                <h3>De onde sai esse dinheiro?</h3>
                <p data-funding-resumo>Seu saldo não cobre esta despesa.</p>
            </div>
            <button class="modal-x" type="button" data-funding-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <div class="modal-body">
            {{-- Opções injetadas pelo JS: uma <label class="fonte-opt"> por fonte --}}
            <div class="fonte-lista" data-funding-opcoes></div>

            {{-- Quando nenhuma fonte cobre: explica a saída (lançar um recebimento) --}}
            <div class="flash-error" data-funding-sem-saida role="alert" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <span data-funding-sem-saida-msg></span>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn ghost" type="button" data-funding-close>Cancelar</button>
            <button class="btn primary" type="button" data-funding-confirm>
                <span class="btn-label">Confirmar</span>
                <span class="btn-spin" aria-hidden="true"></span>
            </button>
        </div>
    </div>
</div>

{{-- ===================== FALLBACK SEM JS (session('fonteNecessaria')) =====================
     O mesmo 409, pelo caminho de navegação normal: `RequiresFundingChoice` faz
     `back()->with('fonteNecessaria', ...)` e este bloco renderiza a escolha como
     FORMULÁRIO DE VERDADE, que reenvia a requisição original + `funding_source`.

     Existe porque o app é PWA: se o fetch não rodar (JS desligado, erro de módulo,
     navegador antigo), o usuário ainda tem como pagar. Sem ele, o clique em
     "Confirmar pagamento" voltava para a tela sem mensagem nenhuma — o achado A-2. --}}
{{-- `acao` é o que torna o replay possível; sem ela (payload antigo em sessão
     de uma versão anterior) não há bloco a renderizar. --}}
@if (is_array($smFonte = session('fonteNecessaria')) && ! empty($smFonte['acao']))
    @php
        $smFontes = collect($smFonte['fontes'] ?? [])->filter(fn ($f) => ! empty($f['id']));
        $smAlgumCobre = $smFontes->contains(fn ($f) => ! empty($f['cobre']));
        $smPrimeira = $smFontes->first(fn ($f) => ! empty($f['cobre']))['id'] ?? null;
        $smResgate = $smFontes->firstWhere('id', 'resgate_investimento');
    @endphp
    <div class="modal-scrim open" id="fundingModalSemJs" data-funding-fallback>
        <div class="modal">
            <div class="modal-head">
                <span class="modal-ico ico-out">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/></svg>
                </span>
                <div>
                    <h3>De onde sai esse dinheiro?</h3>
                    <p>
                        A conta {{ $smFonte['conta']['nome'] ?? '' }} tem @brl($smFonte['disponivel'] ?? 0)
                        disponíveis e este pagamento é de @brl($smFonte['valor'] ?? 0).
                        Faltam @brl($smFonte['faltante'] ?? 0).
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ $smFonte['acao'] }}">
                @csrf
                {{-- O replay precisa do MÉTODO original: editar transação é PATCH, e
                     postar POST na rota de update devolveria 405. O formulário HTML só
                     fala GET/POST, então o verbo vai no _method (spoofing do Laravel). --}}
                @if (($smFonte['metodo'] ?? 'POST') !== 'POST')
                    @method($smFonte['metodo'])
                @endif
                {{-- Replay da requisição original: o usuário não redigita nada. --}}
                @foreach ($smFonte['campos'] ?? [] as $smCampo => $smValor)
                    <input type="hidden" name="{{ $smCampo }}" value="{{ $smValor }}">
                @endforeach
                {{-- TETO do resgate: é este número que a tela promete logo abaixo
                     ("Vamos resgatar X"). O servidor recalcula o faltante na hora de
                     gravar, então sem este teto o valor aprovado aqui e o sacado lá
                     podem divergir. --}}
                @if (($smFonte['faltante'] ?? 0) > 0)
                    <input type="hidden" name="funding_max_amount" value="{{ number_format((float) $smFonte['faltante'], 2, '.', '') }}">
                @endif

                <div class="modal-body">
                    <div class="fonte-lista">
                        @foreach ($smFontes as $smOpt)
                            <label class="fonte-opt {{ empty($smOpt['cobre']) ? 'disabled' : '' }}">
                                <input type="radio" name="funding_source" value="{{ $smOpt['id'] }}"
                                       @checked($smOpt['id'] === $smPrimeira) @disabled(empty($smOpt['cobre']))>
                                <div class="fonte-txt">
                                    <strong>{{ $smOpt['rotulo'] }}</strong>
                                    <span>
                                        @if (! empty($smOpt['cobre']))
                                            {{ $smOpt['detalhe'] }}
                                        @else
                                            {{-- `motivo` vem do servidor: no resgate, o teto é o MAIOR
                                                 investimento (o pedido carrega um id só), então o número
                                                 sozinho não explica nada. --}}
                                            {{ $smOpt['motivo'] ?? 'Não cobre: o máximo por aqui é ' . \App\Support\Brl::format($smOpt['teto'] ?? 0) . '.' }}
                                        @endif
                                    </span>

                                    @if ($smOpt['id'] === 'resgate_investimento' && ! empty($smOpt['itens']))
                                        @php
                                            // O 1º que cobre nasce selecionado; sem isso o navegador
                                            // marca o primeiro da lista, mesmo desabilitado.
                                            $smItemViavel = collect($smOpt['itens'])->firstWhere('cobre', true)['id'] ?? null;
                                        @endphp
                                        <select class="input" name="funding_investment_id" @disabled(empty($smOpt['cobre']))>
                                            @foreach ($smOpt['itens'] as $smItem)
                                                <option value="{{ $smItem['id'] }}"
                                                        @selected($smItem['id'] === $smItemViavel)
                                                        @disabled(empty($smItem['cobre']))>
                                                    {{ $smItem['nome'] }} — @brl($smItem['aplicado']) aplicados
                                                    @if (empty($smItem['cobre'])) (não cobre sozinho) @endif
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="field-hint">
                                            @if (! empty($smOpt['cobre']))
                                                Vamos resgatar @brl($smFonte['faltante'] ?? 0) — o resto continua investido.
                                            @else
                                                Para juntar mais de um investimento, resgate na tela de Investimentos
                                                e lance a despesa depois.
                                            @endif
                                        </small>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>

                    @unless ($smAlgumCobre)
                        <div class="flash-error" role="alert">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                            {{-- Quando há investimento, o motivo dele é que aponta a saída
                                 (resgatar mais de um em Investimentos) — repetir só
                                 "nenhuma fonte cobre" deixaria o usuário sem caminho. --}}
                            <span>Nenhuma fonte cobre este pagamento sozinha. {{ $smResgate['motivo'] ?? '' }} Lance um recebimento para completar o valor, ou reduza o gasto.</span>
                        </div>
                    @endunless
                </div>

                <div class="modal-foot">
                    {{-- Cancelar sem JS = recarregar a tela; o flash já foi consumido, o bloco some. --}}
                    <a class="btn ghost" href="{{ url()->current() }}" data-funding-fallback-close>Cancelar</a>
                    <button class="btn primary" type="submit" @disabled(! $smAlgumCobre)>Confirmar</button>
                </div>
            </form>
        </div>
    </div>
@endif
