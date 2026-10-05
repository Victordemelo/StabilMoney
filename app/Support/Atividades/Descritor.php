<?php

namespace App\Support\Atividades;

use App\Models\Account;
use App\Models\Atividade;
use App\Models\Category;
use App\Models\CreditSettlement;
use App\Models\EndedRecurrence;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Brl;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Transforma um evento do Eloquent (criado / atualizado / excluído) numa linha legível do
 * registro de atividade: a ação do catálogo (`Atividade::ACOES`), a frase em PT-BR e, na
 * edição, o antes → depois de cada campo que mudou.
 *
 * Devolve `null` quando o evento não merece linha — e isso é decisão de produto, não falha:
 *  - campo técnico mudou sozinho (o `remember_token` a cada login, a auditoria `funding_*`
 *    zerada numa reconciliação, a `position` de uma categoria): só entram os campos de uma
 *    LISTA DE PERMISSÃO por model. Lista de permissão, e não de negação, é o que garante que
 *    segredo nenhum (senha, `remember_token`, `two_factor_*`, tokens, `client_uuid`) vá
 *    parar no JSON de mudanças, nem o que for acrescentado ao model depois;
 *  - uma operação que escreve VÁRIAS linhas para um único gesto vira UMA atividade: a
 *    compra em 12x registra a 1ª parcela (as outras 11 ficam de fora), a transferência
 *    registra a ponta de ENTRADA (que já encontra a saída, gravada antes, e diz "de A para B").
 *
 * As consultas daqui leem só o que a frase precisa (nome do cartão, da meta…), por id —
 * nunca carregam relação no model, para não mudar o que o código em volta enxerga.
 */
final class Descritor
{
    /**
     * @return array{acao: string, oQue: string, dono: int, mudancas?: list<array>, semIdentificacao?: bool}|null
     */
    public static function descrever(Model $m, string $evento): ?array
    {
        return match (true) {
            $m instanceof Transaction => self::transacao($m, $evento),
            $m instanceof Account => self::conta($m, $evento),
            $m instanceof Category => self::categoria($m, $evento),
            $m instanceof Goal => self::meta($m, $evento),
            $m instanceof Investment => self::investimento($m, $evento),
            $m instanceof GoalContribution => self::movimentoDaMeta($m, $evento),
            $m instanceof InvestmentContribution => self::movimentoDoInvestimento($m, $evento),
            $m instanceof FixedBill => self::contaFixa($m, $evento),
            $m instanceof EndedRecurrence => self::recorrenciaEncerrada($m, $evento),
            $m instanceof CreditSettlement => self::quitacaoPeloCredito($m, $evento),
            $m instanceof User => self::pessoa($m, $evento),
            default => null,
        };
    }

    // ------------------------------------------------------------------ dinheiro

