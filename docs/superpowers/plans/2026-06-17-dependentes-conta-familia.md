# Dependentes (Conta-família) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que quem se cadastra (titular) crie dependentes com login próprio que compartilham a mesma visão financeira, registrando quem fez cada lançamento.

**Architecture:** `users.account_owner_id` auto-referenciado (null = titular). Todo dado da família continua com `user_id = id do titular`; o escopo das queries troca de `auth id` por `User::ownerId()`. `transactions.made_by_user_id` guarda o autor. Round 1 = acesso total na família (permissões granulares são outro subprojeto).

**Tech Stack:** Laravel 12 (PHP 8.4), MySQL (dev) / sqlite (testes), PHPUnit (Feature tests com `RefreshDatabase` + factories), Blade + design-system CSS.

## Global Constraints

- Testes rodam no container: `docker compose exec app php artisan test`.
- Strings de UI e comentários em **PT-BR**.
- Migrations sempre reversíveis (`down()`), SQL compatível MySQL + sqlite.
- **NUNCA** `Auth::id() ?? 1`; escopo sempre por `->ownerId()`.
- Hash de senha via `Hash::make` (driver argon2id já configurado).
- Commits com prefixo `Feat:`/`Fix:`/`test:`, terminando com `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Rodar `npm run build` quando tocar CSS/Blade que dependa de assets.

## File Structure

- `database/migrations/*_add_account_owner_id_to_users.php` — coluna do titular/dependente (Task 1)
- `database/migrations/*_add_made_by_user_id_to_transactions.php` — autor do lançamento (Task 2)
- `app/Models/User.php` — `ownerId()`, `isTitular()`, `dependents()`, `titular()` (Task 1)
- `app/Models/Transaction.php` — `made_by_user_id` no fillable + `madeBy()` (Task 2)
- `app/Http/Controllers/{Account,Category,Transaction,Dashboard}Controller.php` — escopo família (Task 3, 7)
- `app/Http/Requests/{Store,Update}TransactionRequest.php` — escopo família + autor (Task 4, 7)
- `app/Policies/{Account,Category,Transaction}Policy.php` — escopo família (Task 5)
- `app/Http/Controllers/DependentController.php` — gerenciar dependentes (Task 6)
- `routes/web.php` — rotas de dependentes (Task 6)
- `resources/views/dependents/index.blade.php` — tela Dependentes (Task 8)
- `resources/views/partials/sidebar.blade.php` — card Dependentes (count + esconder) (Task 8)
- `resources/views/transactions/_form.blade.php` — select "quem fez a compra" (Task 7)
- `resources/css/design-system.css` — portar `.dep-*`, `.modal-lg` (Task 8)
- `tests/Feature/FamilyAccountTest.php` — testes da feature (Tasks 1-7)

---

### Task 1: Migration `account_owner_id` + helpers no User

**Files:**
- Create: `database/migrations/2026_06_17_100000_add_account_owner_id_to_users.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/FamilyAccountTest.php`

**Interfaces:**
- Produces: `User::ownerId(): int`, `User::isTitular(): bool`, `User::dependents(): HasMany`, `User::titular(): BelongsTo`, coluna `users.account_owner_id`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_titular_owner_id_is_self(): void
    {
        $titular = User::factory()->create();

        $this->assertSame($titular->id, $titular->ownerId());
        $this->assertTrue($titular->isTitular());
    }

    public function test_dependent_points_to_titular(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->assertSame($titular->id, $dependent->ownerId());
        $this->assertFalse($dependent->isTitular());
        $this->assertTrue($titular->dependents->contains($dependent));
        $this->assertTrue($dependent->titular->is($titular));
    }
}
```

- [ ] **Step 2: Run, expect fail**

Run: `docker compose exec app php artisan test --filter=FamilyAccountTest`
Expected: erro (coluna/método não existem).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('account_owner_id')->nullable()->after('is_admin')
                ->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_owner_id');
        });
    }
};
```

- [ ] **Step 4: User model** — adicionar ao `$fillable` o `'account_owner_id'` e os métodos (importar `BelongsTo`):

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// dentro da classe:
/** Id do dono da família: o próprio id se for titular, ou o do titular se dependente. */
public function ownerId(): int
{
    return $this->account_owner_id ?? $this->id;
}

public function isTitular(): bool
{
    return $this->account_owner_id === null;
}

public function dependents(): HasMany
{
    return $this->hasMany(User::class, 'account_owner_id');
}

