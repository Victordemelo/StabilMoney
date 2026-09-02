<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dados do painel administrativo.
 *
 * ## 🚨 A REGRA DESTE ARQUIVO: nenhuma quantia, nunca
 *
 * O painel mostra a ESTRUTURA da conta de alguém (existe, tem tantos dependentes,
 * tantas contas, tantos lançamentos, entrou pela última vez tal dia) e NUNCA um valor
 * em dinheiro: nada de saldo, valor de lançamento, limite, meta ou investimento.
 *
 * Isso é feito no nível da QUERY, não da view: as colunas de valor não chegam a ser
 * selecionadas. Uma parede que existe só no Blade some no dia em que alguém der um
 * `dd($usuario)` ou acrescentar um campo na tela.
 *
 * Ao mexer aqui: `withCount` é permitido (conta linhas), `withSum`/`sum`/`avg` sobre
 * qualquer coluna monetária **não é**. Há teste garantindo isso
 * (`PainelAdminNaoVeValoresTest`).
 */
class AdminPanelService
{
    /** Colunas de `users` que o painel pode ler. Lista fechada, e sem nada financeiro. */
    private const COLUNAS = [
        'id', 'name', 'email', 'phone', 'avatar_path', 'email_verified_at',
        'is_admin', 'account_owner_id', 'relationship',
        'banned_at', 'banned_reason', 'banned_by_admin_id',
        'terms_accepted_at', 'terms_version', 'created_at',
    ];

    /** Contagens exibidas por pessoa — quantidade de registros, jamais soma de valores. */
    private const CONTAGENS = [
        'accounts', 'categories', 'transactions', 'goals', 'investments', 'dependents',
    ];

    /**
     * Lista de TITULARES (uma linha por família), com dependentes e contagens.
     *
     * Dependente não vira linha própria: a unidade de moderação é a família, e é assim
     * que o app trata os dados (tudo pertence ao titular).
     */
    public function familias(?string $busca = null, string $filtro = 'todos'): LengthAwarePaginator
    {
        $query = User::query()
            ->select(self::COLUNAS)
            ->whereNull('account_owner_id')
            ->withCount(self::CONTAGENS)
            ->with(['dependents' => fn ($q) => $q->select(self::COLUNAS)->orderBy('name')]);

        if ($busca) {
            $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $busca).'%';
            $query->where(function ($q) use ($termo) {
                $q->where('name', 'like', $termo)->orWhere('email', 'like', $termo);
            });
        }

        match ($filtro) {
            'banidos' => $query->whereNotNull('banned_at'),
            'ativos' => $query->whereNull('banned_at'),
            'sem_confirmar' => $query->whereNull('email_verified_at'),
            default => null,
        };

        return $query->orderByDesc('created_at')->paginate(20)->withQueryString();
    }

    /** Uma família completa, com as mesmas restrições de coluna. */
    public function familia(int $id): User
    {
        return User::query()
            ->select(self::COLUNAS)
            ->whereNull('account_owner_id')
            ->withCount(self::CONTAGENS)
            // Dependente não tem dados próprios (tudo pertence ao titular); o que dá
            // para medir dele é quantos lançamentos ELE fez — `made_by_user_id`.
            ->with(['dependents' => fn ($q) => $q->select(self::COLUNAS)
                ->withCount('madeTransactions')
                ->orderBy('name')])
            ->findOrFail($id);
    }

    /**
     * Último acesso por usuário, lido da tabela `sessions`.
     *
     * É o sinal de "esta conta é usada" — o que separa cadastro real de conta fantasma,
     * e o único jeito de saber isso sem olhar dinheiro nenhum.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Carbon>
     */
    public function ultimosAcessos(array $userIds): Collection
    {
        if (! $userIds) {
            return collect();
        }

        return DB::table('sessions')
            ->select('user_id', DB::raw('MAX(last_activity) as ultimo'))
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->pluck('ultimo', 'user_id')
            ->map(fn ($ts) => Carbon::createFromTimestamp((int) $ts));
    }

    /**
     * Números do topo do painel. Todos são CONTAGENS de pessoas/registros.
     *
     * @return array<string, int>
     */
    public function resumo(): array
    {
        return [
            'familias' => User::whereNull('account_owner_id')->count(),
            'dependentes' => User::whereNotNull('account_owner_id')->count(),
            'banidos' => User::whereNotNull('banned_at')->count(),
            'semConfirmar' => User::whereNull('email_verified_at')->count(),
            'novos7dias' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'novos30dias' => User::where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * Cadastros por dia nos últimos N dias — a "história" de quem chegou.
     *
     * Os dias sem cadastro entram com zero: um gráfico que pula datas mente sobre o
     * ritmo de crescimento.
     *
     * @return list<array{dia: string, rotulo: string, total: int}>
     */
    public function historicoDeCadastros(int $dias = 30): array
    {
        $inicio = now()->subDays($dias - 1)->startOfDay();

        // `whereDate` está proibido no projeto (anula índice); a comparação é por
        // intervalo, e o agrupamento por dia é feito em PHP para não depender de
        // função de data específica do driver (MySQL × sqlite).
        $porDia = User::where('created_at', '>=', $inicio)
            ->orderBy('created_at')
            ->pluck('created_at')
            ->countBy(fn ($data) => $data->toDateString());

        $serie = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = $inicio->copy()->addDays($i);
            $chave = $dia->toDateString();

            $serie[] = [
                'dia' => $chave,
                'rotulo' => $dia->format('d/m'),
                'total' => (int) ($porDia[$chave] ?? 0),
            ];
        }

        return $serie;
    }

    /** Os cadastros mais recentes, para a lista "quem chegou agora". */
    public function cadastrosRecentes(int $quantos = 8): Collection
    {
        return User::select(self::COLUNAS)
            ->orderByDesc('created_at')
            ->limit($quantos)
            ->get();
    }
}
