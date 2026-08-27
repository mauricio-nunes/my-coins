# Guia para implementar um CRUD no My Coins

Este documento descreve como adicionar um CRUD seguindo a arquitetura atual do My Coins. O exemplo didático será **Meta financeira** (`FinancialGoal`). Ele cobre migration, model, persistência, controller, rotas, AdminLTE/Blade e testes. Adapte nomes e campos para o recurso real; os snippets não significam que metas financeiras já façam parte dos requisitos do produto.

## 1. Entenda o fluxo da aplicação

```text
Navegador
   │
   ▼
Route protegida ──► Controller ──► FinanceStore ──► Model/Eloquent ──► MySQL
                         │
                         └────────► View Blade/AdminLTE ────────────► HTML
```

As responsabilidades são:

- `routes/web.php`: URL, verbo HTTP, middleware e nome da rota.
- Controller em `app/Http/Controllers/Finance`: valida entrada, coordena o fluxo e escolhe a resposta.
- `app/Services/FinanceStore.php`: centraliza consultas e mutações financeiras com escopo do proprietário autenticado.
- Model em `app/Models`: casts, soft delete e relacionamentos Eloquent.
- Views em `resources/views/<recurso>`: interface em pt-BR usando o layout AdminLTE existente.
- Testes: garantem comportamento, validação, ownership e acessibilidade.

Nunca consulte um recurso financeiro sem restringi-lo ao usuário autenticado. O `FinanceStore` aplica `where('user_id', auth()->id())` aos recursos registrados em seu mapa interno.

## 2. Gere a estrutura inicial

Com Docker Compose legado:

```bash
docker-compose run --rm app php artisan make:model FinancialGoal -m
docker-compose run --rm app php artisan make:controller Finance/FinancialGoalController --resource
```

Com Compose v2, troque `docker-compose` por `docker compose`.

Crie também, manualmente:

```text
resources/views/goals/index.blade.php
resources/views/goals/form.blade.php
resources/views/goals/show.blade.php
tests/Feature/FinancialGoalFlowTest.php
```

## 3. Crie a migration

Edite a migration gerada em `database/migrations/`. Valores monetários devem ser inteiros em centavos; não use `float` ou `decimal` na aplicação.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedBigInteger('target_amount');
            $table->date('target_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_goals');
    }
};
```

Use `restrictOnDelete()` quando um relacionamento histórico não puder desaparecer com o registro pai. Use `cascadeOnDelete()` apenas quando os registros filhos não tiverem sentido independente.

## 4. Implemente o model

Crie `app/Models/FinancialGoal.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialGoal extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'target_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

O projeto usa `$guarded = []` porque os controllers enviam somente arrays validados e o `FinanceStore` adiciona o `user_id`. Não passe `$request->all()` para persistência.

## 5. Registre o recurso no FinanceStore

Importe o model e adicione-o ao mapa `MODELS` em `app/Services/FinanceStore.php`:

```php
use App\Models\FinancialGoal;

private const MODELS = [
    // recursos existentes...
    'goals' => FinancialGoal::class,
];
```

Isso habilita os métodos existentes:

```php
$store->all('goals');
$store->find('goals', $id);
$store->create('goals', $attributes);
$store->update('goals', $id, $attributes);
$store->delete('goals', $id);
```

Esses métodos adicionam ou verificam `user_id` internamente. Regras específicas do domínio, cálculos e mutações com várias tabelas devem virar métodos nomeados no `FinanceStore`, preferencialmente dentro de `DB::transaction()`.

## 6. Implemente o controller

Crie `app/Http/Controllers/Finance/FinancialGoalController.php`:

