<?php

namespace App\Services;

use App\Exceptions\RequiresFundingChoice;
use App\Models\Account;
use App\Models\Investment;
use App\Models\Transaction;
use App\Support\Brl;
use App\Support\FundingSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava uma despesa respeitando o piso do saldo — e, quando o disponível não
 * cobre, a fonte escolhida pelo usuário (cheque especial ou resgate).
 *
 * Por que existe além do SpendingGuard: o Form Request valida no
 * time-of-CHECK, mas entre validar e gravar há uma janela em que outra
 * requisição pode consumir o mesmo saldo. Aqui tudo acontece dentro de UMA
 * DB::transaction com `lockForUpdate`, que é o time-of-USE e a palavra final.
 * É o mesmo padrão já usado em Concerns\HandlesContributions.
 *
 * ORDEM DE LOCK: conta → investimento, SEMPRE. Aportes concorrentes usam a
 * mesma ordem; inverter em um dos caminhos causaria deadlock.
 */
class FundingService
{
    public function __construct(private SpendingGuard $guard)
    {
    }

    /**
     * Executa `$write` (que cria a despesa) com a garantia de que o saldo
     * aguenta. Devolve o que `$write` devolver.
     *
     * `$write` pode devolver **null** quando, já sob o lock, descobre que não há
     * nada a gravar (fatura quitada por outra requisição, ocorrência recorrente
     * que já existe). Sem isso o caminho de corrida estourava TypeError em vez
     * de sair em silêncio.
     *
     * @param  callable(array): ?Transaction  $write  recebe os campos de auditoria
     *                                                (funding_source/funding_amount)
     *                                                para gravar junto da despesa.
     *
     * @throws RequiresFundingChoice  quando falta escolher a fonte
     * @throws ValidationException    quando nenhuma fonte cobre
     */
    public function spend(
        Account $account,
        float $amount,
        ?string $source,
        ?int $investmentId,
        callable $write,
        float $ignore = 0.0,
        ?int $madeByUserId = null,
        ?string $date = null,
        bool $obrigacao = false,
        ?float $maxFonte = null,
    ): ?Transaction {
        return DB::transaction(function () use ($account, $amount, $source, $investmentId, $write, $ignore, $madeByUserId, $date, $obrigacao, $maxFonte) {
            // 1. Relock da conta (o saldo é derivado de SUM sobre transactions,
            //    então travamos a linha da conta como ponto de serialização).
            $conta = Account::whereKey($account->getKey())->lockForUpdate()->first() ?? $account;

            // Cartão de crédito: o teto é o limite de crédito, não o saldo.
            if ($conta->isCard()) {
                $this->assertLimiteCartao($conta, $amount, $ignore);

                return $write([]);
            }

            if (! $conta->isCash()) {
                return $write([]);
            }

            $veredito = $this->guard->check($conta, $amount, $ignore);

            // 2. Cabe no disponível: nada a perguntar, nada a auditar.
            if ($veredito === SpendingGuard::OK) {
                return $write([]);
            }

            // 3. Nenhuma fonte cobre.
            if ($veredito === SpendingGuard::ESTOURA_LIMITE) {
                // OBRIGAÇÃO (fatura de cartão, conta fixa vencida): a dívida já
                // existe no mundo real e precisa ser quitada. Não se recusa um
                // boleto — a conta simplesmente fica negativa, em vermelho.
                if ($obrigacao) {
                    return $write([]);
                }

                // GASTO NOVO: aí sim recusa, com a saída explicada.
                throw ValidationException::withMessages([
                    'amount' => $this->guard->mensagemSemFonte($conta, $amount, $ignore),
                ]);
            }

            // 4. Precisa de fonte e o usuário não escolheu: 409 com as opções.
            // Vale também para obrigação — o app NUNCA usa o cheque especial
            // sozinho; a escolha é sempre do usuário.
            if (! $source || $source === FundingSource::DISPONIVEL) {
                throw new RequiresFundingChoice(
                    $this->guard->opcoesDeFonte($conta, $amount, $ignore)
                );
            }

            // Duas contas diferentes de propósito (ver SpendingGuard):
            // - `faltante`  = o que o RESGATE traz para a conta terminar em zero,
            //                 incluindo o vermelho que ela já tinha;
            // - `doCheque`  = o cheque especial ADICIONAL que a despesa consome —
            //                 o vermelho atual já saiu do `overdraftAvailable`, e
            //                 contá-lo de novo recusaria despesa que cabe.
            $faltante = $this->guard->faltante($conta, $amount, $ignore);
            $doCheque = $this->guard->chequeNecessario($conta, $amount, $ignore);

            if ($source === FundingSource::CHEQUE_ESPECIAL) {
                // Numa obrigação, estourar o limite é permitido (a dívida é
                // real); num gasto novo, não.
                // Com `$ignore` (edição de despesa), o teto precisa refletir o cenário
                // sem a linha antiga — igual ao time-of-check do SpendingGuard.
                if (! $obrigacao && $doCheque > $conta->overdraftAvailableWith($ignore) + SpendingGuard::EPSILON) {
                    throw ValidationException::withMessages([
                        'amount' => 'Não dá: faltam ' . Brl::format($doCheque)
                            . ' e o cheque especial disponível é ' . Brl::format($conta->overdraftAvailableWith($ignore)) . '.',
                    ]);
                }

                return $write([
                    'funding_source' => FundingSource::CHEQUE_ESPECIAL,
                    // Auditoria: quanto de cheque especial ESTA despesa passou a usar.
                    'funding_amount' => $doCheque,
                ]);
            }

            if ($source === FundingSource::RESGATE_INVESTIMENTO) {
                // 🚨 O resgate NUNCA passa do que o usuário aprovou.
                //
                // O modal mostra um número ("Vamos resgatar R$ 100,00") e é ESSE
                // número que a pessoa autoriza — mas ele não viaja no payload: o
                // `faltante` é recalculado aqui, com o disponível do momento da
                // GRAVAÇÃO. Entre aprovar e gravar, o disponível pode ter despencado
                // (outra despesa da família, uma fatura paga, ou — o caso grave — o
                // lançamento ter dormido horas na fila offline). Sem este teto,
                // aprovar R$ 100 sacava R$ 700 do investimento, sem novo aviso.
                //
                // Estourou: não resgata mais que o combinado nem recusa em silêncio —
                // devolve 409 com as opções RECALCULADAS, para a pessoa decidir de
                // novo com o número certo na frente.
                if ($maxFonte !== null && $faltante > $maxFonte + SpendingGuard::EPSILON) {
                    throw new RequiresFundingChoice(
                        $this->guard->opcoesDeFonte($conta, $amount, $ignore)
                    );
                }

                return $this->comResgate($conta, $faltante, $investmentId, $write, $madeByUserId, $date);
            }

            throw ValidationException::withMessages([
                'funding_source' => 'Escolha de onde sai o dinheiro é inválida.',
            ]);
        });
    }

