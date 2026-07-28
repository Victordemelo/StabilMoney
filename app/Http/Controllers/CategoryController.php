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

    public function index(Request $request)
    {
        $categories = Category::where('user_id', $request->user()->ownerId())
            ->orderBy('name')
            ->get();

        return view('categories.index', [
            'incomeCategories' => $categories->where('type', 'income'),
            'expenseCategories' => $categories->where('type', 'expense'),
        ]);
    }

    public function create()
    {
        return view('categories.create');
    }

    public function store(StoreCategoryRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();

        Category::create($data);

        return redirect()->route('categories.index')
            ->with('status', 'Categoria criada com sucesso.');
    }

    public function edit(Category $category)
    {
        $this->authorize('update', $category);

        return view('categories.edit', compact('category'));
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
