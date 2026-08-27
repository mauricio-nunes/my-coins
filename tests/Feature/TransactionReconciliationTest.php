<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
use Tests\TestCase;

class TransactionReconciliationTest extends TestCase
{
    public function test_reconciliation_can_be_set_when_creating_and_editing_transactions_and_transfers(): void
    {
        $this->signInWithFinanceData();

        $this->post('/transactions', $this->transactionPayload(['reconciled' => '1']))
            ->assertRedirect('/transactions/11');
        $this->assertDatabaseHas('transactions', ['id' => 11, 'reconciled' => true]);

        $this->put('/transactions/11', $this->transactionPayload(['description' => 'Despesa revisada', 'reconciled' => '0']))
            ->assertRedirect('/transactions/11');
        $this->assertDatabaseHas('transactions', ['id' => 11, 'reconciled' => false]);

        $this->post('/transfers', $this->transferPayload(['reconciled' => '1']))
            ->assertRedirect('/transactions/12');
        $this->assertDatabaseHas('transactions', ['id' => 12, 'type' => 'transfer', 'reconciled' => true]);

        $this->put('/transfers/12', $this->transferPayload(['description' => 'Transferência revisada', 'reconciled' => '0']))
            ->assertRedirect('/transactions/12');
        $this->assertDatabaseHas('transactions', ['id' => 12, 'reconciled' => false]);
    }

    public function test_transactions_can_be_filtered_by_reconciliation_status(): void
    {
        $this->signInWithFinanceData();
        Transaction::query()->whereKey(3)->update(['reconciled' => true]);

        $this->get('/transactions?reconciled=yes')
            ->assertOk()
            ->assertSee('Supermercado Vila')
            ->assertDontSee('Farmácia')
            ->assertSee('value="yes" selected', false);

        $this->get('/transactions?reconciled=no&account_id=1&type=expense')
            ->assertOk()
            ->assertDontSee('Supermercado Vila')
            ->assertSee('Farmácia')
            ->assertSee('value="no" selected', false);
    }

    public function test_quick_reconciliation_toggles_without_changing_financial_calculations_and_returns_to_the_filtered_page(): void
    {
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $balance = $store->balance(1);
        $budget = $store->find('budgets', 2);
        $budgetSpent = $store->budgetSpent($budget);
        $returnTo = '/transactions?search=Supermercado&type=expense&account_id=1&page=2';

        $this->patch('/transactions/3/reconciliation', ['return_to' => $returnTo])
            ->assertRedirect($returnTo)
            ->assertSessionHas('success', 'Transação conciliada.');
        $this->assertDatabaseHas('transactions', ['id' => 3, 'reconciled' => true]);
        $this->assertSame($balance, $store->balance(1));
        $this->assertSame($budgetSpent, $store->budgetSpent($budget));

        $this->patch('/transactions/3/reconciliation', ['return_to' => $returnTo])
            ->assertRedirect($returnTo)
            ->assertSessionHas('success', 'Conciliação desfeita.');
        $this->assertDatabaseHas('transactions', ['id' => 3, 'reconciled' => false]);
    }

    public function test_edit_cancel_and_save_preserve_the_transaction_list_context_for_transactions_and_transfers(): void
    {
        $this->signInWithFinanceData();
        $returnTo = '/transactions?type=expense&account_id=1&reconciled=no&page=2';
        $encodedReturn = urlencode($returnTo);

        $this->get("/transactions/3/edit?return_to={$encodedReturn}")
            ->assertOk()
            ->assertSee($returnTo);
        $this->put('/transactions/3', $this->transactionPayload([
            'description' => 'Supermercado atualizado',
            'return_to' => $returnTo,
        ]))->assertRedirect($returnTo);

        $transferReturn = '/transactions?type=transfer&reconciled=no';
        $this->get('/transfers/10/edit?return_to='.urlencode($transferReturn))
            ->assertOk()
            ->assertSee($transferReturn);
        $this->put('/transfers/10', $this->transferPayload(['return_to' => $transferReturn]))
            ->assertRedirect($transferReturn);
    }

    public function test_return_target_is_restricted_to_the_transaction_list(): void
    {
        $this->signInWithFinanceData();

        $this->get('/transactions/3/edit?return_to='.urlencode('https://example.com/steal'))
            ->assertOk()
            ->assertDontSee('name="return_to"', false);
        $this->put('/transactions/3', $this->transactionPayload([
            'return_to' => 'https://example.com/steal',
        ]))->assertRedirect('/transactions/3');
    }

    public function test_reconciliation_is_owner_scoped(): void
    {
        $this->signInWithFinanceData();
        $other = User::factory()->create();
        $transaction = Transaction::query()->findOrFail(3)->replicate();
        $transaction->user_id = $other->id;
        $transaction->save();

        $this->patch("/transactions/{$transaction->id}/reconciliation")
            ->assertNotFound();
        $this->assertFalse($transaction->fresh()->reconciled);
    }

    public function test_only_the_selected_recurring_occurrence_is_created_as_reconciled(): void
    {
        $this->signInWithFinanceData();
        $this->post('/transactions', $this->transactionPayload([
            'date' => now()->toDateString(),
            'reconciled' => '1',
            'recurring' => '1',
            'frequency' => 'monthly',
            'recurrence_end_date' => now()->addMonths(2)->toDateString(),
        ]))->assertSessionHasNoErrors();

        $occurrences = Transaction::query()->whereNotNull('recurring_transaction_id')->orderBy('date')->get();
        $this->assertGreaterThanOrEqual(2, $occurrences->count());
        $this->assertTrue($occurrences->first()->reconciled);
        $this->assertTrue($occurrences->skip(1)->every(fn (Transaction $transaction): bool => ! $transaction->reconciled));
    }

    private function transactionPayload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Despesa conciliável',
            'type' => 'expense',
            'amount' => '25,90',
            'date' => now()->toDateString(),
            'account_id' => 1,
            'category_id' => $this->categoryId('Alimentação'),
            'notes' => '',
            'reconciled' => '0',
        ], $overrides);
    }

    private function transferPayload(array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => 1,
            'destination_account_id' => 2,
            'amount' => '100,00',
            'date' => now()->toDateString(),
            'description' => 'Transferência conciliável',
            'reconciled' => '0',
        ], $overrides);
    }
}