    /**
     * Resgata do investimento o que FALTA (não o total da despesa) e só então
     * grava a despesa — nesta ordem, para o disponível já estar reposto.
     * O piso de R$ 0,00 do investido é garantido pelo recheque sob lock.
     *
     * "O que falta" inclui o vermelho que a conta já tinha (`SpendingGuard::faltante`):
     * é o que faz o disponível terminar em zero, como o modal promete. Com o
     * clamp antigo a conta saía do resgate ainda negativa.
     *
     * O teto do que o usuário aprovou é conferido em `spend()`, ANTES daqui.
     */
    private function comResgate(
        Account $conta,
        float $faltante,
        ?int $investmentId,
        callable $write,
        ?int $madeByUserId,
        ?string $date,
    ): ?Transaction {
        if (! $investmentId) {
            throw ValidationException::withMessages([
                'funding_investment_id' => 'Escolha de qual investimento resgatar.',
            ]);
        }


        // Lock do investimento DEPOIS da conta (ordem fixa: conta → pai).
        $investimento = Investment::whereKey($investmentId)
            ->where('user_id', $conta->user_id)
            ->lockForUpdate()
            ->first();

        if (! $investimento) {
            throw ValidationException::withMessages([
                'funding_investment_id' => 'O investimento escolhido não existe ou não é da sua família.',
            ]);
        }

        // Só o que saiu DESTA conta pode voltar para ela — senão o reservado da
        // conta ficaria negativo e ela ofereceria dinheiro que não tem.
        $resgatavel = $this->guard->resgatavelDe($conta, $investimento);

        if ($faltante > $resgatavel + SpendingGuard::EPSILON) {
            // A mensagem tem de apontar a SAÍDA: só dizer "não cobre" para quem
            // tem dinheiro aplicado de sobra (só que espalhado em vários
            // investimentos) é o beco sem saída que esta rodada veio fechar.
            throw ValidationException::withMessages([
                'funding_investment_id' => 'O investimento ' . $investimento->name . ' tem só '
                    . Brl::format($resgatavel) . ' aplicados a partir desta conta — não cobre '
                    . Brl::format($faltante) . '. O resgate sai de um investimento por vez: '
                    . 'escolha outro, ou resgate mais de um em Investimentos e lance a despesa depois.',
            ]);
        }

        $transacao = $write([
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_amount' => $faltante,
        ]);

        // Nada foi gravado (corrida): não resgata nada — senão o investimento
        // encolheria sem despesa nenhuma do outro lado.
        if (! $transacao) {
            return null;
        }

        $investimento->contributions()->create([
            'account_id' => $conta->id,
            'transaction_id' => $transacao->id,
            'made_by_user_id' => $madeByUserId,
            'type' => 'resgate',
            'amount' => $faltante,
            'date' => $date ?? now()->toDateString(),
        ]);

        return $transacao;
    }