```php
<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinancialGoalController extends Controller
{
    public function index(FinanceStore $store): View
    {
        $goals = collect($store->all('goals'))
            ->sortBy(fn (array $goal): string => $goal['target_date'] ?? '9999-12-31')
            ->values();

        return view('goals.index', compact('goals'));
    }

    public function create(): View
    {
        return view('goals.form', ['goal' => null]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $goal = $store->create('goals', $this->validated($request));

        return redirect()->route('goals.show', $goal['id'])
            ->with('success', 'Meta financeira criada com sucesso.');
    }

    public function show(int $goal, FinanceStore $store): View
    {
        return view('goals.show', [
            'goal' => $store->find('goals', $goal) ?? abort(404),
        ]);
    }

    public function edit(int $goal, FinanceStore $store): View
    {
        return view('goals.form', [
            'goal' => $store->find('goals', $goal) ?? abort(404),
        ]);
    }

    public function update(Request $request, int $goal, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('goals', $goal), 404);
        $store->update('goals', $goal, $this->validated($request));

        return redirect()->route('goals.show', $goal)
            ->with('success', 'Meta financeira atualizada.');
    }

    public function destroy(int $goal, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->delete('goals', $goal), 404);

        return redirect()->route('goals.index')
            ->with('success', 'Meta financeira excluída.');
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'target_amount' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'target_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,paused,completed'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $validated['target_amount'] = Money::toCents($validated['target_amount']);
        if ($validated['target_amount'] <= 0) {
            throw ValidationException::withMessages([
                'target_amount' => 'O valor-alvo deve ser maior que zero.',
            ]);
        }

        return $validated;
    }
}
```

Use 404 para IDs inexistentes e para recursos de outro usuário. Não diferencie esses casos na resposta, evitando revelar a existência de dados alheios.

## 7. Registre as rotas e o menu

Importe o controller em `routes/web.php` e registre a resource route dentro do grupo `['auth', 'password.changed']`:

```php
use App\Http\Controllers\Finance\FinancialGoalController;

Route::resource('goals', FinancialGoalController::class);
```

Isso cria:

| Verbo | URL | Ação | Rota |
| --- | --- | --- | --- |
| GET | `/goals` | index | `goals.index` |
| GET | `/goals/create` | create | `goals.create` |
| POST | `/goals` | store | `goals.store` |
| GET | `/goals/{goal}` | show | `goals.show` |
| GET | `/goals/{goal}/edit` | edit | `goals.edit` |
| PUT/PATCH | `/goals/{goal}` | update | `goals.update` |
| DELETE | `/goals/{goal}` | destroy | `goals.destroy` |

Adicione em `config/adminlte.php`, na seção de organização ou planejamento:

```php
[
    'text' => 'Metas financeiras',
    'route' => 'goals.index',
    'active' => ['goals*'],
    'icon' => 'bi bi-flag',
],
```

## 8. Construa as views Blade

### Listagem

`resources/views/goals/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Metas financeiras')
@section('eyebrow', 'PLANEJAMENTO')
@section('page_title', 'Metas financeiras')
@section('page_subtitle', 'Acompanhe seus objetivos financeiros.')
@section('page_actions')
    <a href="{{ route('goals.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nova meta
    </a>
@endsection

@section('page_content')
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Meta</th><th>Prazo</th><th>Status</th><th class="text-end">Valor</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
            <tbody>
            @forelse($goals as $goal)
                <tr>
                    <td><a href="{{ route('goals.show', $goal['id']) }}" class="fw-medium text-decoration-none">{{ $goal['name'] }}</a></td>
                    <td>{{ $goal['target_date'] ? \Carbon\Carbon::parse($goal['target_date'])->format('d/m/Y') : 'Sem prazo' }}</td>
                    <td>{{ ['active' => 'Ativa', 'paused' => 'Pausada', 'completed' => 'Concluída'][$goal['status']] }}</td>
                    <td class="text-end"><x-money :value="$goal['target_amount']" /></td>
                    <td class="text-end"><a href="{{ route('goals.edit', $goal['id']) }}" class="btn btn-sm btn-light" aria-label="Editar {{ $goal['name'] }}"><i class="bi bi-pencil"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center py-5">Nenhuma meta financeira cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
```

### Formulário compartilhado

`resources/views/goals/form.blade.php` deve servir para criação e edição:

```blade
@extends('layouts.app')
@php($editing = (bool) $goal)
@section('title', $editing ? 'Editar meta' : 'Nova meta')
@section('eyebrow', 'METAS FINANCEIRAS')
@section('page_title', $editing ? 'Editar meta' : 'Nova meta')
@section('page_subtitle', 'Defina o valor e o prazo do objetivo.')

@section('page_content')
<div class="row"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
<form method="post" action="{{ $editing ? route('goals.update', $goal['id']) : route('goals.store') }}">
    @csrf
    @if($editing) @method('put') @endif
    <div class="row g-3">
        <div class="col-12"><label for="name" class="form-label">Nome</label><input id="name" name="name" value="{{ old('name', $goal['name'] ?? '') }}" maxlength="80" required class="form-control @error('name') is-invalid @enderror"><x-field-error name="name" /></div>
        <div class="col-md-6"><label for="target_amount" class="form-label">Valor-alvo</label><div class="input-group"><span class="input-group-text">R$</span><input id="target_amount" name="target_amount" inputmode="decimal" value="{{ old('target_amount', $goal ? number_format($goal['target_amount']/100, 2, ',', '') : '') }}" required class="form-control @error('target_amount') is-invalid @enderror"></div><x-field-error name="target_amount" /></div>
        <div class="col-md-6"><label for="target_date" class="form-label">Prazo</label><input id="target_date" type="date" name="target_date" value="{{ old('target_date', $goal['target_date'] ?? '') }}" class="form-control @error('target_date') is-invalid @enderror"><x-field-error name="target_date" /></div>
        <div class="col-md-6"><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>@foreach(['active' => 'Ativa', 'paused' => 'Pausada', 'completed' => 'Concluída'] as $value => $label)<option value="{{ $value }}" @selected(old('status', $goal['status'] ?? 'active') === $value)>{{ $label }}</option>@endforeach</select><x-field-error name="status" /></div>
        <div class="col-12"><label for="notes" class="form-label">Observações</label><textarea id="notes" name="notes" rows="3" maxlength="500" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $goal['notes'] ?? '') }}</textarea><x-field-error name="notes" /></div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('goals.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary">{{ $editing ? 'Salvar alterações' : 'Criar meta' }}</button></div>
</form>
</div></div></div></div>
@endsection
```

### Detalhes e exclusão

`resources/views/goals/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', $goal['name'])
@section('eyebrow', 'META FINANCEIRA')
@section('page_title', $goal['name'])
@section('page_subtitle', 'Detalhes do objetivo financeiro.')
@section('page_actions')<a href="{{ route('goals.edit', $goal['id']) }}" class="btn btn-primary"><i class="bi bi-pencil me-1"></i> Editar</a>@endsection

@section('page_content')
<div class="row"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
    <dl class="row mb-0">
        <dt class="col-sm-4">Valor-alvo</dt><dd class="col-sm-8"><x-money :value="$goal['target_amount']" /></dd>
        <dt class="col-sm-4">Prazo</dt><dd class="col-sm-8">{{ $goal['target_date'] ? \Carbon\Carbon::parse($goal['target_date'])->format('d/m/Y') : 'Sem prazo' }}</dd>
        <dt class="col-sm-4">Status</dt><dd class="col-sm-8">{{ ['active' => 'Ativa', 'paused' => 'Pausada', 'completed' => 'Concluída'][$goal['status']] }}</dd>
        <dt class="col-sm-4">Observações</dt><dd class="col-sm-8">{{ $goal['notes'] ?: 'Nenhuma observação.' }}</dd>
    </dl>
</div><div class="card-footer bg-transparent">
    <form method="post" action="{{ route('goals.destroy', $goal['id']) }}" data-confirm="Excluir esta meta financeira?">@csrf @method('delete')<button class="btn btn-outline-danger">Excluir meta</button></form>
</div></div></div></div>
@endsection
```

O atributo `data-confirm` é processado pelo JavaScript compartilhado. Use `<x-field-error>` para mensagens e `<x-money>` para BRL. Toda ação representada apenas por ícone precisa de `aria-label`.

## 9. Escreva testes de feature

