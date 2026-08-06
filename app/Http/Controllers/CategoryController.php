<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToAjax;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    use AuthorizesRequests;
    use RespondsToAjax;

    /**
     * Emojis oferecidos no picker. Fonte ÚNICA: o formulário de página cheia
     * (`categories/_form`) e o modal da listagem leem daqui — duplicar as duas
     * listas faria o modal e a página divergirem com o tempo.
     *
     * @var list<string>
     */
    public const ICONES = ['🍽️', '🚗', '🏠', '💊', '🎮', '📚', '🛒', '🧾', '💰', '💼', '📈', '🎁', '📦', '✨'];

    /**
     * Cores do picker. Sem vermelho: o #E5604D (--neg) é reservado para "está
     * devendo" (saldo negativo, a pagar, vencido).
     *
     * @var list<string>
     */
    public const CORES = ['#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#0EA5B5', '#3B82C4', '#6366F1', '#8B5CF6', '#EC4899', '#F0A93B', '#64748B', '#78716C'];

    /** Dados dos pickers, compartilhados pela listagem (modal) e pelo formulário cheio. */
    private function opcoesDoFormulario(): array
    {
        return ['icones' => self::ICONES, 'cores' => self::CORES];
    }

    public function index(Request $request)
    {
        // Fixas primeiro (são as de uso recorrente), depois as demais — cada
        // bloco em ordem alfabética.
        $categories = Category::where('user_id', $request->user()->ownerId())
            ->orderByDesc('is_locked')
            ->orderBy('name')
            ->get();

        return view('categories.index', [
            'incomeCategories' => $categories->where('type', 'income'),
            'expenseCategories' => $categories->where('type', 'expense'),
            ...$this->opcoesDoFormulario(),
        ]);
    }

    public function create()
    {
        return view('categories.create', $this->opcoesDoFormulario());
    }

    public function store(StoreCategoryRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();

        $category = Category::create($data);

        // O modal da listagem envia por fetch (JSON) e só precisa do OK: ele
        // fecha e manda a página recarregar por pjax. Sem isto o AJAX receberia
        // um redirect e baixaria a listagem inteira à toa.
        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true, 'id' => $category->id], 201);
        }

        return redirect()->route('categories.index')
            ->with('status', 'Categoria criada com sucesso.');
    }

    public function edit(Category $category)
    {
        $this->authorize('update', $category);

        return view('categories.edit', [
            'category' => $category,
            ...$this->opcoesDoFormulario(),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $this->authorize('update', $category);

        $data = $request->validated();

        // Categoria fixa pode ser renomeada/repintada, mas NÃO muda de tipo
        // (elas são de despesa por definição). Cobre o drag & drop entre as
        // colunas: como é ValidationException, vira 422 JSON no AJAX e
        // redirect com erro no envio normal do formulário.
        if ($category->isLocked() && $data['type'] !== $category->type) {
            throw ValidationException::withMessages([
                'type' => 'Esta é uma categoria fixa do sistema e não pode mudar de tipo.',
            ]);
        }

        $category->update($data);

        // O drag & drop da página de categorias envia PATCH via fetch (JSON)
        // e só precisa do OK — sem redirect (evita o GET extra da página toda).
        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('categories.index')
            ->with('status', 'Categoria atualizada.');
    }

    public function destroy(Category $category)
    {
        $this->authorize('delete', $category);

        // Categorias fixas (uso recorrente) são a espinha dorsal dos
        // lançamentos — o usuário pode renomeá-las, mas não removê-las.
        if ($category->isLocked()) {
            return back()->withErrors([
                'category' => 'Esta é uma categoria fixa do sistema e não pode ser excluída.',
            ]);
        }

        // A FK de transactions.category_id é nullOnDelete: as transações
        // associadas ficam "Sem categoria" — pode excluir sem perder dados.
        $category->delete();

        return redirect()->route('categories.index')
            ->with('status', 'Categoria removida. As transações dela ficaram sem categoria.');
    }
}
