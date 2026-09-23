<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToAjax;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\FixedBill;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // A ordem é a que o usuário escolheu arrastando os chips (`position`).
        // Os dois desempates existem para a lista NUNCA alternar entre um
        // refresh e outro: sem eles, duas categorias empatadas em `position`
        // sairiam na ordem que o banco quisesse. Empate é raro (a migration
        // numerou tudo e cada categoria nova entra no fim), mas acontece se o
        // PATCH de ordenar falhar no meio de um arraste entre colunas — e aí
        // vale a regra antiga: fixas primeiro, depois alfabética.
        $categories = Category::where('user_id', $request->user()->ownerId())
            ->orderBy('position')
            ->orderByDesc('is_locked')
            ->orderBy('name')
            ->get();

        return view('categories.index', [
            'incomeCategories' => $categories->where('type', 'income'),
            'expenseCategories' => $categories->where('type', 'expense'),
            ...$this->opcoesDoFormulario(),
        ]);
    }

    /**
     * Grava a ordem dos chips de UMA coluna (recebe os ids na ordem final).
     *
     * Renumera em 0..n-1 em vez de "empurrar" vizinhos: é uma coluna de ~10
     * itens, e reescrever tudo elimina de vez a chance de empate — que é o que
     * faria a lista alternar de ordem entre um refresh e outro.
     *
     * A validação mora aqui (e não num Form Request) porque o corpo é só uma
     * lista de ids; e é de propósito que NÃO exista uma regra `exists`: id
     * inexistente ou de outra família tem de ser IGNORADO, não virar 422 — o
     * usuário não tem culpa se uma categoria foi excluída em outra aba no meio
     * do arraste.
     */
    public function ordenar(Request $request)
    {
        $dados = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => 'Informe a nova ordem das categorias.',
            'ids.array' => 'A nova ordem precisa ser uma lista de categorias.',
            'ids.*.integer' => 'A lista de categorias veio em formato inválido.',
        ]);

        $ownerId = $request->user()->ownerId();
        $ids = array_map('intval', $dados['ids']);

        // 🔒 O payload é dado do cliente: quem decide o que é da família é o
        // BANCO, não a lista recebida. Sem este filtro, mandar o id do vizinho
        // reordenaria (ou embaralharia) a tela dele — IDOR clássico.
        $daFamilia = Category::where('user_id', $ownerId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $posicao = 0;
        $vistos = [];

        DB::transaction(function () use ($ids, $daFamilia, $ownerId, &$posicao, &$vistos) {
            foreach ($ids as $id) {
                // Alheio, inexistente ou repetido: pula sem numerar.
                if (! in_array($id, $daFamilia, true) || isset($vistos[$id])) {
                    continue;
                }

                $vistos[$id] = true;

                // O `user_id` no WHERE é cinto de segurança: mesmo que a lista
                // acima escapasse, o UPDATE não alcança outra família.
                Category::where('user_id', $ownerId)
                    ->where('id', $id)
                    ->update(['position' => $posicao++]);
            }
        });

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true, 'ordenadas' => $posicao]);
        }

        return redirect()->route('categories.index')
            ->with('status', 'Ordem das categorias atualizada.');
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

        // Depois do `authorize` de propósito: a recusa conta lançamentos e cita conta fixa,
        // e isso não pode responder por categoria de outra família.
        if ($data['type'] !== $category->type) {
            $this->recusarTrocaDeTipoEmUso($category, $data['type']);
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

    /**
     * Recusa trocar o tipo de uma categoria em uso por algo que não casaria com o tipo novo.
     *
     * Lançamento e conta fixa guardam só o `category_id` — o tipo é o da categoria. Trocá-lo
     * por baixo (arrastar entre as colunas, o toggle do modal e a página cheia caem todos
     * aqui) deixava os lançamentos presos a uma categoria do tipo oposto: editar a despesa
     * sem mudar nada passava a dar 422 ("a categoria precisa casar com o tipo"), o donut do
     * dashboard mostrava categoria de receita entre as despesas, e pagar a conta fixa
     * gravava despesa numa categoria de receita.
     *
     * 🚨 Nunca "consertar" trocando o tipo dos lançamentos junto: o `type` é o sinal do
     * dinheiro (receita soma, despesa subtrai), e mudá-lo reescreveria saldos de meses atrás.
     *
     * Confere o INVARIANTE — todo lançamento casa com o tipo da categoria —, e não "tem
     * lançamento": uma categoria que ficou do tipo errado por um arraste de antes desta trava
     * pode ser arrastada de volta, que é justamente o que a conserta.
     *
     * @throws ValidationException no campo `type` (422 no AJAX, erro no formulário)
     */
    private function recusarTrocaDeTipoEmUso(Category $category, string $novoTipo): void
    {
        $vira = $novoTipo === 'income' ? 'receita' : 'despesa';
        // A saída é a mesma nos dois casos: a categoria que a pessoa quer do outro lado
        // nasce nova, e esta segue servindo aos lançamentos que já tem.
        $saida = 'Para lançar '.$vira.'s, crie uma categoria nova em '.($novoTipo === 'income' ? 'Receitas' : 'Despesas').'.';
        $nome = '"'.$category->name.'"';

        $lancamentos = $category->transactions()->where('type', '!=', $novoTipo)->count();

        if ($lancamentos > 0) {
            $deTipo = $novoTipo === 'income' ? 'despesa' : 'receita';
            $quantos = $lancamentos === 1
                ? 'tem 1 lançamento de '.$deTipo.', e ele ficaria'
                : 'tem '.$lancamentos.' lançamentos de '.$deTipo.', e eles ficariam';

            throw ValidationException::withMessages([
                'type' => $nome.' não pode virar '.$vira.': '.$quantos.' numa categoria do tipo errado. '.$saida,
            ]);
        }

        // Conta fixa é sempre despesa (StoreFixedBillRequest só aceita categoria de despesa,
        // e o pagamento grava `type = expense` com a categoria dela). Mesmo sem nenhum
        // pagamento ainda, virar receita quebraria o próximo — e a edição da conta fixa.
        if ($novoTipo === 'expense') {
            return;
        }

        $contasFixas = FixedBill::where('category_id', $category->id)->orderBy('name')->pluck('name');

        if ($contasFixas->isNotEmpty()) {
            $quais = $contasFixas->count() === 1
                ? 'é a categoria da conta fixa "'.$contasFixas->first().'"'
                : 'é a categoria de '.$contasFixas->count().' contas fixas (entre elas, "'.$contasFixas->first().'")';

            throw ValidationException::withMessages([
                'type' => $nome.' não pode virar '.$vira.': '.$quais.', e conta fixa é sempre despesa. '.$saida,
            ]);
        }
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
