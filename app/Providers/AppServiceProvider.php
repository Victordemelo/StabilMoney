<?php

namespace App\Providers;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\FaturaService;
use App\Services\SidebarService;
use App\Support\ChaveDeIp;
use App\Support\EnderecoPublico;
use App\Support\VerificadorDeSenhaVazada;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registrarVerificadorDeSenhaVazada();
    }

    /**
     * Troca o verificador de senha vazada do framework pelo nosso, que registra no log
     * quando a consulta falha em vez de aceitar a senha em silêncio. A política continua
     * de falha ABERTA — ver App\Support\VerificadorDeSenhaVazada.
     *
     * 🚨 `extend()`, e NÃO `singleton()`. O binding original vem do ValidationServiceProvider,
     * que é DIFERIDO: ele só se registra na primeira vez que alguém pede o validador — depois
     * deste `register()` — e aí sobrescreve qualquer `singleton()` feito aqui, sem erro
     * nenhum. Medido em 17/09/2026: com `singleton()` o container seguia entregando o
     * `NotPwnedVerifier`; com `extend()`, o nosso. O extender sobrevive ao registro tardio.
     * O `SenhaVazadaFalhaAbertaComAvisoTest` confere a peça que o container entrega de fato.
     */
    protected function registrarVerificadorDeSenhaVazada(): void
    {
        $this->app->extend(
            UncompromisedVerifier::class,
            fn ($doFramework, $app) => new VerificadorDeSenhaVazada($app->make(HttpFactory::class)),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em produção, todo link gerado sai com a raiz do APP_URL, em https — nunca com o
        // Host da requisição (o link do "esqueci a senha" já foi um alvo disso). Fora de
        // produção os links seguem o endereço aberto (localhost ou o IP no Wi-Fi).
        EnderecoPublico::fixarEmProducao($this->app);

        // Datas traduzidas em todo o app (ex.: "terça-feira, 9 de junho"
        // via translatedFormat). O locale vem do .env (APP_LOCALE=pt_BR).
        Carbon::setLocale(config('app.locale'));

        $this->configurarLimitesDeTaxa();
        $this->configurarPoliticaDeSenha();
        $this->configurarPessoasDaFamiliaNaRota();

        // @brl($valor) — dinheiro no padrão brasileiro, com o sinal ANTES do
        // símbolo ("−R$ 1.234,56"). number_format sozinho produzia "R$ -1.234,56".
        Blade::directive('brl', fn ($expressao) => "<?php echo e(\App\Support\Brl::format($expressao)); ?>");

        // Card "Patrimônio total" da sidebar: dados agregados injetados em
        // toda renderização do partial (todas as páginas autenticadas).
        View::composer('partials.sidebar', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            // Escopo por família: o patrimônio é o do titular (ownerId), visível também aos dependentes.
            $view->with('patrimonio', $user ? app(SidebarService::class)->build($user->ownerId()) : null);
        });

        // Notificações da topbar: contas a vencer nos próximos 7 dias (faturas de
        // cartão em aberto + recorrências não pagas). Escopo por família.
        View::composer('partials.topbar', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $view->with('vencimentos', $user
                ? app(FaturaService::class)->upcomingDue($user->ownerId(), 7)
                : collect());
        });

        // Modal global de "Lançar" (nova transação), presente no shell de todas as
        // páginas autenticadas. Escopo por família (ownerId).
        View::composer('partials.launch-modal', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $ownerId = $user?->ownerId();

            $view->with([
                'lmAccounts' => $ownerId ? Account::paymentOptions($ownerId) : collect(),
                'lmCategories' => $ownerId
                    ? Category::where('user_id', $ownerId)->orderBy('type')->orderBy('name')->get()
                    : collect(),
                'lmFamily' => $ownerId ? User::familyOf($ownerId)->get() : collect(),
            ]);
        });
    }

    /**
     * Pessoas nomeadas na URL do app: `{dependent}` (editar/remover dependente) e `{membro}`
     * (a foto de perfil). Pessoa de OUTRA família responde como id que não existe — a regra
     * e o porquê estão em `User::daFamiliaNaRota()`.
     *
     * Os models do dinheiro não precisam de nada aqui: o escopo deles vem do próprio model
     * (`Concerns\EscopoDaFamiliaNaRota`). Pessoa precisa, porque o `{user}` do painel
     * administrativo não pode ganhar escopo de família.
     *
     * ⚠️ Fica AQUI, e não em routes/web.php: com `route:cache` (deploy) os arquivos de rota
     * nem são lidos, e um `Route::bind` escrito neles sumiria em produção sem erro nenhum —
     * o binding voltaria a ser o implícito, sem família. O provider roda sempre.
     */
    protected function configurarPessoasDaFamiliaNaRota(): void
    {
        Route::bind('dependent', fn (string $valor) => User::daFamiliaNaRota($valor, soDependentes: true));
        Route::bind('membro', fn (string $valor) => User::daFamiliaNaRota($valor));
    }

    /**
     * Política de senha de TODO o app.
     *
     * `Password::defaults()` é usado no registro, na troca de senha, no reset e no
     * cadastro de dependente — mas nunca havia sido configurado, então valia o default
     * do framework: `min(8)` e nada mais. "12345678" era aceito.
     *
     * A escolha aqui segue a orientação atual do NIST (SP 800-63B): comprimento mínimo
     * + conferência contra vazamentos, SEM exigir composição (maiúscula/símbolo). Regra
     * de composição empurra a pessoa para "Senha@123" — que satisfaz todos os requisitos
     * e está em qualquer lista de ataque. `uncompromised()` barra justamente essas.
     *
     * `uncompromised()` consulta a API do Pwned Passwords por k-anonimato: envia só os
     * 5 primeiros caracteres do SHA-1 da senha, nunca a senha nem o hash completo. Se a
     * consulta falhar, a regra passa (fail-open) — por isso ela reforça, não substitui, o
     * mínimo de tamanho — e a falha fica REGISTRADA no log como aviso (ver
     * `registrarVerificadorDeSenhaVazada`). Desligada em teste para a suíte não depender de
     * rede; os testes do verificador a religam com a rede simulada.
     */
    protected function configurarPoliticaDeSenha(): void
    {
        Password::defaults(function () {
            $regra = Password::min(8);

            return $this->app->runningUnitTests() ? $regra : $regra->uncompromised();
        });
    }

    /**
     * Limites de taxa das rotas sensíveis.
     *
     * Motivo: todo endpoint que VALIDA uma senha é um oráculo de força bruta se não
     * tiver limite — quem tem a sessão (celular perdido, PC compartilhado) mas não a
     * senha ganharia tentativas ilimitadas para descobri-la e tomar a conta em
     * definitivo. E cada tentativa custa um argon2id de 64 MiB, então sem limite isso
     * também é amplificação de DoS: o hash caro é a defesa, o limite é o que impede
     * de transformá-la em arma.
     *
     * "Por IP" aqui é sempre `ChaveDeIp::da($request)`, nunca `$request->ip()` cru: em
     * IPv6 a chave é a rede /64, que o visitante não troca a cada requisição — o
     * endereço inteiro ele troca à vontade, e cada endereço novo era um limite novo
     * (TrocarDeEnderecoIpv6NaoRenovaOLimiteTest, 24/09/2026).
     */
    protected function configurarLimitesDeTaxa(): void
    {
        // Endpoints autenticados que pedem a senha atual (confirmar senha, trocar senha,
        // excluir conta, encerrar outras sessões). Chave pelo usuário — mais preciso que
        // por IP, que agruparia toda uma casa atrás do mesmo NAT.
        RateLimiter::for('senha', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->id ?: ChaveDeIp::da($request)));

        // Rotas públicas de credencial. Cada POST em /register roda um argon2id;
        // sem limite, é o jeito mais barato de derrubar a VPS.
        RateLimiter::for('credencial', fn (Request $request) => Limit::perMinute(5)->by(ChaveDeIp::da($request)));

        // Login: complementa o throttle por e-mail+IP que já existe no LoginRequest.
        // Aquele protege UMA conta; este barra "password spraying" — uma senha comum
        // testada contra milhares de e-mails diferentes, que não repete a chave de lá.
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by(ChaveDeIp::da($request)));

        // Verificação em duas etapas: o desafio do login e a confirmação do setup.
        //
        // O código tem 6 dígitos (10^6) e a janela de tolerância aceita 3 deles por vez,
        // então cada palpite acerta com chance ~3 em 1.000.000. Só o limite por MINUTO
        // não bastaria: 5/min sustentados dariam ~2% de chance por dia. Daí o teto por
        // HORA, que derruba isso para ~0,1% ao dia e continua folgado para quem
        // simplesmente errou de digitar — 20 códigos errados em uma hora não é engano,
        // e nenhum número de tentativas conserta um relógio de celular fora de hora.
        //
        // A chave é a CONTA + o IP: no desafio a pessoa ainda não está autenticada, então
        // o alvo vem do login pendente na sessão (ver TwoFactorChallengeController).
        //
        // 🚨 O teto por HORA é da CONTA, venha o código de onde vier (24/09/2026 —
        // CodigoDoDoisFatoresTemTetoPorContaTest e TrocarDeEnderecoIpv6NaoRenovaOLimiteTest).
        // Com conta + IP na chave, quem já tem a senha — justamente quem o segundo fator
        // existe para barrar — ganhava 20 códigos novos por hora a cada endereço: 1.000
        // endereços (um /48 de túnel IPv6 são 65.536 redes /64; uma botnet, milhares de
        // IPv4) davam 20.000 chutes por hora, e o "~0,1% ao dia" acima virava ~76%. O de
        // MINUTO segue por conta + rede (rajada de uma origem). Preço aceito: quem tem a
        // senha consegue gastar a cota e atrasar o login do dono em até uma hora — o dono
        // troca a senha (o que já derruba o login pendente do atacante) e espera; a
        // alternativa era o código ser adivinhado.
        RateLimiter::for('dois-fatores', function (Request $request) {
            $conta = $request->user()?->getKey()
                ?? $request->session()->get(TwoFactorChallengeController::CHAVE_ID);
            $rede = ChaveDeIp::da($request);

            return [
                Limit::perMinute(5)->by('2fa|'.($conta ?? $rede).'|'.$rede),
                $conta !== null
                    ? Limit::perHour(20)->by('2fa-conta|'.$conta)
                    : Limit::perHour(20)->by('2fa-rede|'.$rede),
            ];
        });

        // ── Painel administrativo ───────────────────────────────────────────────
        //
        // Limites bem mais apertados que os do app, e a razão não é simetria: aqui
        // existe UM usuário legítimo (o Victor), que erra a senha uma ou duas vezes por
        // mês. Qualquer volume acima disso é ataque, não uso. Apertar não incomoda
        // ninguém e derruba força bruta automatizada.
        RateLimiter::for('painel-login', fn (Request $request) => [
            Limit::perMinute(3)->by(ChaveDeIp::da($request)),
            Limit::perHour(10)->by(ChaveDeIp::da($request)),
        ]);

        // Segundo fator do painel: o mesmo raciocínio de 10^6 palpites do 2FA do app,
        // com teto ainda menor porque não há base de usuários para acomodar.
        //
        // E o mesmo teto por CONTA do `dois-fatores`: por IP só, quem tem a senha do admin
        // renovava a cota trocando de endereço (CodigoDoDoisFatoresTemTetoPorContaTest). As
        // rotas do código ficam atrás do `AutenticaNoPainel`, então o admin já é conhecido.
        RateLimiter::for('painel-totp', function (Request $request) {
            $limites = [
                Limit::perMinute(3)->by('painel-totp|'.ChaveDeIp::da($request)),
                Limit::perHour(10)->by('painel-totp|'.ChaveDeIp::da($request)),
            ];

            if ($admin = $request->user('admin')) {
                $limites[] = Limit::perHour(10)->by('painel-totp-admin|'.$admin->getKey());
            }

            return $limites;
        });

        // Banir/desbanir/excluir. Um humano faz isso poucas vezes ao dia; um pico é
        // sinal de sessão sequestrada, e o limite transforma "apagou a base inteira"
        // em "apagou dez e parou" — tempo para o alerta por e-mail chegar.
        RateLimiter::for('painel-acao', fn (Request $request) => [
            Limit::perMinute(5)->by($request->user('admin')?->id ?: ChaveDeIp::da($request)),
            Limit::perHour(30)->by($request->user('admin')?->id ?: ChaveDeIp::da($request)),
        ]);
    }
}