public function titular(): BelongsTo
{
    return $this->belongsTo(User::class, 'account_owner_id');
}
```

- [ ] **Step 5: Run, expect pass**

Run: `docker compose exec app php artisan test --filter=FamilyAccountTest`
Expected: PASS (2 testes).

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/User.php tests/Feature/FamilyAccountTest.php
git commit -m "Feat: account_owner_id e helpers de conta-família no User"
```

---

### Task 2: Migration `made_by_user_id` + relação no Transaction

**Files:**
- Create: `database/migrations/2026_06_17_100100_add_made_by_user_id_to_transactions.php`
- Modify: `app/Models/Transaction.php`
- Test: `tests/Feature/FamilyAccountTest.php`

**Interfaces:**
- Produces: coluna `transactions.made_by_user_id`, `Transaction::madeBy(): BelongsTo`.

- [ ] **Step 1: Failing test** (adicionar ao `FamilyAccountTest`)

```php
public function test_transaction_records_author(): void
{
    $titular = \App\Models\User::factory()->create();
    $account = \App\Models\Account::factory()->for($titular)->create();
    $tx = \App\Models\Transaction::factory()->for($titular)->for($account)->expense()
        ->create(['made_by_user_id' => $titular->id]);

    $this->assertTrue($tx->madeBy->is($titular));
}
```

- [ ] **Step 2: Run, expect fail**

Run: `docker compose exec app php artisan test --filter=test_transaction_records_author`
Expected: FAIL (coluna/relação ausente).

- [ ] **Step 3: Migration (com backfill p/ dados existentes)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('made_by_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: lançamentos antigos foram feitos pelo titular dono (user_id).
        DB::table('transactions')->whereNull('made_by_user_id')
            ->update(['made_by_user_id' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('made_by_user_id');
        });
    }
};
```

- [ ] **Step 4: Transaction model** — `'made_by_user_id'` no `$fillable` + relação:

```php
public function madeBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'made_by_user_id');
}
```

- [ ] **Step 5: Run, expect pass** — `docker compose exec app php artisan test --filter=FamilyAccountTest`

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/Transaction.php tests/Feature/FamilyAccountTest.php
git commit -m "Feat: made_by_user_id (autor do lançamento) em transactions"
```

---

### Task 3: Escopo por família nos controllers

**Files:**
- Modify: `app/Http/Controllers/AccountController.php`, `CategoryController.php`, `TransactionController.php`, `DashboardController.php`
- Test: `tests/Feature/FamilyAccountTest.php`

**Interfaces:**
- Consumes: `User::ownerId()` (Task 1).

- [ ] **Step 1: Failing test**

```php
public function test_dependent_sees_titular_data(): void
{
    $titular = \App\Models\User::factory()->create();
    \App\Models\Account::factory()->for($titular)->create(['name' => 'Conta da Familia']);
    \App\Models\Category::factory()->expense()->for($titular)->create(['name' => 'Mercado Familia']);
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    $this->actingAs($dependent)->get('/accounts')->assertOk()->assertSee('Conta da Familia');
    $this->actingAs($dependent)->get('/categories')->assertOk()->assertSee('Mercado Familia');
    $this->actingAs($dependent)->get('/')->assertOk();
}
```

- [ ] **Step 2: Run, expect fail** (dependente não vê os dados do titular ainda).

- [ ] **Step 3: Trocar `->id` por `->ownerId()`**

`AccountController`: linha 27 `Account::where('user_id', $request->user()->ownerId())`; linha 45 `$data['user_id'] = $request->user()->ownerId();`.
`CategoryController`: linha 17 `Category::where('user_id', $request->user()->ownerId())`; linha 35 `$data['user_id'] = $request->user()->ownerId();`.
`TransactionController`: linhas 19, 49, 75 `$userId = $request->user()->ownerId();`; linha 63 `$data['user_id'] = $request->user()->ownerId();`.
`DashboardController` linha 15:

```php
public function index(DashboardService $dashboard)
{
    return view('dashboard', $dashboard->build(auth()->user()->ownerId()));
}
```

