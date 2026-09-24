<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Support\BrowserSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Cria (ou atualiza) o administrador do painel.
 *
 * **Por que só por comando:** não existe — e não pode existir — tela de cadastro de
 * admin. Uma rota pública que cria conta com poder sobre a base inteira é a falha mais
 * cara possível. Para rodar isto é preciso ter shell no servidor, que já é um nível de
 * acesso acima de qualquer coisa que a web exponha.
 *
 * A senha é pedida de forma oculta (`secret`), nunca por argumento: argumento fica no
 * histórico do shell e na lista de processos.
 */
class CriarAdmin extends Command
{
    protected $signature = 'admin:criar {--email=} {--nome=}';

    protected $description = 'Cria ou atualiza o administrador do painel (senha pedida de forma oculta)';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('E-mail do administrador');
        $nome = $this->option('nome') ?: $this->ask('Nome');
        $senha = $this->secret('Senha (não aparece na tela)');
        $confirmacao = $this->secret('Repita a senha');

        if ($senha !== $confirmacao) {
            $this->error('As senhas não conferem.');

            return self::FAILURE;
        }

        $validador = Validator::make(
            ['email' => $email, 'nome' => $nome, 'senha' => $senha],
            [
                'email' => ['required', 'email', 'max:255'],
                'nome' => ['required', 'string', 'max:255'],
                // Mesma política do app (mínimo 8 + checagem de vazamento no
                // Have I Been Pwned), que é o `Password::defaults()` do projeto.
                'senha' => ['required', Password::defaults()],
            ],
        );

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $existia = Admin::where('email', $email)->exists();
        $admin = Admin::provisionar($nome, $email, $senha);

        $this->info($existia
            ? "Administrador {$admin->email} atualizado."
            : "Administrador {$admin->email} criado.");

        if ($existia) {
            $this->encerrarAsSessoesNoPainel($admin);
        }

        if (! $admin->temDoisFatores()) {
            $this->newLine();
            $this->warn('O segundo fator ainda NÃO está configurado.');
            $this->line('No primeiro acesso ao painel você cairá na tela de configuração e');
            $this->line('não sai dela sem escanear o QR e confirmar um código.');
        }

        if (! config('admin.enabled')) {
            $this->newLine();
            $this->warn('O painel está DESLIGADO (ADMIN_PANEL_ENABLED ausente ou false).');
            $this->line('Enquanto estiver assim, todas as rotas dele respondem 404.');
        }

        return self::SUCCESS;
    }

    /**
     * Senha nova = as sessões abertas no painel com a senha antiga caem
     * (PainelAdminSenhaTrocadaPeloTerminalEncerraAsSessoesTest).
     *
     * Trocar a senha por aqui é o remédio que o app indica quando ela pode ter vazado (o
     * alerta do painel e a saída do `admin:zerar-2fa`). Sem isto, quem já estava dentro seguia
     * dentro: o guard `admin` não confere a senha a cada requisição. A mesma regra da troca de
     * senha do cliente (PasswordController), que derruba as outras sessões.
     *
     * As sessões são achadas pelo payload, como no `admin:zerar-2fa` — a coluna `user_id` de
     * `sessions` é do guard `web` e derrubaria o cliente do app com o mesmo número de id.
     */
    private function encerrarAsSessoesNoPainel(Admin $admin): void
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            $this->warn("O driver de sessão é \"{$driver}\": as sessões abertas no painel não puderam ser encerradas.");

            return;
        }

        $encerradas = BrowserSessions::purgeForGuard('admin', $admin->getKey());

        $this->line(match ($encerradas) {
            0 => 'Não havia sessão aberta no painel.',
            1 => '1 sessão aberta no painel foi encerrada.',
            default => "{$encerradas} sessões abertas no painel foram encerradas.",
        });
    }
}