    private static function transacao(Transaction $t, string $evento): ?array
    {
        $dono = (int) $t->user_id;
        $valor = Brl::format($t->amount);
        $conta = self::rotuloDaConta($t->account_id);
        $nome = self::q(self::nomeDaTransacao($t));

        if ($evento === 'updated') {
            // Transferência: quem fala pelas duas pontas é a SAÍDA (a edição de uma
            // transferência regrava as duas, e duas linhas iguais seriam ruído).
            if ($t->isTransferencia() && $t->type === 'income') {
                return null;
            }

            $mudancas = self::mudancas($t, [
                'amount' => ['Valor', fn ($v) => Brl::format($v)],
                'description' => ['Descrição', fn ($v) => $v === null || $v === '' ? '(sem descrição)' : (string) $v],
                'date' => ['Data', fn ($v) => self::data($v)],
                'account_id' => ['Conta', fn ($v) => self::rotuloDaConta($v)],
                'category_id' => ['Categoria', fn ($v) => self::nomeDaCategoria($v)],
                'type' => ['Tipo', fn ($v) => $v === 'income' ? 'Receita' : 'Despesa'],
                'made_by_user_id' => ['Quem fez', fn ($v) => self::nomeDaPessoa($v)],
            ]);

            if ($mudancas === []) {
                return null;
            }

            return $t->isTransferencia()
                ? ['acao' => 'transferencia.editada', 'oQue' => "editou a transferência {$nome}", 'dono' => $dono, 'mudancas' => $mudancas]
                : ['acao' => 'transacao.editada', 'oQue' => 'editou '.self::artigoDoTipo($t)." {$nome}", 'dono' => $dono, 'mudancas' => $mudancas];
        }

        if ($evento === 'deleted') {
            if ($t->settles_account_id) {
                return ['acao' => 'fatura.estornada', 'dono' => $dono,
                    'oQue' => "estornou o pagamento de {$valor} da fatura do cartão ".self::q(self::rotuloDaConta($t->settles_account_id))
                        .": o dinheiro voltou para {$conta}"];
            }

            return ['acao' => 'transacao.excluida', 'dono' => $dono,
                'oQue' => 'excluiu '.self::artigoDoTipo($t)." {$nome} de {$valor} de {$conta}"];
        }

        // created
        if ($t->isTransferencia()) {
            // A saída é gravada primeiro e ainda não tem par; a ENTRADA já encontra a saída.
            if ($t->type !== 'income') {
                return null;
            }

            $origem = Transaction::where('user_id', $t->user_id)
                ->where('transfer_group_id', $t->transfer_group_id)
                ->where('type', 'expense')
                ->value('account_id');

            return ['acao' => 'transferencia.feita', 'dono' => $dono,
                'oQue' => "transferiu {$valor} de ".self::rotuloDaConta($origem)." para {$conta}".self::emNomeDe($t)];
        }

        if ($t->settles_account_id) {
            return ['acao' => 'fatura.paga', 'dono' => $dono,
                'oQue' => "pagou {$valor} da fatura do cartão ".self::q(self::rotuloDaConta($t->settles_account_id))
                    .", saindo de {$conta}".self::fonte($t)];
        }

        if ($t->fixed_bill_id) {
            return ['acao' => 'conta_fixa.paga', 'dono' => $dono,
                'oQue' => "pagou a conta fixa {$nome}: {$valor} em {$conta}".self::fonte($t)];
        }

        if ((int) $t->installments > 1) {
            // Uma compra, uma linha: só a 1ª parcela fala, pela compra inteira.
            if ((int) $t->installment_no !== 1) {
                return null;
            }

            return ['acao' => 'compra.parcelada', 'dono' => $dono,
                'oQue' => "lançou a compra {$nome} em {$t->installments}x de {$valor} em {$conta}".self::emNomeDe($t)];
        }

        if ($t->group_id && $t->recurring) {
            $jaHavia = Transaction::where('user_id', $t->user_id)
                ->where('group_id', $t->group_id)
                ->whereKeyNot($t->getKey())
                ->exists();

            return $jaHavia
                ? ['acao' => 'recorrencia.proxima', 'dono' => $dono,
                    'oQue' => "lançou a próxima cobrança da recorrência {$nome}: {$valor} em {$conta}"]
                : ['acao' => 'recorrencia.criada', 'dono' => $dono,
                    'oQue' => "lançou a despesa recorrente {$nome} de {$valor} em {$conta}".self::emNomeDe($t)];
        }

        $quando = self::data($t->getAttributes()['date'] ?? null);
        $hoje = CarbonImmutable::today()->format('d/m/Y');

        return ['acao' => 'transacao.criada', 'dono' => $dono,
            'oQue' => 'lançou '.self::artigoDoTipo($t)." {$nome} de {$valor} em {$conta}"
                .($quando !== '—' && $quando !== $hoje ? " (com data de {$quando})" : '')
                .self::emNomeDe($t)
                .self::fonte($t)];
    }

    /** "a despesa", "a receita" ou — receita lançada no cartão — "o estorno". */
    private static function artigoDoTipo(Transaction $t): string
    {
        if ($t->type === 'income') {
            return Account::whereKey($t->account_id)->value('type') === 'credit_card' ? 'o estorno' : 'a receita';
        }

        return 'a despesa';
    }

    public static function nomeDaTransacao(Transaction $t): string
    {
        $descricao = trim((string) $t->description);

        if ($descricao !== '') {
            return $descricao;
        }

        return self::nomeDaCategoria($t->category_id, vazio: 'sem descrição');
    }