    /**
     * ESTORNO DA FONTE — desfaz o resgate que financiou uma despesa apagada.
     *
     * Quando o usuário escolhe "tirar do investimento", nascem DUAS linhas: a
     * despesa e um `resgate` ligado a ela por `transaction_id`. Apagar só a
     * despesa devolvia o dinheiro ao saldo mas deixava o resgate de pé: o
     * aplicado do investimento encolhia para sempre, sem contrapartida nenhuma.
     * O patrimônio total ficava certo e o investido, errado — e não havia como
     * o usuário reconstituir o valor a não ser aportando de novo à mão.
     *
     * Chamado EXPLICITAMENTE por quem apaga transações, e não por um hook de
     * model: `FaturaController::destroy` apaga as parcelas em massa
     * (`->delete()` no builder), e delete em massa NÃO dispara eventos Eloquent.
     * Um hook daria a falsa sensação de cobertura justamente no caminho que
     * apaga mais linhas de uma vez.
     *
     * Deve rodar dentro da MESMA transação de banco que apaga as despesas —
     * senão uma falha no meio deixa o resgate estornado e a despesa viva.
     *
     * @param  iterable<int>  $transactionIds
     * @return int  quantas movimentações foram desfeitas
     */
    public function estornarFonte(iterable $transactionIds): int
    {
        $ids = collect($transactionIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        // Metas e investimentos: os dois gravam `transaction_id` no resgate.
        // Hoje só o investimento é oferecido como fonte, mas a coluna existe nos
        // dois — deixar a meta de fora criaria o mesmo buraco no dia em que ela
        // virar opção de fonte.
        return \App\Models\InvestmentContribution::whereIn('transaction_id', $ids)->delete()
            + \App\Models\GoalContribution::whereIn('transaction_id', $ids)->delete();
    }

    /** Cartão de crédito: a compra não pode passar do limite disponível. */
    private function assertLimiteCartao(Account $card, float $amount, float $ignore = 0.0): void
    {
        $disponivel = round($card->availableLimit + $ignore, 2);

        if (round($amount, 2) > $disponivel + SpendingGuard::EPSILON) {
            throw ValidationException::withMessages([
                'amount' => $this->guard->mensagemLimiteCartao($card, $amount),
            ]);
        }
    }
}