- [ ] **Step 4: Run, expect pass** — `--filter=test_dependent_sees_titular_data`. Rodar também o suite todo p/ garantir que os 2-famílias seguem isolados: `docker compose exec app php artisan test`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers
git commit -m "Feat: escopo por família (ownerId) nos controllers"
```

---

### Task 4: Escopo por família nos Form Requests

**Files:**
- Modify: `app/Http/Requests/StoreTransactionRequest.php`, `app/Http/Requests/UpdateTransactionRequest.php`
- Test: `tests/Feature/FamilyAccountTest.php`

**Interfaces:**
- Consumes: `User::ownerId()`.

- [ ] **Step 1: Failing test**

```php
public function test_dependent_can_create_with_family_account(): void
{
    $titular = \App\Models\User::factory()->create();
    $account = \App\Models\Account::factory()->for($titular)->create();
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    $this->actingAs($dependent)->post('/transactions', [
        'type' => 'expense', 'amount' => '30,00',
        'account_id' => $account->id, 'date' => now()->toDateString(),
        'description' => 'Compra do dependente',
    ])->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'description' => 'Compra do dependente',
        'user_id' => $titular->id,
    ]);
}
```

- [ ] **Step 2: Run, expect fail** (validação rejeita a conta "alheia").

- [ ] **Step 3: Trocar o `$userId`** em ambos os requests: onde houver `$userId = $this->user()->id;` usar `$userId = $this->user()->ownerId();` (afeta as `Rule::exists` de `account_id`/`category_id`). Verificar nos dois arquivos.

- [ ] **Step 4: Run, expect pass** — `--filter=test_dependent_can_create_with_family_account`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests
git commit -m "Feat: validação de transação escopada por família"
```

---

### Task 5: Escopo por família nas Policies

**Files:**
- Modify: `app/Policies/AccountPolicy.php`, `CategoryPolicy.php`, `TransactionPolicy.php`
- Test: `tests/Feature/FamilyAccountTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_dependent_can_edit_family_transaction(): void
{
    $titular = \App\Models\User::factory()->create();
    $account = \App\Models\Account::factory()->for($titular)->create();
    $tx = \App\Models\Transaction::factory()->for($titular)->for($account)->expense()->create();
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    $this->actingAs($dependent)->get("/transactions/{$tx->id}/edit")->assertOk();
}
```

- [ ] **Step 2: Run, expect fail** (policy nega: `user_id !== dependent->id`).

- [ ] **Step 3: Nas 3 policies**, trocar `$model->user_id === $user->id` por `$model->user_id === $user->ownerId()` (métodos `update` e `delete` de cada uma). Ex. `TransactionPolicy`:

```php
public function update(User $user, Transaction $transaction): bool
{
    return $transaction->user_id === $user->ownerId();
}

public function delete(User $user, Transaction $transaction): bool
{
    return $transaction->user_id === $user->ownerId();
}
```

- [ ] **Step 4: Run, expect pass** + suite completo (isolamento entre famílias deve seguir verde): `docker compose exec app php artisan test`.

- [ ] **Step 5: Commit**

```bash
git add app/Policies
git commit -m "Feat: policies escopadas por família (ownerId)"
```

---

### Task 6: DependentController + rotas + gating titular-only

**Files:**
- Create: `app/Http/Controllers/DependentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/FamilyAccountTest.php`

**Interfaces:**
- Produces: rotas `dependentes` (GET), `dependentes.store` (POST), `dependentes.destroy` (DELETE).

- [ ] **Step 1: Failing tests**

```php
public function test_titular_adds_dependent_without_seeding_categories(): void
{
    $titular = \App\Models\User::factory()->create();

    $this->actingAs($titular)->post('/dependentes', [
        'name' => 'Joao', 'email' => 'joao@familia.test', 'password' => 'senha-forte-123',
    ])->assertRedirect();

    $dependent = \App\Models\User::where('email', 'joao@familia.test')->first();
    $this->assertNotNull($dependent);
    $this->assertSame($titular->id, $dependent->account_owner_id);
    $this->assertFalse((bool) $dependent->is_admin);
    $this->assertDatabaseMissing('categories', ['user_id' => $dependent->id]);
}

public function test_dependent_cannot_manage_dependents(): void
{
    $titular = \App\Models\User::factory()->create();
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    $this->actingAs($dependent)->get('/dependentes')->assertForbidden();
}

public function test_titular_removes_dependent(): void
{
    $titular = \App\Models\User::factory()->create();
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    $this->actingAs($titular)->delete("/dependentes/{$dependent->id}")->assertRedirect();
    $this->assertDatabaseMissing('users', ['id' => $dependent->id]);
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class DependentController extends Controller
{
    public function index(Request $request)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular(), 403);

        $dependents = $titular->dependents()->orderBy('name')->get();

        return view('dependents.index', compact('dependents'));
    }

    public function store(Request $request)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Rules\Password::defaults()],
        ]);

        // Não dispara Registered: dependente compartilha as categorias da família.
        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => false,
            'account_owner_id' => $titular->id,
        ]);

        return redirect()->route('dependentes')->with('status', 'Dependente adicionado.');
    }

    public function destroy(Request $request, User $dependent)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular() && $dependent->account_owner_id === $titular->id, 403);

        $dependent->delete();

        return redirect()->route('dependentes')->with('status', 'Dependente removido.');
    }
}
```

