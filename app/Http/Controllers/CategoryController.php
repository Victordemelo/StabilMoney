<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    private function userId(): int
    {
        // Enquanto não há login, usa o usuário padrão (id 1). Ver CLAUDE.md.
        return Auth::id() ?? 1;
    }

    public function index()
    {
        $categories = Category::where('user_id', $this->userId())
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('categories.index', compact('categories'));
    }

    public function create()
    {
        return view('categories.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['user_id'] = $this->userId();

        Category::create($data);

        return redirect()->route('categories.index')
            ->with('status', 'Categoria criada com sucesso.');
    }

    public function edit(Category $category)
    {
        $this->authorizeOwner($category);

        return view('categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $this->authorizeOwner($category);

        $category->update($this->validateData($request));

        return redirect()->route('categories.index')
            ->with('status', 'Categoria atualizada.');
    }

    public function destroy(Category $category)
    {
        $this->authorizeOwner($category);

        $category->delete();

        return redirect()->route('categories.index')
            ->with('status', 'Categoria removida.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:30'],
        ]);
    }

    private function authorizeOwner(Category $category): void
    {
        abort_unless($category->user_id === $this->userId(), 403);
    }
}
