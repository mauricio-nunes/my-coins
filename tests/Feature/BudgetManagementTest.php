<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BudgetManagementTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_budget_can_group_categories_and_names_are_unique_only_within_the_period(): void
    {
        $this->freeze('2026-09-10');
        $this->signInWithFinanceData();
        $categories = [$this->categoryId('Transporte'), $this->categoryId('Financeiro')];

        $this->post('/budgets', ['name' => 'Veículo', 'month' => '2026-09', 'category_ids' => $categories, 'limit' => '2.000,00'])
            ->assertRedirect('/budgets?month=2026-09');

        $budget = Budget::query()->where('name', 'Veículo')->firstOrFail();
        $this->assertSame($categories, $budget->categories()->orderBy('categories.id')->pluck('categories.id')->all());
        $this->assertSame(200000, $budget->limit);

        $this->from('/budgets?month=2026-09')->post('/budgets', ['name' => '  VEÍCULO ', 'month' => '2026-09', 'category_ids' => [$categories[0]], 'limit' => '100'])
            ->assertRedirect('/budgets?month=2026-09')->assertSessionHasErrors('name');
        $this->post('/budgets', ['name' => 'Veículo', 'month' => '2026-10', 'category_ids' => [$categories[0]], 'limit' => '100'])
            ->assertRedirect('/budgets?month=2026-10');
    }

    public function test_monitoring_calculates_current_spending_projection_and_shared_categories(): void
    {
        $this->freeze('2026-09-10');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $categoryId = $this->categoryId('Financeiro');
        $first = $store->createBudget(['name' => 'Compromissos', 'month' => '2026-09', 'category_ids' => [$categoryId], 'limit' => 300000]);
        $second = $store->createBudget(['name' => 'Custos fixos', 'month' => '2026-09', 'category_ids' => [$categoryId], 'limit' => 200000]);
        Transaction::create(['user_id' => auth()->id(), 'description' => 'Seguro', 'type' => 'expense', 'amount' => 150000, 'date' => '2026-09-05', 'account_id' => 1, 'category_id' => $categoryId]);
        Transaction::create(['user_id' => auth()->id(), 'description' => 'Despesa futura', 'type' => 'expense', 'amount' => 90000, 'date' => '2026-09-20', 'account_id' => 1, 'category_id' => $categoryId]);
        Transaction::create(['user_id' => auth()->id(), 'description' => 'Receita ignorada', 'type' => 'income', 'amount' => 500000, 'date' => '2026-09-06', 'account_id' => 1, 'category_id' => $categoryId]);
        $deleted = Transaction::create(['user_id' => auth()->id(), 'description' => 'Despesa excluída', 'type' => 'expense', 'amount' => 500000, 'date' => '2026-09-07', 'account_id' => 1, 'category_id' => $categoryId]);
        $deleted->delete();

        $firstMetrics = $store->budgetDetails($first['id']);
        $secondMetrics = $store->budgetDetails($second['id']);
        $this->assertSame(150000, $firstMetrics['spent']);
        $this->assertSame(150000, $secondMetrics['spent']);
        $this->assertSame(240000, $firstMetrics['projection']);
        $this->assertSame(80.0, $firstMetrics['projection_percentage']);
        $this->assertSame('within', $firstMetrics['status']);
        $this->assertSame('exceeded', $secondMetrics['status']);

        $this->get('/budgets?month=2026-09')->assertOk()->assertSee('Monitoramento de orçamentos')->assertSee('Limite excedido')->assertSee('Compromissos')->assertDontSee('Limite atingido')->assertDontSee('Em risco');
        $this->get('/dashboard')->assertOk()->assertSee('80,0%');
    }

    public function test_past_and_future_periods_include_all_monthly_expenses_in_projection(): void
    {
        $this->freeze('2026-09-10');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $categoryId = $this->categoryId('Financeiro');
        $past = $store->createBudget(['name' => 'Passado', 'month' => '2026-08', 'category_ids' => [$categoryId], 'limit' => 10000]);
        $future = $store->createBudget(['name' => 'Futuro', 'month' => '2026-10', 'category_ids' => [$categoryId], 'limit' => 10000]);
        Transaction::create(['user_id' => auth()->id(), 'description' => 'Tarifa', 'type' => 'expense', 'amount' => 9500, 'date' => '2026-08-31', 'account_id' => 1, 'category_id' => $categoryId]);
        Transaction::create(['user_id' => auth()->id(), 'description' => 'Agendada', 'type' => 'expense', 'amount' => 15000, 'date' => '2026-10-01', 'account_id' => 1, 'category_id' => $categoryId]);

        $this->assertSame('attention', $store->budgetDetails($past['id'])['status']);
        $this->assertSame(9500, $store->budgetDetails($past['id'])['projection']);
        $this->assertSame(0, $store->budgetDetails($future['id'])['spent']);
        $this->assertSame('exceeded', $store->budgetDetails($future['id'])['status']);
        $this->assertSame(15000, $store->budgetDetails($future['id'])['projection']);
    }

    public function test_status_boundaries_use_the_complete_month_total(): void
    {
        $this->freeze('2026-09-10');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $categoryId = $this->categoryId('Financeiro');
        Transaction::create(['user_id' => auth()->id(), 'description' => 'Mensalidade', 'type' => 'expense', 'amount' => 9000, 'date' => '2026-09-20', 'account_id' => 1, 'category_id' => $categoryId]);
        $ninety = $store->createBudget(['name' => 'Noventa', 'month' => '2026-09', 'category_ids' => [$categoryId], 'limit' => 10000]);
        $hundred = $store->createBudget(['name' => 'Cem', 'month' => '2026-09', 'category_ids' => [$categoryId], 'limit' => 9000]);
        $over = $store->createBudget(['name' => 'Acima', 'month' => '2026-09', 'category_ids' => [$categoryId], 'limit' => 8999]);

        $this->assertSame('within', $store->budgetDetails($ninety['id'])['status']);
        $this->assertSame('attention', $store->budgetDetails($hundred['id'])['status']);
        $this->assertSame('exceeded', $store->budgetDetails($over['id'])['status']);
    }

    public function test_budget_can_be_copied_edited_and_soft_deleted_without_financial_changes(): void
    {
        $this->freeze('2026-09-10');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $source = $store->createBudget(['name' => 'Transporte familiar', 'month' => '2026-09', 'category_ids' => [$this->categoryId('Transporte')], 'limit' => 200000]);

        $this->get("/budgets/{$source['id']}")->assertStatus(405);
        $this->post("/budgets/{$source['id']}/copy", ['destination_month' => '2026-10', 'source_budget_id' => $source['id']])
            ->assertRedirect('/budgets?month=2026-10');
        $copy = Budget::query()->where('month', '2026-10')->where('name', 'Transporte familiar')->firstOrFail();
        $this->assertNotSame($source['id'], $copy->id);

        $this->put("/budgets/{$copy->id}", ['name' => 'Carro', 'month' => '2026-10', 'category_ids' => [$this->categoryId('Transporte')], 'limit' => '2.500,00'])
            ->assertRedirect('/budgets?month=2026-10');
        $this->assertSame('Transporte familiar', Budget::query()->findOrFail($source['id'])->name);

        $transactionsBefore = Transaction::query()->count();
        $this->delete("/budgets/{$copy->id}", ['month' => '2026-10'])->assertRedirect('/budgets?month=2026-10');
        $this->assertSoftDeleted('budgets', ['id' => $copy->id]);
        $this->assertSame($transactionsBefore, Transaction::query()->count());
        $this->assertDatabaseHas('budget_category', ['budget_id' => $copy->id, 'category_id' => $this->categoryId('Transporte')]);
    }

    public function test_budget_actions_are_scoped_to_the_authenticated_owner(): void
    {
        $this->freeze('2026-09-10');
        $this->signInWithFinanceData();
        $other = User::factory()->create(['email' => 'other@example.test']);
        $foreign = Budget::create(['user_id' => $other->id, 'name' => 'Privado', 'normalized_name' => 'privado', 'active_name_key' => hash('sha256', $other->id.'|2026-09|privado'), 'month' => '2026-09', 'limit' => 10000]);

        $this->get("/budgets/{$foreign->id}")->assertStatus(405);
        $this->put("/budgets/{$foreign->id}", ['name' => 'Invadido', 'month' => '2026-09', 'category_ids' => [$this->categoryId('Transporte')], 'limit' => '100'])->assertNotFound();
        $this->delete("/budgets/{$foreign->id}")->assertNotFound();
    }

    private function freeze(string $date): void
    {
        Carbon::setTestNow($date);
        CarbonImmutable::setTestNow($date);
    }
}