    /** ", em nome de Maria" — só quando quem lançou não é quem fez a compra. */
    private static function emNomeDe(Transaction $t): string
    {
        $autor = Atividade::autorDaRequisicao()[0];

        if ($t->made_by_user_id === null || (int) $t->made_by_user_id === (int) $autor) {
            return '';
        }

        return ', em nome de '.self::nomeDaPessoa($t->made_by_user_id);
    }

    /** O cheque especial é dinheiro emprestado com juros: a frase diz quanto veio dele. */
    private static function fonte(Transaction $t): string
    {
        if ($t->funding_source === FundingSource::CHEQUE_ESPECIAL && (float) $t->funding_amount > 0) {
            return ' (usando '.Brl::format($t->funding_amount).' do cheque especial)';
        }

        return '';
    }

    private static function movimentoDaMeta(GoalContribution $c, string $evento): ?array
    {
        if ($evento !== 'created') {
            return null;
        }

        // A conta é lida ANTES do pai, na mesma ordem das travas da gravação (conta → pai):
        // o AuditoriaMetasInvestimentosTest confere essa ordem pela sequência de leituras.
        $conta = self::rotuloDaConta($c->account_id);
        $meta = Goal::whereKey($c->goal_id)->first(['id', 'user_id', 'name']);
        if (! $meta) {
            return null;
        }

        $valor = Brl::format($c->amount);
        $nome = self::q($meta->name);

        return $c->type === 'resgate'
            ? ['acao' => 'meta.resgate', 'dono' => (int) $meta->user_id, 'oQue' => "resgatou {$valor} da meta {$nome} para {$conta}"]
            : ['acao' => 'meta.aporte', 'dono' => (int) $meta->user_id, 'oQue' => "guardou {$valor} na meta {$nome}, saindo de {$conta}"];
    }

    private static function movimentoDoInvestimento(InvestmentContribution $c, string $evento): ?array
    {
        if ($evento !== 'created') {
            return null;
        }

        $conta = self::rotuloDaConta($c->account_id); // antes do pai: ver movimentoDaMeta
        $investimento = Investment::whereKey($c->investment_id)->first(['id', 'user_id', 'name']);
        if (! $investimento) {
            return null;
        }

        $valor = Brl::format($c->amount);
        $nome = self::q($investimento->name);

        if ($c->type === 'resgate') {
            return ['acao' => 'investimento.resgate', 'dono' => (int) $investimento->user_id,
                'oQue' => $c->transaction_id
                    ? "resgatou {$valor} de {$nome} para cobrir uma despesa em {$conta}"
                    : "resgatou {$valor} de {$nome} para {$conta}"];
        }

        return ['acao' => 'investimento.aporte', 'dono' => (int) $investimento->user_id,
            'oQue' => "aplicou {$valor} em {$nome}, saindo de {$conta}"];
    }

    private static function recorrenciaEncerrada(EndedRecurrence $r, string $evento): ?array
    {
        if ($evento !== 'created') {
            return null;
        }

        $nome = Transaction::where('user_id', $r->user_id)
            ->where('group_id', $r->group_id)
            ->orderBy('id')
            ->value('description');

        return ['acao' => 'recorrencia.encerrada', 'dono' => (int) $r->user_id,
            'oQue' => 'encerrou a recorrência '.self::q($nome ?: 'sem descrição').': nenhuma cobrança nova será lançada'];
    }

    private static function quitacaoPeloCredito(CreditSettlement $q, string $evento): ?array
    {
        if ($evento !== 'created') {
            return null;
        }

        return ['acao' => 'fatura.quitada_pelo_credito', 'dono' => (int) $q->user_id,
            'oQue' => 'quitou a fatura do cartão '.self::q(self::rotuloDaConta($q->account_id))
                .' com o crédito de um estorno (nada saiu da conta)'];
    }

    // ------------------------------------------------------------------ cadastros

