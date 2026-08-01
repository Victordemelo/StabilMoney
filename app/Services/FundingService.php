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
     * @param  callable(array): Transaction  $write  recebe os campos de auditoria
     *                                               (funding_source/funding_amount)
     *                                               para gravar junto da despesa.
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
    ): Transaction {
        return DB::transaction(function () use ($account, $amount, $source, $investmentId, $write, $ignore, $madeByUserId, $date, $obrigacao) {
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

            $faltante = $this->guard->faltante($conta, $amount, $ignore);

            if ($source === FundingSource::CHEQUE_ESPECIAL) {
                // Numa obrigação, estourar o limite é permitido (a dívida é
                // real); num gasto novo, não.
                // Com `$ignore` (edição de despesa), o teto precisa refletir o cenário
                // sem a linha antiga — igual ao time-of-check do SpendingGuard.
                if (! $obrigacao && $faltante > $conta->overdraftAvailableWith($ignore) + SpendingGuard::EPSILON) {
                    throw ValidationException::withMessages([
                        'amount' => 'Não dá: faltam ' . Brl::format($faltante)
                            . ' e o cheque especial disponível é ' . Brl::format($conta->overdraftAvailableWith($ignore)) . '.',
                    ]);
                }

                return $write([
                    'funding_source' => FundingSource::CHEQUE_ESPECIAL,
                    'funding_amount' => $faltante,
                ]);
            }

            if ($source === FundingSource::RESGATE_INVESTIMENTO) {
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
     */
    private function comResgate(
        Account $conta,
        float $faltante,
        ?int $investmentId,
        callable $write,
        ?int $madeByUserId,
        ?string $date,
    ): Transaction {
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
            throw ValidationException::withMessages([
                'funding_investment_id' => 'O investimento ' . $investimento->name . ' tem só '
                    . Brl::format($resgatavel) . ' aplicados a partir desta conta — não cobre '
                    . Brl::format($faltante) . '.',
            ]);
        }

        $transacao = $write([
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_amount' => $faltante,
        ]);

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
