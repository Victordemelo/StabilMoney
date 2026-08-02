<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayFixedBillRequest;
use App\Http\Requests\StoreFixedBillRequest;
use App\Http\Requests\UpdateFixedBillRequest;
use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Services\FixedBillService;
use App\Services\FundingService;
use App\Support\Brl;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;

/**
 * Contas fixas mensais. A LISTAGEM não tem rota própria: as ocorrências
 * aparecem como um bloco da tela "Pagar despesas" (/faturas), que já é onde se
 * paga tudo. Aqui ficam só as escritas.
 */
class FixedBillController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreFixedBillRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();
        $data['made_by_user_id'] = $request->user()->id;
        $data['active'] = $data['active'] ?? true;

        FixedBill::create($data);

        return redirect()->route('faturas.index')
            ->with('status', 'Conta fixa cadastrada. Ela aparece todo mês até você desativar.');
    }

    public function update(UpdateFixedBillRequest $request, FixedBill $conta)
    {
        $this->authorize('update', $conta);

        $conta->update($request->validated());

        return redirect()->route('faturas.index')->with('status', 'Conta fixa atualizada.');
    }

    public function destroy(FixedBill $conta, FixedBillService $service)
    {
        $this->authorize('delete', $conta);

        // Desativar/excluir tira as competências VENCIDAS EM ABERTO do bloco de
        // /faturas e do sino — a dívida some do radar sem que nada tenha sido
        // pago (M-10). Não bloqueamos (quem cancelou o serviço tem o direito de
        // apagar a conta e talvez nunca vá pagar aquele mês), mas avisamos com
        // números: quantas competências e quanto some.
        $vencidas = $service->overdue($conta->user_id)
            ->filter(fn ($o) => $o['bill']->id === $conta->id);

        $aviso = $vencidas->isEmpty() ? '' : ' Atenção: ' . $vencidas->count()
            . ($vencidas->count() === 1 ? ' competência vencida' : ' competências vencidas')
            . ' em aberto (' . Brl::format((float) $vencidas->sum('valor'))
            . ') deixaram de aparecer aqui — se você ainda deve, lance como despesa avulsa.';

        // Os pagamentos já feitos são transações de verdade e ficam no
        // histórico; só o vínculo se perde (fixed_bill_id vira null não é
        // possível sem FK, então desligamos a conta em vez de apagar quando há
        // pagamento — assim o histórico continua explicável).
        if ($conta->payments()->exists()) {
            $conta->update(['active' => false]);

            return redirect()->route('faturas.index')
                ->with('status', 'Conta fixa desativada (os pagamentos já feitos continuam no histórico).' . $aviso);
        }

        $conta->delete();

        return redirect()->route('faturas.index')->with('status', 'Conta fixa removida.' . $aviso);
    }

    /**
     * Paga UMA competência (mês) da conta fixa.
     *
     * Passa pelo FundingService, então herda tudo: bloqueio por saldo, pergunta
     * de fonte (cheque especial / resgate) e o limite do cartão. A idempotência
     * é o UNIQUE (fixed_bill_id, competence): duplo clique não paga duas vezes.
     */
    public function pay(PayFixedBillRequest $request, FixedBill $conta, string $competencia, FundingService $funding)
    {
        $this->authorize('update', $conta);

        $ownerId = $request->user()->ownerId();
        $data = $request->validated();

        // "2026-07" → 01/07/2026. Competência é sempre o dia 1 do mês.
        // A rota já garante mês 01..12; o `!` reseta hora/minuto para não herdar "agora".
        $competence = CarbonImmutable::createFromFormat('!Y-m-d', $competencia . '-01')->startOfMonth();

        // Conta DESATIVADA não se paga (M-11): o serviço só projeta contas
        // ativas, então o dinheiro saía do caixa e a competência paga não
        // aparecia em tela nenhuma — pagamento invisível é pagamento perdido.
        if (! $conta->active) {
            throw ValidationException::withMessages([
                'amount' => 'Esta conta fixa está desativada. Reative-a (ou lance a despesa avulsa) antes de pagar.',
            ]);
        }

        $hoje = CarbonImmutable::today();
        $vencimento = $conta->dueDateFor($competence);

        // Competência FUTURA só se paga se ela JÁ APARECE na tela — ou seja, se
        // o vencimento cabe na janela de projeção (hoje + DIAS_A_FRENTE). Pagar
        // o aluguel do dia 5 no dia 30 do mês anterior é normal; pagar dezembro
        // em julho não é, e o dinheiro sairia para uma competência que nenhuma
        // tela mostra.
        if ($vencimento->greaterThan($hoje->addDays(FixedBillService::DIAS_A_FRENTE))) {
            throw ValidationException::withMessages([
                'amount' => 'Esta competência ainda está longe — ela fica disponível para pagamento a partir de '
                    . $vencimento->subDays(FixedBillService::DIAS_A_FRENTE)->translatedFormat('d/m/Y') . '.',
            ]);
        }

        // Anterior ao início da conta fixa: aquela competência nunca existiu.
        // Compara o VENCIMENTO com `starts_on` (não o mês), senão a conta
        // cadastrada em 20/07 que vence dia 5 aceitaria o pagamento de uma
        // competência de julho que a tela (corretamente) nem lista — A-10.
        if ($vencimento->lessThan(CarbonImmutable::parse($conta->starts_on)->startOfDay())) {
            throw ValidationException::withMessages([
                'amount' => 'Esta conta fixa começou em '
                    . CarbonImmutable::parse($conta->starts_on)->translatedFormat('d/m/Y')
                    . ' — não há o que pagar antes disso.',
            ]);
        }

        // As outras duas bordas da competência — `ends_on` (conta ENCERRADA) e o
        // piso de FixedBillService::MAX_MESES_ATRAS — são validadas antes daqui,
        // no PayFixedBillRequest::validarJanelaDaCompetencia(). Estão lá porque
        // dependem só da conta fixa e da URL, e é lá que moram as mensagens
        // PT-BR. Se for mexer na regra de "qual competência é pagável", leia os
        // dois lugares.

        $pagoEm = CarbonImmutable::parse($data['paid_on'] ?? now()->toDateString());
        $valor = round((float) $data['amount'], 2);

        $caixa = Account::whereKey($data['account_id'])->firstOrFail();

        try {
            $funding->spend(
                account: $caixa,
                amount: $valor,
                source: $data['funding_source'] ?? null,
                investmentId: isset($data['funding_investment_id']) ? (int) $data['funding_investment_id'] : null,
                write: fn (array $auditoria) => Transaction::create($auditoria + [
                    'user_id' => $ownerId,
                    'made_by_user_id' => $request->user()->id,
                    'account_id' => $caixa->id,
                    'category_id' => $conta->category_id,
                    'type' => 'expense',
                    'amount' => $valor,
                    'date' => $pagoEm->toDateString(),
                    // Em CONTA DE CAIXA o dinheiro sai na hora → a despesa já nasce
                    // quitada. Em CARTÃO DE CRÉDITO não: a competência vira dívida na
                    // fatura e precisa nascer EM ABERTO (paid_at null) para entrar no
                    // `committed` (consumir limite) e no `openInvoiceDue` (ser cobrada
                    // no pagamento da fatura). Marcá-la como paga aqui fazia a dívida
                    // sumir: nenhum caixa era debitado e o limite nunca era consumido.
                    // A competência continua contando como paga — FixedBillService
                    // olha a EXISTÊNCIA da transação (fixed_bill_id + competence).
                    'paid_at' => $caixa->isCash() ? $pagoEm : null,
                    'description' => $conta->name . ' — ' . $competence->translatedFormat('F/Y'),
                    'fixed_bill_id' => $conta->id,
                    'competence' => $competence->toDateString(),
                ]),
                madeByUserId: $request->user()->id,
                date: $pagoEm->toDateString(),
                // Obrigação SÓ depois do vencimento (C-2c). A dívida vencida é
                // real e não se recusa um boleto: a conta fica negativa. Mas a
                // competência que ainda NÃO venceu é um gasto como outro
                // qualquer — se não cabe no disponível, a trava recusa (422) em
                // vez de deixar a conta no vermelho por antecipação.
                obrigacao: $vencimento->lessThanOrEqualTo($hoje),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Violação do UNIQUE = alguém já pagou esta competência (duplo clique /
            // replay). Idempotente: segue como sucesso.
            //
            // A exceção TIPADA é o que torna isto driver-agnóstico. Antes o código
            // procurava o nome do índice na mensagem, que só o MySQL inclui: em sqlite
            // (e nos testes) a exceção era relançada e o segundo clique virava HTTP 500.
            return redirect()->route('faturas.index')
                ->with('status', 'Esta competência já estava paga.');
        }

        // O aviso de saldo negativo só faz sentido para conta de CAIXA. Num cartão
        // de crédito, `available` (saldo − reservado) não significa nada: o que
        // importa é o limite, e a despesa entra na fatura em vez de sair do bolso.
        $fresco = $caixa->fresh();
        $saldo = $fresco->available;
        $aviso = $fresco->isCash() && $saldo < 0
            ? $conta->name . ' pago. Atenção: a conta ' . $caixa->name . ' ficou em ' . Brl::format($saldo) . '.'
            : ($fresco->isCard()
                ? $conta->name . ' lançado na fatura do ' . $caixa->name . '. Entra no pagamento da fatura.'
                : $conta->name . ' pago. A próxima competência já aparece aqui.');

        return redirect()->route('faturas.index')->with('status', $aviso);
    }
}
