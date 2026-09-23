<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Support\AdminAudit;
use App\Support\BrowserSessions;
use App\Support\Texto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan admin:zerar-2fa {email}` — a volta de quem perdeu o celular E os códigos de
 * recuperação do painel.
 *
 * No painel o 2FA é obrigatório e não se desliga pela web (Admin\TwoFactorController). Sem
 * o celular e sem os códigos, o admin ficava trancado do lado de fora para sempre — a única
 * saída era abrir o tinker e apagar colunas à mão, sem histórico e sem aviso. Este comando
 * faz a mesma coisa com as travas que o tinker não tem.
 *
 * **Só por shell**, como o `admin:criar`: quem roda isto já tem o servidor na mão, que é
 * um nível de acesso acima de qualquer coisa que a web exponha. Não existe rota.
 *
 * **O que ele faz, numa transação só:**
 *  1. zera o 2FA (segredo, confirmação, códigos de recuperação e último passo) — o próximo
 *     login cai na tela de configurar o app autenticador (`ExigeDoisFatoresDoAdmin`);
 *  2. encerra as sessões daquele admin no painel. Sem isto, zerar entregaria o painel a
 *     quem tivesse uma sessão aberta: ela cairia na configuração, que não pede a senha, e
 *     cadastraria o PRÓPRIO celular. As sessões são achadas pelo payload (ver
 *     `BrowserSessions::purgeForGuard`): a coluna `user_id` não guarda admin, e apagar por
 *     ela derrubaria o cliente do app que tivesse o mesmo número de id;
 *  3. registra no histórico do painel (`ZEROU_2FA`), cujo e-mail sai depois do commit para
 *     `ADMIN_ALERT_EMAIL` ou, sem ele, para o próprio admin.
 *
 * **Recusa, sem mudar nada:** e-mail que não é de admin; confirmação negada; terminal não
 * interativo sem `--force`; e driver de sessão que não seja `database` — o único em que as
 * sessões dá para encerrar. Zerar sem encerrá-las é pior que não zerar.
 *
 * ⚠️ Enquanto o admin não configurar de novo, a senha sozinha leva à configuração (é o
 * estado de um admin recém-criado). Se a senha pode ter vazado, troque-a também — o
 * `admin:criar` com o mesmo e-mail atualiza a conta e pede uma senha nova.
 */
class ZerarDoisFatoresDoAdmin extends Command
{
    protected $signature = 'admin:zerar-2fa
                            {email : E-mail do administrador}
                            {--force : Não pergunta (para rodar sem terminal interativo)}';

    protected $description = 'Zera o 2FA de um administrador do painel (perdeu o celular e os códigos): ele configura de novo no próximo acesso';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $admin = Admin::where('email', $email)->first();

        if ($admin === null) {
            $this->error("Nenhum administrador com o e-mail {$email}. Nada foi alterado.");

            return self::FAILURE;
        }

        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            $this->error("O driver de sessão é \"{$driver}\", e este comando só sabe encerrar as sessões do painel no driver database.");
            $this->line('Zerar o 2FA sem encerrá-las deixaria quem tem uma sessão aberta cadastrar o próprio celular sem digitar a senha.');
            $this->line('Nada foi alterado.');

            return self::FAILURE;
        }

        $this->line("Administrador: {$admin->name} <{$admin->email}>");
        $this->line($admin->temDoisFatores()
            ? '2FA hoje: configurado desde '.$admin->two_factor_confirmed_at->format('d/m/Y H:i').'.'
            : '2FA hoje: ainda não configurado.');
        $this->line('Isto apaga o app autenticador e os códigos de recuperação dele, e encerra as sessões dele no painel.');
        $this->line('No próximo acesso, a senha leva direto à tela de configurar o app autenticador de novo.');

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Sem terminal interativo não há a quem perguntar: rode de novo com --force. Nada foi alterado.');

                return self::FAILURE;
            }

            if (! $this->confirm("Zerar o 2FA de {$admin->email}?")) {
                $this->warn('Nada foi alterado.');

                return self::FAILURE;
            }
        }

        // Os três ou nenhum: um 2FA zerado sem registro no histórico seria justamente o que
        // este comando existe para evitar (a mudança silenciosa que o tinker fazia).
        $encerradas = DB::transaction(function () use ($admin): int {
            $admin->zerarDoisFatores();

            $encerradas = BrowserSessions::purgeForGuard('admin', $admin->getKey());

            AdminAudit::registrar(
                AdminAuditLog::ZEROU_2FA,
                $admin,
                null,
                motivo: 'Pelo terminal do servidor (php artisan admin:zerar-2fa). '
                    .'O app autenticador é configurado de novo no próximo acesso.',
                // O nome encolhe, o e-mail fica inteiro — a mesma regra do descreverAlvo.
                alvoDescricao: Texto::paraColuna($admin->name, depois: ' <'.$admin->email.'>'),
            );

            return $encerradas;
        });

        $this->info("2FA de {$admin->email} zerado. ".match ($encerradas) {
            0 => 'Não havia sessão aberta no painel.',
            1 => '1 sessão do painel foi encerrada.',
            default => "{$encerradas} sessões do painel foram encerradas.",
        });
        $this->line('Se a senha do painel pode ter vazado, troque-a também: php artisan admin:criar --email='.$admin->email);

        if (! config('admin.enabled')) {
            $this->newLine();
            $this->warn('O painel está DESLIGADO (ADMIN_PANEL_ENABLED ausente ou false).');
            $this->line('A configuração do app autenticador acontece no primeiro acesso depois de ligá-lo.');
        }

        return self::SUCCESS;
    }
}
