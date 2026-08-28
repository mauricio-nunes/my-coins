<?php

namespace Tests\Feature;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardUpcomingTransactionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_upcoming_transactions_include_today_and_are_limited_in_ascending_order(): void
    {
        $this->freezeDate();
        $this->signInWithFinanceData();
        $user = auth()->user();
        $categoryId = $this->categoryId('Alimentação');

        $this->expense($user, 'Passada', '2026-08-27', $categoryId);
        $deleted = $this->expense($user, 'Excluída', '2026-08-28', $categoryId);
        $deleted->delete();
        $this->expense(User::factory()->create(), 'Outra pessoa', '2026-08-28', $categoryId);

        $this->income($user, 'Hoje receita', '2026-08-28');
        $this->transfer($user, 'Hoje transferência', '2026-08-28');
        $this->recurringExpense($user, 'Recorrente futura', '2026-08-29', $categoryId);
        foreach (range(2, 10) as $days) {
            $this->expense($user, "Futura {$days}", CarbonImmutable::today()->addDays($days)->toDateString(), $categoryId);
        }

        $upcoming = app(FinanceStore::class)->upcomingTransactions();

        $this->assertCount(10, $upcoming);
        $this->assertSame([
            'Hoje receita',
            'Hoje transferência',
            'Recorrente futura',
            'Futura 2',
            'Futura 3',
            'Futura 4',
            'Futura 5',
            'Futura 6',
            'Futura 7',
            'Futura 8',
        ], $upcoming->pluck('description')->all());
        $this->assertSame(['income', 'transfer', 'expense'], $upcoming->take(3)->pluck('type')->all());
        $this->assertNotContains('Passada', $upcoming->pluck('description'));
        $this->assertNotContains('Excluída', $upcoming->pluck('description'));
        $this->assertNotContains('Outra pessoa', $upcoming->pluck('description'));
    }

    public function test_dashboard_renders_upcoming_transactions_and_links_to_the_current_month(): void
    {
        $this->freezeDate();
        $this->signInWithFinanceData();
        $this->expense(auth()->user(), 'Consulta futura', '2026-09-02', $this->categoryId('Alimentação'));
        $monthUrl = route('transactions.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Próximas transações')
            ->assertDontSee('Transações recentes')
            ->assertSee('Consulta futura')
            ->assertSee('Ver todas do mês')
            ->assertSee($monthUrl);

        $this->get('/transactions?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertSee('value="2026-08-01"', false)
            ->assertSee('value="2026-08-31"', false)
            ->assertDontSee('Consulta futura');
    }

    public function test_dashboard_shows_an_empty_state_without_upcoming_transactions(): void
    {
        $this->freezeDate();
        $this->signInWithFinanceData();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Próximas transações')
            ->assertSee('Nenhuma transação futura encontrada.');
    }

    private function freezeDate(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');
        CarbonImmutable::setTestNow('2026-08-28 12:00:00');
    }

    private function expense(User $user, string $description, string $date, int $categoryId): Transaction
    {
        return Transaction::create([
            'user_id' => $user->id,
            'description' => $description,
            'type' => 'expense',
            'amount' => 1000,
            'date' => $date,
            'account_id' => 1,
            'category_id' => $categoryId,
        ]);
    }

    private function income(User $user, string $description, string $date): Transaction
    {
        return Transaction::create([
            'user_id' => $user->id,
            'description' => $description,
            'type' => 'income',
            'amount' => 1000,
            'date' => $date,
            'account_id' => 1,
            'category_id' => $this->categoryId('Trabalho'),
        ]);
    }

    private function transfer(User $user, string $description, string $date): Transaction
    {
        return Transaction::create([
            'user_id' => $user->id,
            'description' => $description,
            'type' => 'transfer',
            'amount' => 1000,
            'date' => $date,
            'source_account_id' => 1,
            'destination_account_id' => 2,
        ]);
    }

    private function recurringExpense(User $user, string $description, string $date, int $categoryId): Transaction
    {
        $recurrence = RecurringTransaction::create([
            'series_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'description' => $description,
            'type' => 'expense',
            'amount' => 1000,
            'account_id' => 1,
            'category_id' => $categoryId,
            'frequency' => 'monthly',
            'start_date' => $date,
            'status' => 'active',
        ]);

        return Transaction::create([
            'user_id' => $user->id,
            'description' => $description,
            'type' => 'expense',
            'amount' => 1000,
            'date' => $date,
            'account_id' => 1,
            'category_id' => $categoryId,
            'recurring_transaction_id' => $recurrence->id,
            'recurrence_date' => $date,
        ]);
    }
}
