<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\AdminPanelService;
use Illuminate\Http\Request;

/**
 * Telas de leitura do painel: visão geral, lista de pessoas e ficha de uma família.
 *
 * Toda a consulta passa pelo `AdminPanelService`, que é onde mora a regra de nunca
 * selecionar coluna de dinheiro. Este controller não monta query própria de propósito.
 */
class PainelController extends Controller
{
    public function __construct(private AdminPanelService $painel) {}

    /** Visão geral: contagens, histórico de cadastros e as últimas ações do painel. */
    public function home()
    {
        $historico = $this->painel->historicoDeCadastros(30);

        return view('admin.home', [
            'resumo' => $this->painel->resumo(),
            'historico' => $historico,
            'picoDoHistorico' => max(1, max(array_column($historico, 'total'))),
            'recentes' => $this->painel->cadastrosRecentes(),
            'acoes' => AdminAuditLog::with('admin')->latest()->limit(10)->get(),
        ]);
    }

    /** Lista de famílias, com busca e filtro. */
    public function pessoas(Request $request)
    {
        $busca = trim((string) $request->query('q', '')) ?: null;
        $filtro = (string) $request->query('filtro', 'todos');

        $familias = $this->painel->familias($busca, $filtro);

        // Último acesso do titular E dos dependentes numa consulta só.
        $ids = $familias->flatMap(fn ($t) => [$t->id, ...$t->dependents->pluck('id')])->all();

        return view('admin.pessoas.index', [
            'familias' => $familias,
            'acessos' => $this->painel->ultimosAcessos($ids),
            'busca' => $busca,
            'filtro' => $filtro,
        ]);
    }

    /** Ficha de uma família: titular, dependentes e o histórico de moderação dela. */
    public function pessoa(int $id)
    {
        $titular = $this->painel->familia($id);
        $ids = [$titular->id, ...$titular->dependents->pluck('id')->all()];

        return view('admin.pessoas.show', [
            'titular' => $titular,
            'acessos' => $this->painel->ultimosAcessos($ids),
            'acoes' => AdminAuditLog::with('admin')
                ->whereIn('target_user_id', $ids)
                ->latest()
                ->get(),
        ]);
    }

    /** Histórico completo de ações do painel. */
    public function historico()
    {
        return view('admin.historico', [
            'acoes' => AdminAuditLog::with('admin')->latest()->paginate(50),
        ]);
    }
}
