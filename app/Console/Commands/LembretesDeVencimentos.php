<?php

namespace App\Console\Commands;

use App\Mail\LembreteDeVencimento;
use App\Models\User;
use App\Services\FaturaService;
use App\Support\Mailer;
use App\Support\Notificador;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Fluent;

/**
 * `php artisan lembretes:vencimentos` — o sino da topbar chegando por e-mail.
 *
 * Fatura de cartão e conta fixa só avisavam no sino; num app PWA, quem fica dias sem
 * abrir a tela não fica sabendo. Este comando roda uma vez por dia (08:00, ver
 * routes/console.php) e manda UM e-mail por titular com o que vence ou venceu.
 *
 * **Só NOTIFICA, nunca cria dado** (regra do CLAUDE.md para tudo que é agendado): a
 * lista vem de `FaturaService::upcomingDue` — a MESMA fonte do sino, então e-mail e
 * tela nunca discordam — e nada é pago nem lançado por aqui.
 *
 * **Quem recebe: só o TITULAR.** O dinheiro é da família e o titular é quem responde
 * por ele; mandar a mesma lista para cada dependente seria três e-mails pela mesma
 * conta de luz. Precisa também de e-mail verificado (senão o endereço pode nem ser da
 * pessoa) e da preferência `reminder_emails` ligada (Configurações › Conta).
 *
 * **Quando avisa (gatilho × conteúdo):**
 *  - Um item DISPARA o e-mail quando vence em 3 dias, amanhã ou hoje — ou quando está
 *    vencido há um múltiplo de 7 dias (7, 14, 21…). Vencida avisa no dia e depois a
 *    cada semana, não todo dia: um e-mail diário sobre a mesma dívida vira spam, e
 *    spam é filtrado justamente no dia em que importa.
 *  - Disparado o e-mail, ele leva o QUADRO INTEIRO — tudo que já venceu e tudo que
 *    vence nos próximos 3 dias — mesmo os itens que sozinhos não disparariam hoje. Uma
 *    lista incompleta ("vence amanhã: luz", sem citar o aluguel atrasado há 2 dias)
 *    passaria a impressão de que o aluguel está em dia.
 *
 * **Idempotência do dia:** `users.reminder_last_sent_on` guarda a data do último
 * envio; quem já recebeu hoje é pulado. O cron reexecutado não manda dois e-mails.
 * A data só é gravada quando o envio DEU CERTO — falha de SMTP deixa a pessoa
 * elegível para a próxima passada.
 *
 * **Falha no envio não aborta:** `Notificador::avisar` engole a exceção e registra no
 * log; o laço segue para o próximo titular. Sem mailer (`Mailer::entrega()` falso) o
 * comando só registra quantos lembretes deixaram de sair e termina com sucesso — não
 * é erro, é o app em fase de testes.
 */
class LembretesDeVencimentos extends Command
{
    protected $signature = 'lembretes:vencimentos
                            {--dry-run : Lista o que enviaria, sem enviar nem gravar nada}
                            {--user= : Só este titular (id)}';

    protected $description = 'Envia aos titulares, por e-mail, as faturas e contas fixas que vencem em breve ou já venceram';

    /** Quantos dias antes do vencimento o item dispara o e-mail (além do próprio dia). */
    public const DIAS_DE_AVISO = [3, 1, 0];

    /** Vencida: avisa no dia e de novo a cada tantos dias de atraso. */
    public const CADENCIA_VENCIDA = 7;

    /** Horizonte do quadro: vence em até tantos dias entra como "próxima". */
    public const HORIZONTE_DIAS = 3;

    public function handle(FaturaService $faturas): int
    {
        $hoje = CarbonImmutable::today();
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('user') && ! User::whereKey($this->option('user'))->whereNull('account_owner_id')->exists()) {
            $this->warn('Nenhum titular com esse id (dependentes não recebem lembrete).');

            return self::SUCCESS;
        }

