<?php

namespace App\Models;

use App\Support\Texto;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Uma linha do registro de atividade da família: QUEM fez O QUÊ, ONDE e QUANDO.
 *
 * Quem escreve aqui:
 *  - o trait `Concerns\RegistraAtividade`, nos eventos do Eloquent dos models da família
 *    (a frase vem do `App\Support\Atividades\Descritor`);
 *  - chamadas EXPLÍCITAS a `Atividade::registrar()` onde o Eloquent não dispara evento —
 *    delete/update em massa no builder (`->delete()` numa query não passa por model
 *    nenhum) — e onde a ação não é escrita de model: entrar, sair, trocar a senha, ligar o
 *    2FA, encerrar sessões.
 *
 * 🚨 O registro é gravado na MESMA transação da ação, nunca em fila nem em `afterCommit`:
 * se a ação for desfeita (rollback, deadlock repetido pelo FundingService), o registro some
 * junto. Registro de algo que não aconteceu é pior que registro nenhum.
 *
 * O painel administrativo NUNCA lê esta tabela (ver PainelAdminNaoVeValoresTest): as
 * frases trazem valores em dinheiro.
 */
class Atividade extends Model
{
    protected $table = 'atividades';

    public const UPDATED_AT = null;

    /** Os quatro filtros da tela (Configurações › Atividade). */
    public const GRUPOS = [
        'dinheiro' => 'Dinheiro',
        'cadastros' => 'Cadastros',
        'familia' => 'Família',
        'acesso' => 'Acesso e segurança',
    ];

    /**
     * Catálogo FECHADO de ações → grupo. Ação fora daqui é erro de programação (exceção),
     * não registro sem grupo: o filtro da tela depende de toda linha ter um.
     */
    public const ACOES = [
        // Dinheiro
        'transacao.criada' => 'dinheiro',
        'transacao.editada' => 'dinheiro',
        'transacao.excluida' => 'dinheiro',
        'transferencia.feita' => 'dinheiro',
        'transferencia.editada' => 'dinheiro',
        'transferencia.desfeita' => 'dinheiro',
        'compra.parcelada' => 'dinheiro',
        'compra.excluida' => 'dinheiro',
        'recorrencia.criada' => 'dinheiro',
        'recorrencia.proxima' => 'dinheiro',
        'recorrencia.paga' => 'dinheiro',
        'recorrencia.encerrada' => 'dinheiro',
        'recorrencia.excluida' => 'dinheiro',
        'fatura.paga' => 'dinheiro',
        'fatura.estornada' => 'dinheiro',
        'fatura.quitada_pelo_credito' => 'dinheiro',
        'fatura.quitacao_desfeita' => 'dinheiro',
        'conta_fixa.paga' => 'dinheiro',
        'meta.aporte' => 'dinheiro',
        'meta.resgate' => 'dinheiro',
        'investimento.aporte' => 'dinheiro',
        'investimento.resgate' => 'dinheiro',
        'resgate.desfeito' => 'dinheiro',
        // Cadastros
        'conta.criada' => 'cadastros',
        'conta.editada' => 'cadastros',
        'conta.excluida' => 'cadastros',
        'categoria.criada' => 'cadastros',
        'categoria.editada' => 'cadastros',
        'categoria.excluida' => 'cadastros',
        'categoria.reordenada' => 'cadastros',
        'meta.criada' => 'cadastros',
        'meta.editada' => 'cadastros',
        'meta.excluida' => 'cadastros',
        'investimento.criado' => 'cadastros',
        'investimento.editado' => 'cadastros',
        'investimento.excluido' => 'cadastros',
        'conta_fixa.criada' => 'cadastros',
        'conta_fixa.editada' => 'cadastros',
        'conta_fixa.excluida' => 'cadastros',
        // Família
        'dependente.adicionado' => 'familia',
        'dependente.editado' => 'familia',
        'dependente.senha_trocada' => 'familia',
        'dependente.removido' => 'familia',
        'dependente.saiu' => 'familia',
        'perfil.editado' => 'familia',
        'familia.visivel_ligada' => 'familia',
        'familia.visivel_desligada' => 'familia',
        'familia.edicao_ligada' => 'familia',
        'familia.edicao_desligada' => 'familia',
        // Acesso e segurança
        'app.conta_criada' => 'acesso',
        'acesso.entrou' => 'acesso',
        'acesso.entrou_2fa' => 'acesso',
        'acesso.entrou_google' => 'acesso',
        'acesso.lembrado' => 'acesso',
        'acesso.saiu' => 'acesso',
        'acesso.codigo_2fa_errado' => 'acesso',
        'acesso.termos_aceitos' => 'acesso',
        'senha.trocada' => 'acesso',
        'senha.redefinida' => 'acesso',
        'dois_fatores.ligado' => 'acesso',
        'dois_fatores.desligado' => 'acesso',
        'dois_fatores.codigos_trocados' => 'acesso',
        'sessoes.encerradas' => 'acesso',
        'email.troca_pedida' => 'acesso',
        'email.trocado' => 'acesso',
        'lembretes.ligados' => 'acesso',
        'lembretes.desligados' => 'acesso',
    ];

    /** Nome do autor quando não há pessoa do app por trás (console, seeder, agendador). */
    public const AUTOR_SISTEMA = 'Sistema';

