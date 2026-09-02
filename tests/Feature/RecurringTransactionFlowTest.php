<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RecurringTransactionFlowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_monthly_recurrence_uses_the_last_valid_day_and_copies_tags(): void
    {
        $this->freeze('2026-01-15');
        $this->signInWithFinanceData();

        $this->post('/transactions', $this->payload([
            'date' => '2026-01-31',
            'recurring' => '1',
            'frequency' => 'monthly',
            'recurrence_end_date' => '2026-04-30',
            'tags' => ['Essencial'],
        ]))->assertRedirect('/transactions/11')->assertSessionHasNoErrors();

        $rule = RecurringTransaction::query()->firstOrFail();
        $this->assertSame('active', $rule->status);
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            Transaction::query()->where('recurring_transaction_id', $rule->id)->orderBy('date')->pluck('date')->map->format('Y-m-d')->all(),
        );
        $this->assertTrue($rule->transactions()->with('tags')->get()->every(
            fn (Transaction $transaction): bool => $transaction->tags->pluck('name')->all() === ['Essencial'],
        ));
    }

    public function test_past_start_keeps_the_entered_occurrence_without_backfilling_history(): void
    {
        $this->freeze('2026-08-26');
        $this->signInWithFinanceData();

        $this->post('/transactions', $this->payload([
            'date' => '2026-05-31',
            'recurring' => '1',
            'frequency' => 'monthly',
        ]))->assertSessionHasNoErrors();

        $dates = Transaction::query()->whereNotNull('recurring_transaction_id')->orderBy('date')->pluck('date')->map->format('Y-m-d');
        $this->assertSame('2026-05-31', $dates->first());
        $this->assertFalse($dates->contains('2026-06-30'));
        $this->assertFalse($dates->contains('2026-07-31'));
        $this->assertSame('2026-08-31', $dates[1]);
    }

    public function test_weekly_and_yearly_schedules_keep_their_calendar_anchor(): void
    {
        $this->freeze('2028-01-15');
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->payload([
            'description' => 'Semanal',
            'date' => '2028-02-01',
            'recurring' => '1',
            'frequency' => 'weekly',
            'recurrence_end_date' => '2028-02-15',
        ]));
        $this->post('/transactions', $this->payload([
            'description' => 'Anual bissexta',
            'date' => '2028-02-29',
            'recurring' => '1',
            'frequency' => 'yearly',
            'recurrence_end_date' => '2031-02-28',
        ]));

        $weekly = RecurringTransaction::query()->where('description', 'Semanal')->firstOrFail();
        $yearly = RecurringTransaction::query()->where('description', 'Anual bissexta')->firstOrFail();
        $this->assertSame(
            ['2028-02-01', '2028-02-08', '2028-02-15'],
            $weekly->transactions()->orderBy('date')->pluck('date')->map->format('Y-m-d')->all(),
        );
        $this->assertSame(
            ['2028-02-29'],
            $yearly->transactions()->orderBy('date')->pluck('date')->map->format('Y-m-d')->all(),
        );
        $this->freeze('2029-03-01');
        $this->artisan('mycoins:generate-recurrences')->assertSuccessful();
        $this->assertSame(
            ['2028-02-29', '2029-02-28', '2030-02-28'],
            $yearly->transactions()->orderBy('date')->pluck('date')->map->format('Y-m-d')->all(),
        );
    }

    public function test_open_recurrence_maintains_an_idempotent_twelve_month_horizon(): void
    {
        $this->freeze('2026-08-20');
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->payload([
            'date' => '2026-09-01',
            'recurring' => '1',
            'frequency' => 'monthly',
        ]));
        $rule = RecurringTransaction::query()->firstOrFail();
        $count = $rule->transactions()->count();

        $this->artisan('mycoins:generate-recurrences')->assertSuccessful();
        $this->artisan('mycoins:generate-recurrences')->assertSuccessful();

        $this->assertSame($count, $rule->transactions()->count());
        $this->assertSame('2027-08-01', $rule->transactions()->latest('date')->first()->date->format('Y-m-d'));
        $this->assertSame('2027-08-20', $rule->fresh()->generated_until->format('Y-m-d'));
    }

    public function test_single_edit_is_an_exception_and_future_edit_replaces_the_series(): void
    {
        $this->freeze('2026-08-20');
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->payload([
            'date' => '2026-09-05',
            'recurring' => '1',
            'frequency' => 'monthly',
        ]));

        $october = Transaction::query()->whereDate('recurrence_date', '2026-10-05')->firstOrFail();
        $this->put("/transactions/{$october->id}", $this->payload([
            'amount' => '200,00',
            'date' => '2026-10-06',
            'recurrence_scope' => 'single',
            'frequency' => 'monthly',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('transactions', ['id' => $october->id, 'amount' => 20000, 'date' => '2026-10-06', 'recurrence_date' => '2026-10-05']);
        $this->assertDatabaseHas('transactions', ['date' => '2026-11-05', 'amount' => 10000]);

        $november = Transaction::query()->whereDate('recurrence_date', '2026-11-05')->firstOrFail();
        $this->put("/transactions/{$november->id}", $this->payload([
            'amount' => '300,00',
            'date' => '2026-11-05',
            'recurrence_scope' => 'future',
            'frequency' => 'weekly',
            'recurrence_end_date' => '2026-11-20',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('superseded', RecurringTransaction::query()->oldest('id')->first()->status);
        $replacement = RecurringTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame('weekly', $replacement->frequency);
        $this->assertSame(
            ['2026-11-05', '2026-11-12', '2026-11-19'],
            $replacement->transactions()->orderBy('date')->pluck('date')->map->format('Y-m-d')->all(),
        );
        $this->assertDatabaseMissing('transactions', ['date' => '2026-12-05', 'deleted_at' => null]);
    }

    public function test_deleted_single_occurrence_is_not_regenerated_and_future_scope_cancels(): void
    {
        $this->freeze('2026-08-20');
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->payload([
            'date' => '2026-09-01',
            'recurring' => '1',
            'frequency' => 'monthly',
        ]));
        $october = Transaction::query()->whereDate('recurrence_date', '2026-10-01')->firstOrFail();
        $this->delete("/transactions/{$october->id}", ['recurrence_scope' => 'single'])->assertRedirect('/transactions');
        $this->artisan('mycoins:generate-recurrences')->assertSuccessful();
        $this->assertSoftDeleted('transactions', ['id' => $october->id]);

        $november = Transaction::query()->whereDate('recurrence_date', '2026-11-01')->firstOrFail();
        $this->delete("/transactions/{$november->id}", ['recurrence_scope' => 'future'])->assertRedirect('/transactions');
        $this->assertSoftDeleted('transactions', ['id' => $november->id]);
        $this->assertSame('cancelled', RecurringTransaction::query()->first()->status);
        $this->assertSame(1, Transaction::query()->whereNotNull('recurring_transaction_id')->count());
    }

    public function test_archiving_an_account_pauses_recurrences_and_preserves_past_occurrences(): void
    {
        $this->freeze('2026-08-26');
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->payload([
            'date' => '2026-08-25',
            'recurring' => '1',
            'frequency' => 'weekly',
        ]));

        $this->delete('/accounts/1')->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'recorrência'));

        $rule = RecurringTransaction::query()->firstOrFail();
        $this->assertSame('paused', $rule->status);
        $this->assertSame('A conta vinculada foi arquivada.', $rule->paused_reason);
        $this->assertDatabaseHas('transactions', ['recurring_transaction_id' => $rule->id, 'date' => '2026-08-25', 'deleted_at' => null]);
        $this->assertSame(1, Transaction::query()->where('recurring_transaction_id', $rule->id)->count());

        $this->patch("/recurrences/{$rule->id}/resume", ['account_id' => 2])->assertRedirect('/recurrences');
        $replacement = RecurringTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame('active', $replacement->status);
        $this->assertSame(2, $replacement->account_id);
        $this->assertDatabaseHas('transactions', ['recurring_transaction_id' => $replacement->id, 'date' => '2026-09-01']);
    }

    public function test_future_occurrences_feed_account_projections_but_not_budget_actual_spending(): void
    {
        $this->freeze('2026-08-20');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $balanceBefore = $store->balance(1);
        $budget = Budget::create(['user_id' => auth()->id(), 'name' => 'Alimentação', 'normalized_name' => 'alimentação', 'active_name_key' => hash('sha256', auth()->id().'|2026-09|alimentação'), 'month' => '2026-09', 'limit' => 50000]);
        $budget->categories()->attach($this->categoryId('Alimentação'));

        $this->post('/transactions', $this->payload([
            'date' => '2026-09-10',
            'recurring' => '1',
            'frequency' => 'monthly',
        ]));

        $budget = $store->all('budgets');
        $september = collect($budget)->firstWhere('month', '2026-09');
        $this->assertSame($balanceBefore, $store->balance(1));
        $this->assertSame(0, $store->budgetSpent($september));
        $this->assertSame(10000, $store->budgetMetrics($september)['projection']);
        $this->assertSame($balanceBefore - 10000, $store->balanceAt(1, '2026-09-10'));
    }

    public function test_recurrence_management_is_owner_scoped(): void
    {
        $this->freeze('2026-08-20');
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->payload(['recurring' => '1', 'frequency' => 'monthly']));
        $rule = RecurringTransaction::query()->firstOrFail();

        $other = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($other)
            ->get('/recurrences')->assertOk()->assertDontSee('Academia recorrente');
        $this->delete("/recurrences/{$rule->id}")->assertNotFound();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Academia recorrente',
            'type' => 'expense',
            'amount' => '100,00',
            'date' => now()->addDay()->toDateString(),
            'account_id' => 1,
            'category_id' => $this->categoryId('Alimentação'),
            'notes' => '',
            'tags' => [],
        ], $overrides);
    }

    private function freeze(string $date): void
    {
        Carbon::setTestNow("{$date} 12:00:00");
        CarbonImmutable::setTestNow("{$date} 12:00:00");
    }
}