    private static function conta(Account $a, string $evento): ?array
    {
        $dono = (int) $a->user_id;
        $nome = self::q($a->rotulo);

        if ($evento === 'created') {
            $saldo = $a->isCash() && (float) $a->initial_balance > 0
                ? ', com saldo inicial de '.Brl::format($a->initial_balance)
                : '';

            return ['acao' => 'conta.criada', 'dono' => $dono, 'oQue' => "cadastrou o método de pagamento {$nome}{$saldo}"];
        }

        if ($evento === 'deleted') {
            return ['acao' => 'conta.excluida', 'dono' => $dono, 'oQue' => "excluiu o método de pagamento {$nome}"];
        }

        $mudancas = self::mudancas($a, [
            'name' => ['Nome', fn ($v) => (string) $v],
            'type' => ['Tipo', fn ($v) => Account::TYPES[$v] ?? (string) $v],
            'bank' => ['Banco', fn ($v) => Account::BANKS[$v] ?? '—'],
            'initial_balance' => ['Saldo inicial', fn ($v) => $v === null ? '—' : Brl::format($v)],
            'overdraft_limit' => ['Cheque especial', fn ($v) => Brl::format($v ?? 0)],
            'credit_limit' => ['Limite do cartão', fn ($v) => $v === null ? '—' : Brl::format($v)],
            'closing_day' => ['Dia do fechamento', fn ($v) => $v === null ? '—' : 'dia '.$v],
            'due_day' => ['Dia do vencimento', fn ($v) => $v === null ? '—' : 'dia '.$v],
            'checking_account_id' => ['Conta corrente vinculada', fn ($v) => self::rotuloDaConta($v)],
            'savings_account_id' => ['Poupança vinculada', fn ($v) => self::rotuloDaConta($v)],
        ]);

        return $mudancas === [] ? null
            : ['acao' => 'conta.editada', 'dono' => $dono, 'oQue' => "editou o método de pagamento {$nome}", 'mudancas' => $mudancas];
    }

    private static function categoria(Category $c, string $evento): ?array
    {
        $dono = (int) $c->user_id;
        $nome = self::q(trim($c->icon.' '.$c->name));
        $tipo = fn ($v) => $v === 'income' ? 'Receitas' : 'Despesas';

        if ($evento === 'created') {
            return ['acao' => 'categoria.criada', 'dono' => $dono, 'oQue' => "criou a categoria {$nome} em ".$tipo($c->type)];
        }

        if ($evento === 'deleted') {
            return ['acao' => 'categoria.excluida', 'dono' => $dono, 'oQue' => "excluiu a categoria {$nome}"];
        }

        $mudancas = self::mudancas($c, [
            'name' => ['Nome', fn ($v) => (string) $v],
            'type' => ['Tipo', $tipo],
            'icon' => ['Ícone', fn ($v) => (string) $v],
            'color' => ['Cor', fn ($v) => (string) $v],
        ]);

        if ($mudancas === []) {
            return null;
        }

        $soOTipo = count($mudancas) === 1 && $mudancas[0]['campo'] === 'type';

        return ['acao' => 'categoria.editada', 'dono' => $dono, 'mudancas' => $mudancas,
            'oQue' => $soOTipo ? "moveu a categoria {$nome} para ".$tipo($c->type) : "editou a categoria {$nome}"];
    }

    private static function meta(Goal $g, string $evento): ?array
    {
        $dono = (int) $g->user_id;
        $nome = self::q(trim($g->emoji.' '.$g->name));

        if ($evento === 'created') {
            $prazo = $g->target_date ? ' até '.$g->target_date->format('d/m/Y') : '';

            return ['acao' => 'meta.criada', 'dono' => $dono,
                'oQue' => "criou a meta {$nome} de ".Brl::format($g->target_amount).$prazo];
        }

        if ($evento === 'deleted') {
            return ['acao' => 'meta.excluida', 'dono' => $dono, 'oQue' => "excluiu a meta {$nome}"];
        }

        $mudancas = self::mudancas($g, [
            'name' => ['Nome', fn ($v) => (string) $v],
            'emoji' => ['Emoji', fn ($v) => (string) $v],
            'color' => ['Cor', fn ($v) => (string) $v],
            'target_amount' => ['Valor alvo', fn ($v) => Brl::format($v)],
            'target_date' => ['Prazo', fn ($v) => self::data($v)],
        ]);

        return $mudancas === [] ? null
            : ['acao' => 'meta.editada', 'dono' => $dono, 'oQue' => "editou a meta {$nome}", 'mudancas' => $mudancas];
    }

