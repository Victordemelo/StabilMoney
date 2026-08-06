<?php

namespace App\Providers;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\FaturaService;
use App\Services\SidebarService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Datas traduzidas em todo o app (ex.: "terça-feira, 9 de junho"
        // via translatedFormat). O locale vem do .env (APP_LOCALE=pt_BR).
        Carbon::setLocale(config('app.locale'));

        $this->configurarLimitesDeTaxa();
        $this->configurarPoliticaDeSenha();

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
     * rede falhar, a regra passa (fail-open) — por isso ela reforça, não substitui, o
     * mínimo de tamanho. Desligada em teste para a suíte não depender de rede.
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
     */
    protected function configurarLimitesDeTaxa(): void
    {
        // Endpoints autenticados que pedem a senha atual (confirmar senha, trocar senha,
        // excluir conta, encerrar outras sessões). Chave pelo usuário — mais preciso que
        // por IP, que agruparia toda uma casa atrás do mesmo NAT.
        RateLimiter::for('senha', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->id ?: $request->ip()));

        // Rotas públicas de credencial. Cada POST em /register roda um argon2id;
        // sem limite, é o jeito mais barato de derrubar a VPS.
        RateLimiter::for('credencial', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Login: complementa o throttle por e-mail+IP que já existe no LoginRequest.
        // Aquele protege UMA conta; este barra "password spraying" — uma senha comum
        // testada contra milhares de e-mails diferentes, que não repete a chave de lá.
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

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
        RateLimiter::for('dois-fatores', function (Request $request) {
            $conta = $request->user()?->getKey()
                ?? $request->session()->get(TwoFactorChallengeController::CHAVE_ID)
                ?? $request->ip();

            $chave = '2fa|'.$conta.'|'.$request->ip();

            return [
                Limit::perMinute(5)->by($chave),
                Limit::perHour(20)->by($chave),
            ];
        });
    }
}
