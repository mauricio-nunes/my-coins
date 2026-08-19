<?php

namespace Tests\Feature;

use App\Services\DemoFinanceStore;
use Tests\TestCase;

class TransferFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->withSession(['demo_authenticated' => true]);
    }

    private function store(): DemoFinanceStore
    {
        return $this->app->make(DemoFinanceStore::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => 1,
            'destination_account_id' => 2,
            'amount' => '100,00',
            'date' => now()->format('Y-m-d'),
            'description' => 'Aporte na reserva',
        ], $overrides);
    }

    public function test_transfer_is_stored_once_and_moves_money_between_accounts(): void
    {
        $this->authenticated()->get('/transfers/create')->assertOk();
        $sourceBefore = $this->store()->balance(1);
        $destinationBefore = $this->store()->balance(2);

        $this->post('/transfers', $this->payload())->assertRedirect('/transactions/11');

        $transfer = $this->store()->find('transactions', 11);
        $this->assertSame('transfer', $transfer['type']);
        $this->assertSame(10000, $transfer['amount']);
        $this->assertNull($transfer['account_id']);
        $this->assertNull($transfer['category_id']);
        $this->assertSame([], $transfer['tag_ids']);
        $this->assertSame($sourceBefore - 10000, $this->store()->balance(1));
        $this->assertSame($destinationBefore + 10000, $this->store()->balance(2));
        $this->get('/transactions/11')->assertOk()->assertSee('Aporte na reserva')->assertSee('Conta de origem')->assertSee('Conta de destino');
    }

    public function test_future_transfer_does_not_change_current_balances(): void
    {
        $this->authenticated()->get('/dashboard');
        $sourceBefore = $this->store()->balance(1);
        $destinationBefore = $this->store()->balance(2);

        $this->post('/transfers', $this->payload(['date' => now()->addDay()->format('Y-m-d')]));

        $this->assertSame($sourceBefore, $this->store()->balance(1));
        $this->assertSame($destinationBefore, $this->store()->balance(2));
        $this->get('/transactions/11')->assertOk()->assertSee('Aporte na reserva');
    }

    public function test_future_income_and_expense_do_not_change_current_balance(): void
    {
        $this->authenticated()->get('/dashboard');
        $before = $this->store()->balance(1);
        $base = [
            'amount' => '50,00', 'date' => now()->addDay()->format('Y-m-d'),
            'account_id' => 1, 'notes' => '',
        ];

        $this->post('/transactions', $base + ['description' => 'Receita futura', 'type' => 'income', 'category_id' => 1]);
        $this->post('/transactions', $base + ['description' => 'Despesa futura', 'type' => 'expense', 'category_id' => 4]);

        $this->assertSame($before, $this->store()->balance(1));
    }

    public function test_transfer_rejects_same_or_archived_accounts(): void
    {
        $this->authenticated()->post('/transfers', $this->payload(['destination_account_id' => 1]))
            ->assertSessionHasErrors('source_account_id');

        $this->delete('/accounts/2');
        $this->post('/transfers', $this->payload())
            ->assertSessionHasErrors('destination_account_id');
    }

    public function test_transfer_can_be_edited_and_deleted_with_balances_recalculated(): void
    {
        $this->authenticated()->get('/dashboard');
        $sourceBefore = $this->store()->balance(1);
        $oldDestinationBefore = $this->store()->balance(2);
        $newDestinationBefore = $this->store()->balance(3);

        $this->put('/transfers/10', $this->payload([
            'destination_account_id' => 3,
            'amount' => '300,00',
            'description' => '',
        ]))->assertRedirect('/transactions/10');

        $this->assertSame($sourceBefore + 20000, $this->store()->balance(1));
        $this->assertSame($oldDestinationBefore - 50000, $this->store()->balance(2));
        $this->assertSame($newDestinationBefore + 30000, $this->store()->balance(3));
        $this->get('/transactions/10')->assertSee('Transferência de Conta principal para Carteira');

        $this->delete('/transactions/10')->assertRedirect('/transactions');
        $this->assertSame($sourceBefore + 50000, $this->store()->balance(1));
        $this->assertSame($newDestinationBefore, $this->store()->balance(3));
        $this->assertNull($this->store()->find('transactions', 10));
    }

    public function test_transfer_is_visible_from_both_accounts_and_filters(): void
    {
        $this->authenticated()->get('/accounts/1')->assertOk()->assertSee('Transferência enviada para Reserva');
        $this->get('/accounts/2')->assertOk()->assertSee('Transferência recebida de Conta principal');
        $this->get('/transactions?type=transfer&account_id=2')
            ->assertOk()
            ->assertSee('Reserva mensal')
            ->assertDontSee('Salário mensal');
    }

    public function test_transfer_does_not_change_income_expense_budget_or_report_totals(): void
    {
        $this->authenticated()->get('/dashboard');
        $dashboardBefore = $this->store()->dashboard();
        $reportBefore = $this->store()->report([]);
        $budget = $this->store()->find('budgets', 3);
        $budgetBefore = $this->store()->budgetSpent($budget);

        $this->post('/transfers', $this->payload(['amount' => '999,00']));

        $dashboardAfter = $this->store()->dashboard();
        $reportAfter = $this->store()->report([]);
        $this->assertSame($dashboardBefore['income'], $dashboardAfter['income']);
        $this->assertSame($dashboardBefore['expenses'], $dashboardAfter['expenses']);
        $this->assertSame($dashboardBefore['result'], $dashboardAfter['result']);
        $this->assertSame($reportBefore['income'], $reportAfter['income']);
        $this->assertSame($reportBefore['expenses'], $reportAfter['expenses']);
        $this->assertSame($budgetBefore, $this->store()->budgetSpent($budget));
    }

    public function test_existing_archived_accounts_remain_visible_when_editing_transfer(): void
    {
        $this->authenticated()->delete('/accounts/1');

        $this->get('/transfers/10/edit')->assertOk()->assertSee('Conta principal · Banco Aurora (Arquivada)');
        $this->put('/transfers/10', $this->payload(['amount' => '75,00']))
            ->assertRedirect('/transactions/10');
    }
}