    private static function investimento(Investment $i, string $evento): ?array
    {
        $dono = (int) $i->user_id;
        $nome = self::q($i->name);

        if ($evento === 'created') {
            return ['acao' => 'investimento.criado', 'dono' => $dono,
                'oQue' => "cadastrou o investimento {$nome} (".(Investment::CLASSES[$i->classe] ?? $i->classe).')'];
        }

        if ($evento === 'deleted') {
            return ['acao' => 'investimento.excluido', 'dono' => $dono, 'oQue' => "excluiu o investimento {$nome}"];
        }

        $mudancas = self::mudancas($i, [
            'name' => ['Nome', fn ($v) => (string) $v],
            'classe' => ['Classe', fn ($v) => Investment::CLASSES[$v] ?? (string) $v],
            'indexador' => ['Indexador', fn ($v) => $v === null || $v === '' ? '—' : (string) $v],
            'taxa' => ['Taxa', fn ($v) => $v === null ? '—' : Brl::number($v).'%'],
        ]);

        return $mudancas === [] ? null
            : ['acao' => 'investimento.editado', 'dono' => $dono, 'oQue' => "editou o investimento {$nome}", 'mudancas' => $mudancas];
    }

    private static function contaFixa(FixedBill $f, string $evento): ?array
    {
        $dono = (int) $f->user_id;
        $nome = self::q($f->name);

        if ($evento === 'created') {
            return ['acao' => 'conta_fixa.criada', 'dono' => $dono,
                'oQue' => "cadastrou a conta fixa {$nome} de ".Brl::format($f->amount).", com vencimento todo dia {$f->due_day}"];
        }

        if ($evento === 'deleted') {
            return ['acao' => 'conta_fixa.excluida', 'dono' => $dono, 'oQue' => "excluiu a conta fixa {$nome}"];
        }

        $mudancas = self::mudancas($f, [
            'name' => ['Nome', fn ($v) => (string) $v],
            'amount' => ['Valor previsto', fn ($v) => Brl::format($v)],
            'due_day' => ['Vencimento', fn ($v) => 'dia '.$v],
            'account_id' => ['Pagar com', fn ($v) => self::rotuloDaConta($v)],
            'category_id' => ['Categoria', fn ($v) => self::nomeDaCategoria($v)],
            'starts_on' => ['Começa em', fn ($v) => self::data($v)],
            'ends_on' => ['Termina em', fn ($v) => self::data($v, vazio: 'sem fim')],
            'active' => ['Situação', fn ($v) => (bool) $v ? 'Ativa' : 'Desativada'],
        ]);

        if ($mudancas === []) {
            return null;
        }

        $soDesativou = count($mudancas) === 1 && $mudancas[0]['campo'] === 'active' && ! $f->active;

        return ['acao' => 'conta_fixa.editada', 'dono' => $dono, 'mudancas' => $mudancas,
            'oQue' => $soDesativou ? "desativou a conta fixa {$nome}" : "editou a conta fixa {$nome}"];
    }

    // ------------------------------------------------------------------ família

