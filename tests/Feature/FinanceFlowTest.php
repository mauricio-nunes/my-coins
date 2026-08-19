<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinanceFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->withSession(['demo_authenticated' => true]);
    }

    public function test_core_pages_render_in_portuguese(): void
    {
        foreach (['/dashboard', '/transactions', '/accounts', '/categories', '/budgets', '/reports'] as $path) {
            $this->authenticated()->get($path)->assertOk();
        }
    }

    public function test_transaction_can_be_created_filtered_updated_and_deleted(): void
    {
        $payload = [
            'description' => 'Café com amigos', 'type' => 'expense', 'amount' => '25,90',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => 6, 'notes' => '',
        ];
        $this->authenticated()->post('/transactions', $payload)->assertRedirect('/transactions/10');
        $this->get('/transactions?search=Caf%C3%A9')->assertSee('Café com amigos');
        $this->put('/transactions/10', array_merge($payload, ['description' => 'Café atualizado']))->assertRedirect('/transactions/10');
        $this->get('/transactions/10')->assertSee('Café atualizado');
        $this->delete('/transactions/10')->assertRedirect('/transactions');
        $this->get('/transactions/10')->assertNotFound();
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

    public function test_demo_reset_discards_session_mutations(): void
    {
        $this->authenticated()->delete('/transactions/1');
        $this->post('/demo/reset')->assertRedirect('/dashboard');
        $this->get('/transactions/1')->assertOk()->assertSee('Salário mensal');
    }
}