Crie `tests/Feature/FinancialGoalFlowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinancialGoal;
use App\Models\User;
use Tests\TestCase;

class FinancialGoalFlowTest extends TestCase
{
    public function test_owner_can_create_view_update_and_delete_a_goal(): void
    {
        $this->signInWithFinanceData();
        $payload = [
            'name' => 'Reserva de emergência',
            'target_amount' => '12500,00',
            'target_date' => now()->addYear()->toDateString(),
            'status' => 'active',
            'notes' => 'Objetivo de seis meses de despesas',
        ];

        $this->post('/goals', $payload)->assertRedirect();
        $goal = FinancialGoal::query()->where('name', 'Reserva de emergência')->firstOrFail();
        $this->assertSame(1250000, $goal->target_amount);
        $this->get("/goals/{$goal->id}")->assertOk()->assertSee('R$ 12.500,00');

        $this->put("/goals/{$goal->id}", array_merge($payload, ['name' => 'Reserva atualizada']))
            ->assertRedirect("/goals/{$goal->id}");
        $this->assertDatabaseHas('financial_goals', ['id' => $goal->id, 'name' => 'Reserva atualizada']);

        $this->delete("/goals/{$goal->id}")->assertRedirect('/goals');
        $this->assertSoftDeleted('financial_goals', ['id' => $goal->id]);
    }

    public function test_goal_validation_rejects_invalid_data(): void
    {
        $this->signInWithFinanceData()->post('/goals', [
            'name' => '',
            'target_amount' => '-10,00',
            'target_date' => 'invalid',
            'status' => 'unknown',
        ])->assertSessionHasErrors(['name', 'target_amount', 'target_date', 'status']);
    }

    public function test_owner_cannot_access_another_users_goal(): void
    {
        $this->signInWithFinanceData();
        $other = User::factory()->create();
        $goal = FinancialGoal::create([
            'user_id' => $other->id,
            'name' => 'Meta privada',
            'target_amount' => 100000,
            'status' => 'active',
        ]);

        $this->get("/goals/{$goal->id}")->assertNotFound();
        $this->put("/goals/{$goal->id}", [])->assertNotFound();
        $this->delete("/goals/{$goal->id}")->assertNotFound();
    }
}
```

Evite IDs fixos. Localize o registro criado pelo comportamento observado. Cubra também regras de domínio específicas e relacionamentos inválidos.

## 10. Adicione cobertura Playwright e acessibilidade

Em `tests/browser/finance.spec.js`, adicione um cenário que:

1. Faça login.
2. Abra `/goals/create`.
3. Preencha nome, valor, prazo, status e observações.
4. Salve e confirme os detalhes.
5. Edite o registro e confirme a atualização.
6. Exclua aceitando o diálogo de confirmação.
7. Confirme o retorno à listagem e a ausência do registro.

Inclua `/goals` na lista de páginas verificadas pelo axe. O cenário deve passar nos projetos desktop e mobile e não pode introduzir violações sérias ou críticas.

## 11. Execute e valide

Depois de implementar:

```bash
docker-compose run --rm app php artisan migrate
make format
make test
make e2e
make build
git diff --check
```

Com Compose v2:

```bash
make COMPOSE="docker compose" format
make COMPOSE="docker compose" test
make COMPOSE="docker compose" e2e
make COMPOSE="docker compose" build
```

`make test` deve usar somente `my_coins_testing`; `make e2e` deve usar somente `my_coins_e2e`. Nunca execute `migrate:fresh`, `db:wipe` ou testes destrutivos contra `my_coins`, produção ou um banco sem backup.

## Checklist para qualquer novo CRUD

- [ ] Migration possui `user_id`, índices, tipos adequados e política de exclusão definida.
- [ ] Dinheiro é armazenado em centavos inteiros.
- [ ] Model possui casts, relacionamentos e `SoftDeletes` quando há histórico.
- [ ] Recurso está registrado no `FinanceStore` ou possui métodos de domínio owner-scoped.
- [ ] Controller persiste somente dados validados.
- [ ] IDs inexistentes ou de outro proprietário retornam 404.
- [ ] Rotas estão dentro de `auth` e `password.changed`.
- [ ] Views usam AdminLTE, textos pt-BR, `old()`, `<x-field-error>` e componentes existentes.
- [ ] Exclusões relevantes pedem confirmação e preservam histórico quando necessário.
- [ ] Menu possui rota, estado ativo e Bootstrap Icon.
- [ ] Testes cobrem CRUD, validação, ownership, soft delete e cálculos.
- [ ] Playwright cobre desktop, mobile e axe.
- [ ] Pint, PHPUnit, Playwright, build e `git diff --check` passam.

## Erros comuns

- Usar `Model::find($id)` no controller sem `user_id`, expondo dados de outro proprietário.
- Usar `$request->all()` e permitir mass assignment de campos não validados.
- Armazenar BRL como `float`, gerando erros de arredondamento.
- Excluir definitivamente registros que explicam histórico financeiro.
- Duplicar consultas e regras em vários controllers em vez de centralizá-las no `FinanceStore`.
- Esquecer `old()` ou mensagens de erro, fazendo o usuário perder o formulário após validação.
- Usar IDs fixos em testes ou executar testes contra o banco de desenvolvimento.
- Alterar apenas a interface sem testar ownership e mutações no banco.