    private static function pessoa(User $u, string $evento): ?array
    {
        // O titular: a criação é registrada pelo cadastro (com o autor certo — ainda não há
        // sessão), e a exclusão apaga o registro da família inteira (ver User::booted).
        if ($u->account_owner_id === null && $evento !== 'updated') {
            return null;
        }

        $dono = (int) $u->ownerId();
        $nome = $u->name;
        $autor = Atividade::autorDaRequisicao()[0];
        $euMesmo = $autor !== null && (int) $autor === (int) $u->getKey();

        if ($evento === 'created') {
            return ['acao' => 'dependente.adicionado', 'dono' => $dono,
                'oQue' => "adicionou {$nome} à família como ".mb_strtolower($u->relationshipLabel() ?? 'dependente')];
        }

        if ($evento === 'deleted') {
            return $euMesmo
                ? ['acao' => 'dependente.saiu', 'dono' => $dono, 'semIdentificacao' => true,
                    'oQue' => 'excluiu o próprio login (o dinheiro da família continua com o titular)']
                : ['acao' => 'dependente.removido', 'dono' => $dono, 'oQue' => "removeu {$nome} da família"];
        }

        // Nascimento, sexo e telefone: registra QUE mudou, nunca o valor — a linha fica
        // visível ao titular por 6 meses, e esses dados ele não precisa ler para auditar.
        $oculto = fn () => null;
        $mudancas = self::mudancas($u, [
            'name' => ['Nome', fn ($v) => (string) $v],
            'email' => ['E-mail', fn ($v) => (string) $v],
            'phone' => ['Telefone', $oculto],
            'birth_date' => ['Data de nascimento', $oculto],
            'gender' => ['Sexo', $oculto],
            'relationship' => ['Parentesco', fn ($v) => User::RELATIONSHIPS[$v] ?? '—'],
            'avatar_path' => ['Foto', $oculto],
        ]);

        if ($mudancas === []) {
            return null;
        }

        // A foto não tem "antes/depois" legível: diz só o que aconteceu com ela.
        foreach ($mudancas as &$mudanca) {
            if ($mudanca['campo'] === 'avatar_path') {
                $mudanca['depois'] = match (true) {
                    $u->getRawOriginal('avatar_path') === null => 'adicionada',
                    $u->avatar_path === null => 'removida',
                    default => 'trocada',
                };
            }
        }
        unset($mudanca);

        $trocouEmail = collect($mudancas)->contains('campo', 'email');

        if ($trocouEmail) {
            return ['acao' => 'email.trocado', 'dono' => $dono, 'mudancas' => $mudancas,
                'oQue' => $euMesmo ? 'trocou o e-mail de login' : "trocou o e-mail de login de {$nome}"];
        }

        return $euMesmo
            ? ['acao' => 'perfil.editado', 'dono' => $dono, 'mudancas' => $mudancas, 'oQue' => 'atualizou o próprio perfil']
            : ['acao' => 'dependente.editado', 'dono' => $dono, 'mudancas' => $mudancas, 'oQue' => "alterou o cadastro de {$nome}"];
    }

    // ------------------------------------------------------------------ apoio

    /**
     * Antes → depois dos campos PERMITIDOS que mudaram neste save. Formatador que devolve
     * `null` = campo sensível: registra que mudou, sem o valor.
     *
     * Lê o original do jeito que está no banco (`getRawOriginal`): no evento `updated` ele
     * ainda é o de ANTES do save — o `syncOriginal` só roda depois.
     *
     * @param  array<string, array{0: string, 1: callable}>  $campos
     * @return list<array{campo: string, rotulo: string, antes: ?string, depois: ?string}>
     */
    private static function mudancas(Model $m, array $campos): array
    {
        $mudancas = [];

        foreach ($campos as $campo => [$rotulo, $formatar]) {
            if (! $m->wasChanged($campo)) {
                continue;
            }

            $antes = $formatar($m->getRawOriginal($campo));
            $depois = $formatar($m->getAttributes()[$campo] ?? null);

            // Mudança só de forma (0 → "0.00", "2026-10-04" → "2026-10-04 00:00:00").
            if ($antes !== null && $antes === $depois) {
                continue;
            }

            $mudancas[] = ['campo' => $campo, 'rotulo' => $rotulo, 'antes' => $antes, 'depois' => $depois];
        }

        return $mudancas;
    }

    public static function q(?string $texto): string
    {
        return '“'.trim((string) $texto).'”';
    }

    private static function data(mixed $valor, string $vazio = '—'): string
    {
        if ($valor === null || $valor === '') {
            return $vazio;
        }

        try {
            return CarbonImmutable::parse($valor)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $valor;
        }
    }

    public static function rotuloDaConta(mixed $id): string
    {
        if (! $id) {
            return '—';
        }

        $conta = Account::query()->whereKey($id)->first(['id', 'name', 'type', 'bank']);

        return $conta?->rotulo ?? 'conta removida';
    }

    private static function nomeDaCategoria(mixed $id, string $vazio = 'Sem categoria'): string
    {
        if (! $id) {
            return $vazio;
        }

        $categoria = Category::query()->whereKey($id)->first(['id', 'name', 'icon']);

        return $categoria ? trim($categoria->icon.' '.$categoria->name) : $vazio;
    }

    private static function nomeDaPessoa(mixed $id): string
    {
        return $id ? (User::whereKey($id)->value('name') ?? 'pessoa removida') : 'Não informado';
    }
}