    /** Nome do autor quando a ação veio do painel administrativo. */
    public const AUTOR_ADMINISTRACAO = 'A administração do Stabil Money';

    /** Retenção: a Política de Privacidade promete 6 meses. Mudou um, mude o outro. */
    public const DIAS_DE_RETENCAO = 180;

    protected $fillable = [
        'owner_id',
        'user_id',
        'autor_nome',
        'acao',
        'grupo',
        'alvo_tipo',
        'alvo_id',
        'descricao',
        'mudancas',
        'ip',
        'aparelho',
    ];

    protected function casts(): array
    {
        return [
            'mudancas' => 'array',
            // IP é dado pessoal: cifrado em repouso (coluna `text`), como o do aceite dos termos.
            'ip' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    /** Quantas pausas estão abertas (`semRegistrar` aninhado soma). */
    private static int $pausas = 0;

    /**
     * Roda `$acao` SEM registrar atividade — para o seeder de demonstração (que escreve
     * milhares de linhas que ninguém fez) e para escritas que a própria chamada já resume
     * numa linha só (ex.: as categorias padrão de uma conta nova).
     *
     * @template T
     *
     * @param  Closure(): T  $acao
     * @return T
     */
    public static function semRegistrar(Closure $acao): mixed
    {
        self::$pausas++;

        try {
            return $acao();
        } finally {
            self::$pausas--;
        }
    }

    public static function registrando(): bool
    {
        return self::$pausas === 0;
    }

    /**
     * Grava uma linha. `$oQue` é o PREDICADO ("lançou a despesa …"); a frase final leva o
     * nome do autor na frente ("Maria lançou a despesa …").
     *
     * `$autor` explícito serve a quem age SEM sessão aberta — o cadastro, a redefinição
     * de senha pelo link, o código errado do 2FA na segunda etapa do login. Sem ele, o autor
     * é a pessoa logada no app (guard `web`).
     *
     * @param  list<array{campo: string, rotulo: string, antes: ?string, depois: ?string}>  $mudancas
     */
    public static function registrar(
        string $acao,
        string $oQue,
        int $dono,
        ?Model $alvo = null,
        array $mudancas = [],
        ?User $autor = null,
        bool $semIdentificacao = false,
    ): ?self {
        if (! self::registrando()) {
            return null;
        }

        $grupo = self::ACOES[$acao] ?? throw new InvalidArgumentException("Ação de atividade desconhecida: {$acao}");

        [$autorId, $autorNome] = $autor !== null ? [$autor->getKey(), $autor->name] : self::autorDaRequisicao();

        $nome = Texto::paraColuna((string) $autorNome);
        $requisicao = self::requisicaoDeVerdade();

        return self::create([
            'owner_id' => $dono,
            'user_id' => $autorId,
            'autor_nome' => $nome,
            'acao' => $acao,
            'grupo' => $grupo,
            'alvo_tipo' => $alvo ? class_basename($alvo) : null,
            'alvo_id' => $alvo?->getKey(),
            'descricao' => $nome.' '.$oQue,
            'mudancas' => $mudancas === [] ? null : array_values($mudancas),
            // Quem acabou de sair da família (dependente que apaga o próprio login) não deixa
            // IP nem aparelho para trás — ver User::booted.
            'ip' => $semIdentificacao ? null : $requisicao?->ip(),
            'aparelho' => $semIdentificacao ? null : $requisicao?->userAgent(),
        ]);
    }

    /**
     * Quem está agindo agora: a pessoa logada no APP (guard `web`), a administração (rota do
     * painel) ou o "Sistema" (console, seeder, agendador, visitante sem sessão).
     *
     * A rota do painel vem ANTES do guard `web` de propósito: o admin pode estar logado no app
     * no mesmo navegador (a sessão é a mesma), e a exclusão de um dependente pelo painel não
     * pode aparecer na família como feita por esse cliente.
     *
     * @return array{0: ?int, 1: string}
     */
    public static function autorDaRequisicao(): array
    {
        if (str_starts_with((string) self::requisicaoDeVerdade()?->route()?->getName(), 'painel.')) {
            return [null, self::AUTOR_ADMINISTRACAO];
        }

        $usuario = Auth::guard('web')->user();

        return $usuario instanceof User
            ? [$usuario->getKey(), $usuario->name]
            : [null, self::AUTOR_SISTEMA];
    }

    /**
     * A requisição HTTP em andamento — só quando ela foi ROTEADA. Num comando de console ou
     * no seeder também existe um `Request` no container, mas é de mentira (sem IP de verdade
     * nem navegador), e gravar "127.0.0.1" ali seria inventar um aparelho.
     */
    private static function requisicaoDeVerdade(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $requisicao = app('request');

        return $requisicao->route() !== null ? $requisicao : null;
    }

    /**
     * O que `$quem` pode ver: o titular vê a família inteira; o dependente, só o que ele
     * mesmo fez (o resto da família não é dele para vigiar).
     */
    public function scopeVisivelPara(Builder $consulta, User $quem): Builder
    {
        $consulta->where('owner_id', $quem->ownerId());

        return $quem->isTitular() ? $consulta : $consulta->where('user_id', $quem->getKey());
    }
}