        $titulares = User::query()
            ->whereNull('account_owner_id')
            ->whereNotNull('email_verified_at')
            ->whereNull('banned_at')
            ->where('reminder_emails', true)
            ->where(fn ($q) => $q->whereNull('reminder_last_sent_on')
                ->orWhere('reminder_last_sent_on', '<', $hoje->toDateString()))
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id');

        // Primeiro monta a lista, depois age: assim `--dry-run` e "sem mailer" mostram
        // o mesmo que um envio de verdade faria.
        $lembretes = [];
        foreach ($titulares->lazyById() as $titular) {
            $selecao = self::selecionar($faturas->upcomingDue($titular->id, self::HORIZONTE_DIAS));
            if ($selecao === null) {
                continue;
            }
            $lembretes[] = [$titular, $selecao['vencidas'], $selecao['proximas']];
        }

        if ($lembretes === []) {
            $this->info('Nenhum lembrete a enviar hoje.');

            return self::SUCCESS;
        }

        $this->table(
            ['Titular', 'E-mail', 'Vencidas', 'Próximas', 'Assunto'],
            array_map(fn ($l) => [
                $l[0]->name,
                $l[0]->email,
                count($l[1]),
                count($l[2]),
                (new LembreteDeVencimento($l[0], $l[1], $l[2]))->assunto(),
            ], $lembretes),
        );

        if ($dryRun) {
            $this->info(sprintf('[dry-run] %d lembrete(s) seriam enviados. Nada foi enviado nem gravado.', count($lembretes)));

            return self::SUCCESS;
        }

        if (! Mailer::entrega()) {
            Log::info(sprintf('Lembretes de vencimento: sem mailer, %d lembrete(s) não enviado(s).', count($lembretes)));
            $this->warn(sprintf('Sem mailer configurado (MAIL_MAILER=%s): %d lembrete(s) não enviado(s).', config('mail.default'), count($lembretes)));

            return self::SUCCESS;
        }

        $enviados = 0;
        $falhas = 0;
        foreach ($lembretes as [$titular, $vencidas, $proximas]) {
            // Exceção do SMTP vira log dentro do Notificador — o laço nunca para aqui.
            if (! Notificador::avisar($titular, new LembreteDeVencimento($titular, $vencidas, $proximas))) {
                $falhas++;

                continue;
            }

            $titular->forceFill(['reminder_last_sent_on' => $hoje->toDateString()])->saveQuietly();
            $enviados++;
        }

        $this->info(sprintf('%d lembrete(s) enviado(s), %d falha(s).', $enviados, $falhas));

        // Falha parcial termina com código de erro para o cron/log acusar — mas só
        // DEPOIS de ter tentado todo mundo.
        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Aplica a regra "gatilho × conteúdo" à lista do sino.
     *
     * @param  Collection<int, Fluent>  $itens  saída de `FaturaService::upcomingDue`
     * @return array{vencidas: list<array>, proximas: list<array>}|null null = nada dispara hoje
     */
    public static function selecionar(Collection $itens): ?array
    {
        $quadro = $itens
            ->filter(fn ($i) => (int) $i['diasRestantes'] <= self::HORIZONTE_DIAS)
            ->map(fn ($i) => [
                'tipo' => $i['tipo'],
                'nome' => $i['nome'],
                'valor' => (float) $i['valor'],
                'due' => $i['due'],
                'diasRestantes' => (int) $i['diasRestantes'],
            ])
            ->values();

        $dispara = $quadro->contains(fn (array $i) => self::dispara($i['diasRestantes']));
        if (! $dispara) {
            return null;
        }

        return [
            'vencidas' => $quadro->filter(fn ($i) => $i['diasRestantes'] < 0)->values()->all(),
            'proximas' => $quadro->filter(fn ($i) => $i['diasRestantes'] >= 0)->values()->all(),
        ];
    }

    /** Este item, sozinho, justifica um e-mail hoje? */
    public static function dispara(int $diasRestantes): bool
    {
        if ($diasRestantes >= 0) {
            return in_array($diasRestantes, self::DIAS_DE_AVISO, true);
        }

        return abs($diasRestantes) % self::CADENCIA_VENCIDA === 0;
    }
}