- [ ] **Step 4: Rotas** — remover `'dependentes'` do `foreach` de coming-soon (linhas 26-37) e adicionar (com `use App\Http\Controllers\DependentController;`):

```php
Route::get('/dependentes', [DependentController::class, 'index'])->name('dependentes');
Route::post('/dependentes', [DependentController::class, 'store'])->name('dependentes.store');
Route::delete('/dependentes/{dependent}', [DependentController::class, 'destroy'])->name('dependentes.destroy');
```

- [ ] **Step 5: Run, expect pass.** Rodar também `ComingSoonTest` (a rota `dependentes` saiu do coming-soon — ajustar/remover o data set `dependentes` desse teste se ele afirmar coming-soon).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DependentController.php routes/web.php tests/Feature
git commit -m "Feat: CRUD de dependentes (titular-only), sem semear categorias"
```

---

### Task 7: "Quem fez a compra" (autor no lançamento)

**Files:**
- Modify: `app/Http/Requests/StoreTransactionRequest.php`, `UpdateTransactionRequest.php`, `app/Http/Controllers/TransactionController.php`, `resources/views/transactions/_form.blade.php`, `resources/views/transactions/index.blade.php`
- Test: `tests/Feature/FamilyAccountTest.php`

**Interfaces:**
- Consumes: `made_by_user_id` (Task 2), `ownerId()` (Task 1).

- [ ] **Step 1: Failing tests**

```php
public function test_author_defaults_to_current_user(): void
{
    $titular = \App\Models\User::factory()->create();
    $account = \App\Models\Account::factory()->for($titular)->create();
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    $this->actingAs($dependent)->post('/transactions', [
        'type' => 'expense', 'amount' => '10,00',
        'account_id' => $account->id, 'date' => now()->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $titular->id, 'made_by_user_id' => $dependent->id,
    ]);
}

