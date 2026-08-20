<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class FinanceFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->signInWithFinanceData();
    }

    public function test_core_pages_render_in_portuguese(): void
    {
        foreach (['/dashboard', '/transactions', '/transfers/create', '/accounts', '/categories', '/tags', '/budgets', '/reports'] as $path) {
            $this->authenticated()->get($path)->assertOk();
        }
    }

    public function test_transaction_can_be_created_filtered_updated_and_deleted(): void
    {
        $payload = [
            'description' => 'Café com amigos', 'type' => 'expense', 'amount' => '25,90',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => 6, 'notes' => '',
        ];
        $this->authenticated()->post('/transactions', $payload)->assertRedirect('/transactions/11');
        $this->get('/transactions?search=Caf%C3%A9')->assertSee('Café com amigos');
        $this->put('/transactions/11', array_merge($payload, ['description' => 'Café atualizado']))->assertRedirect('/transactions/11');
        $this->get('/transactions/11')->assertSee('Café atualizado');
        $this->delete('/transactions/11')->assertRedirect('/transactions');
        $this->get('/transactions/11')->assertNotFound();
        $this->assertSoftDeleted('transactions', ['id' => 11, 'description' => 'Café atualizado']);
    }

    public function test_transaction_rejects_a_category_from_the_wrong_type(): void
    {
        $this->authenticated()->post('/transactions', [
            'description' => 'Inválida', 'type' => 'income', 'amount' => '10,00',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => 4,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_used_category_cannot_be_deleted(): void
    {
        $this->authenticated()->delete('/categories/4')->assertSessionHas('warning');
        $this->get('/categories')->assertSee('Alimentação');
    }

    public function test_used_category_cannot_change_transaction_type(): void
    {
        $this->authenticated()->put('/categories/4', [
            'name' => 'Alimentação', 'type' => 'income', 'icon' => 'bi-basket', 'color' => '#ea580c',
        ])->assertSessionHasErrors('type');
    }

    public function test_account_is_archived_without_losing_its_ledger(): void
    {
        $this->authenticated()->delete('/accounts/1')->assertRedirect('/accounts');
        $this->get('/accounts/1')->assertOk()->assertSee('Arquivada');
        $this->get('/transactions/create')->assertDontSee('Conta principal · Banco Aurora');
    }

    public function test_financial_data_persists_after_logout_and_login(): void
    {
        $this->authenticated()->post('/transactions', [
            'description' => 'Registro persistente', 'type' => 'expense', 'amount' => '10,00',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => 4,
        ]);
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['email' => 'owner@mycoins.local', 'password' => 'Password!234'])->assertRedirect('/dashboard');
        $this->get('/transactions?search=Registro%20persistente')->assertSee('Registro persistente');
    }

    public function test_owner_cannot_read_or_reference_another_users_account(): void
    {
        $this->authenticated();
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $otherAccount = Account::create([
            'user_id' => $otherUser->id,
            'name' => 'Conta de outra pessoa',
            'type' => 'checking',
            'color' => '#111827',
            'opening_balance' => 10000,
        ]);

        $this->get("/accounts/{$otherAccount->id}")->assertNotFound();
        $this->post('/transactions', [
            'description' => 'Tentativa indevida', 'type' => 'expense', 'amount' => '10,00',
            'date' => now()->format('Y-m-d'), 'account_id' => $otherAccount->id, 'category_id' => 4,
        ])->assertSessionHasErrors('account_id');
        $this->assertFalse(Transaction::query()->where('description', 'Tentativa indevida')->exists());
    }
}
