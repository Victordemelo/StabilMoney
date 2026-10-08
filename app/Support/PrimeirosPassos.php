<?php

namespace App\Support;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;

/**
 * "Primeiros passos" (08/10/2026 — pedido do Victor): de 6 titulares, 4 se cadastraram e não
 * criaram nada. O card no topo da Visão geral diz por onde começar, e cada passo se marca
 * SOZINHO, pelo que já existe nos dados da família — não há "marcar como feito".
 *
 * O titular cadastra o dinheiro da família; o DEPENDENTE usa as contas que o titular criou, então
 * o roteiro dele é outro (lançar o próprio gasto, a foto, proteger o login) e nunca mostra o que
 * ele não pode fazer.
 *
 * Os ESSENCIAIS decidem o progresso ("1 de 2"); os outros vêm em "Depois, se quiser".
 */
final class PrimeirosPassos
{
    /**
     * @return array{essenciais: list<array>, opcionais: list<array>, feitos: int, total: int, completo: bool}
     */
    public static function de(User $user): array
    {
        $familia = $user->ownerId();

        if ($user->isTitular()) {
            $temConta = Account::where('user_id', $familia)->whereIn('type', ['checking', 'savings'])->exists();
            $essenciais = [
                self::passo('conta', 'Cadastrar sua conta de banco', 'A conta corrente ou a poupança onde o dinheiro entra.', $temConta, route('accounts.create'), 'Cadastrar'),
                self::passo('lancamento', 'Fazer o primeiro lançamento', 'Uma receita ou uma despesa — o salário, o mercado.', Transaction::where('user_id', $familia)->exists(), route('transactions.create'), 'Lançar', lancar: $temConta),
            ];
            $opcionais = [
                self::passo('cartao', 'Cartão de crédito', 'Para ver a fatura e as parcelas.', Account::where('user_id', $familia)->where('type', 'credit_card')->exists(), route('accounts.create'), 'Cadastrar'),
                self::passo('fixa', 'Conta fixa', 'Aluguel, condomínio, internet: o app avisa antes de vencer.', FixedBill::where('user_id', $familia)->exists(), route('faturas.index'), 'Abrir'),
                self::passo('meta', 'Meta', 'Guardar dinheiro para um objetivo.', Goal::where('user_id', $familia)->exists(), route('metas.index'), 'Criar'),
                self::passo('familia', 'Alguém da família', 'Cada um com o próprio login, nos mesmos números.', $user->dependents()->exists(), route('dependentes'), 'Adicionar'),
            ];
        } else {
            $essenciais = [
                self::passo('lancamento', 'Fazer o seu primeiro lançamento', 'Um gasto seu, nas contas da família.', Transaction::where('user_id', $familia)->where('made_by_user_id', $user->id)->exists(), route('transactions.create'), 'Lançar', lancar: true),
            ];
            $opcionais = [
                self::passo('foto', 'Sua foto', 'Para a família reconhecer os seus lançamentos.', $user->avatar_path !== null, route('profile.edit'), 'Abrir'),
            ];
        }

        $opcionais[] = self::passo('seguranca', 'Verificação em duas etapas', 'Um código do celular, além da senha.', $user->temDoisFatores(), route('settings', '2fa'), 'Ligar');

        $feitos = count(array_filter($essenciais, fn (array $p) => $p['feito']));
        $todos = array_merge($essenciais, $opcionais);

        return [
            'essenciais' => $essenciais,
            'opcionais' => $opcionais,
            'feitos' => $feitos,
            'total' => count($essenciais),
            'completo' => count(array_filter($todos, fn (array $p) => ! $p['feito'])) === 0,
        ];
    }

    /** O card aparece na Visão geral? Não, se a pessoa escondeu ou se já fez tudo. */
    public static function aparece(User $user, ?array $passos = null): bool
    {
        if ($user->primeiros_passos_ocultos_at !== null) {
            return false;
        }

        return ! ($passos ?? self::de($user))['completo'];
    }

    private static function passo(string $chave, string $titulo, string $texto, bool $feito, string $url, string $acao, bool $lancar = false): array
    {
        return compact('chave', 'titulo', 'texto', 'feito', 'url', 'acao', 'lancar');
    }
}