public function test_author_must_be_in_family(): void
{
    $titular = \App\Models\User::factory()->create();
    $account = \App\Models\Account::factory()->for($titular)->create();
    $stranger = \App\Models\User::factory()->create();

    $this->actingAs($titular)->post('/transactions', [
        'type' => 'expense', 'amount' => '10,00',
        'account_id' => $account->id, 'date' => now()->toDateString(),
        'made_by_user_id' => $stranger->id,
    ])->assertSessionHasErrors('made_by_user_id');
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Validação** — em `StoreTransactionRequest` e `UpdateTransactionRequest`, dentro de `rules()`, adicionar (usando o `$userId = $this->user()->ownerId()` já presente):

```php
'made_by_user_id' => [
    'nullable',
    Rule::exists('users', 'id')->where(function ($q) use ($userId) {
        $q->where('id', $userId)->orWhere('account_owner_id', $userId);
    }),
],
```

- [ ] **Step 4: Controller** — `TransactionController`:
  - `store()`: após `$data = $request->validated();` e o `$data['user_id'] = ...->ownerId();`, adicionar:
    ```php
    $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;
    ```
  - `update()`: passar a montar o array p/ default do autor:
    ```php
    $data = $request->validated();
    $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;
    $transaction->update($data);
    ```
  - `create()` e `edit()`: passar os membros da família p/ a view:
    ```php
    'familyMembers' => \App\Models\User::where('id', $request->user()->ownerId())
        ->orWhere('account_owner_id', $request->user()->ownerId())
        ->orderBy('name')->get(),
    ```

- [ ] **Step 5: Form** — em `_form.blade.php`, após o campo de Conta/Categoria, renderizar o select só se a família tiver +1 membro:

```blade
@isset($familyMembers)
@if ($familyMembers->count() > 1)
    <div class="field">
        <label for="made_by_user_id">Quem fez a compra</label>
        <select class="input" id="made_by_user_id" name="made_by_user_id">
            @foreach ($familyMembers as $membro)
                <option value="{{ $membro->id }}"
                    @selected((int) old('made_by_user_id', $transaction->made_by_user_id ?? auth()->id()) === $membro->id)>
                    {{ $membro->name }}{{ $membro->isTitular() ? ' (titular)' : '' }}
                </option>
            @endforeach
        </select>
    </div>
@endif
@endisset
```

- [ ] **Step 6: Exibir o autor** em `transactions/index.blade.php` — na linha de cada transação, mostrar o autor quando houver mais de um membro (na `.tx-meta`): acrescentar `· {{ $transaction->madeBy?->name ?? 'Removido' }}` ao bloco de metadados (eager-load: garantir `->with(['account','category','madeBy'])` no `TransactionController@index`).

- [ ] **Step 7: Run, expect pass** + suite completo.

- [ ] **Step 8: Commit**

```bash
git add app/Http resources/views/transactions tests/Feature
git commit -m "Feat: 'quem fez a compra' (autor do lançamento) com seletor e validação por família"
```

---

### Task 8: Tela de Dependentes + card da sidebar + CSS

**Files:**
- Create: `resources/views/dependents/index.blade.php`
- Modify: `resources/views/partials/sidebar.blade.php`, `resources/css/design-system.css`
- Test: `tests/Feature/FamilyAccountTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_titular_sees_dependents_page_with_member(): void
{
    $titular = \App\Models\User::factory()->create();
    \App\Models\User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Maria Dependente']);

    $this->actingAs($titular)->get('/dependentes')
        ->assertOk()
        ->assertSee('Maria Dependente')
        ->assertSee('Adicionar dependente');
}

public function test_dependent_does_not_see_sidebar_dependents_card(): void
{
    $titular = \App\Models\User::factory()->create();
    $dependent = \App\Models\User::factory()->create(['account_owner_id' => $titular->id]);

    // Dependente acessa uma tela permitida; o card "Dependentes" não aparece pra ele.
    $this->actingAs($dependent)->get('/accounts')->assertOk()->assertDontSee('>Dependentes<', false);
}
```

- [ ] **Step 2: Run, expect fail** (view `dependents.index` não existe).

- [ ] **Step 3: View `dependents/index.blade.php`** — `@extends('layouts.app')`, `@section('title','Dependentes')`, com: `.section-head` (h2 "Dependentes" + sub + botão `.btn-primary` "Adicionar dependente" que abre o modal); card `.span12` com `.dep-grid` listando `.dep-person` (avatar `.ab` com iniciais, nome, badge `Titular`/`Dependente`, e-mail, form de excluir nos dependentes); estado vazio `.dep-empty` quando `$dependents` vazio; modal `.modal-scrim`/`.modal.modal-lg` com form POST `dependentes.store` (campos `.field`/`.input`: Nome, E-mail, Senha). Banner de erros via `$errors`. Abre/fecha o modal por JS mínimo inline (ou reusar padrão de modal existente).

- [ ] **Step 4: CSS** — portar de `design/project/styles.css` para a seção "Extensões" do `design-system.css` os blocos: `.dep-grid` (linha ~631), `.dep-person` (~632-647), `.dep-empty` (~648-652) e `.modal-lg` (~700). Manter os tokens (`var(--...)`).

- [ ] **Step 5: Sidebar** — em `sidebar.blade.php`, envolver o bloco `<a class="dep-card" ...>` (linhas 85-94) com `@if(auth()->user()->isTitular()) ... @endif` e trocar o texto do estado por contagem real:

```blade
@if (auth()->user()->isTitular())
@php($numDep = auth()->user()->dependents()->count())
<a class="dep-card {{ request()->routeIs('dependentes') ? 'active' : '' }}" href="{{ route('dependentes') }}">
    {{-- ...avatars... --}}
    <div class="dep-card-txt">
        <strong>Dependentes</strong>
        <span>{{ $numDep === 0 ? 'Nenhum dependente' : $numDep.' '.($numDep === 1 ? 'pessoa' : 'pessoas') }}</span>
    </div>
    {{-- ...chevron... --}}
</a>
@endif
```

- [ ] **Step 6: Build + run** — `npm run build`; `docker compose exec app php artisan test --filter=FamilyAccountTest`.

- [ ] **Step 7: Commit**

```bash
git add resources/views/dependents resources/views/partials/sidebar.blade.php resources/css/design-system.css tests/Feature
git commit -m "Feat: tela de Dependentes (cadastro/remoção) + card da sidebar"
```

---

### Task 9: Suite completa + CLAUDE.md

- [ ] **Step 1:** `docker compose exec app php artisan test` — tudo verde (87 antigos ajustados + novos da família).
- [ ] **Step 2:** Atualizar `CLAUDE.md`: marcar Dependentes como implementado (sai do "em breve"), documentar `account_owner_id`/`ownerId()`, `made_by_user_id`, rota `dependentes`, e a regra "escopo por família".
- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: CLAUDE.md — conta-família (dependentes) implementada"
```
