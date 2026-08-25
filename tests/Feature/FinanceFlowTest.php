<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
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

    public function test_category_form_offers_the_expanded_icon_catalog(): void
    {
        $this->authenticated()->get('/categories/create')
            ->assertOk()
            ->assertSee('value="bi-mortarboard"', false)
            ->assertSee('value="bi-graph-up-arrow"', false)
            ->assertSee('value="bi-cash-coin"', false);
    }

    public function test_transaction_can_be_created_filtered_updated_and_deleted(): void
    {
        $this->authenticated();
        $payload = [
            'description' => 'Café com amigos', 'type' => 'expense', 'amount' => '25,90',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => $this->categoryId('Lazer e compras'), 'notes' => '',
        ];
        $this->post('/transactions', $payload)->assertRedirect('/transactions/11');
        $this->get('/transactions?search=Caf%C3%A9')->assertSee('Café com amigos');
        $this->put('/transactions/11', array_merge($payload, ['description' => 'Café atualizado']))->assertRedirect('/transactions/11');
        $this->get('/transactions/11')->assertSee('Café atualizado');
        $this->delete('/transactions/11')->assertRedirect('/transactions');
        $this->get('/transactions/11')->assertNotFound();
        $this->assertSoftDeleted('transactions', ['id' => 11, 'description' => 'Café atualizado']);
    }

    public function test_transactions_can_be_filtered_by_category(): void
    {
        $this->authenticated();
        $categoryId = $this->categoryId('Alimentação');

        $this->get('/transactions?'.http_build_query(['category_id' => $categoryId]))
            ->assertOk()
            ->assertSee('Supermercado Vila')
            ->assertDontSee('Aluguel')
            ->assertDontSee('Reserva mensal')
            ->assertSee("value=\"{$categoryId}\" selected", false);
    }

    public function test_category_filter_combines_with_other_transaction_filters(): void
    {
        $this->authenticated();
        $query = http_build_query([
            'type' => 'income',
            'account_id' => 2,
            'category_id' => $this->categoryId('Trabalho'),
            'from' => now()->subDays(7)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
            'tags' => [2],
        ]);

        $this->get("/transactions?{$query}")
            ->assertOk()
            ->assertSee('Projeto freelance')
            ->assertDontSee('Salário mensal')
            ->assertDontSee('Reserva mensal');
    }

    public function test_transaction_rejects_a_category_from_the_wrong_type(): void
    {
        $this->authenticated();
        $this->post('/transactions', [
            'description' => 'Inválida', 'type' => 'income', 'amount' => '10,00',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => $this->categoryId('Alimentação'),
        ])->assertSessionHasErrors('category_id');
    }

    public function test_used_category_cannot_be_deleted(): void
    {
        $this->authenticated();
        $categoryId = $this->categoryId('Alimentação');
        $this->delete("/categories/{$categoryId}")->assertSessionHas('warning');
        $this->get('/categories')->assertSee('Alimentação');
    }

    public function test_used_category_cannot_change_transaction_type(): void
    {
        $this->authenticated();
        $categoryId = $this->categoryId('Alimentação');
        $this->put("/categories/{$categoryId}", [
            'name' => 'Alimentação', 'type' => 'income', 'icon' => 'bi-basket', 'color' => '#ea580c',
        ])->assertSessionHasErrors('type');
    }

    public function test_account_is_archived_without_losing_its_ledger(): void
    {
        $this->authenticated()->delete('/accounts/1')->assertRedirect('/accounts');
        $this->get('/accounts/1')->assertOk()->assertSee('Arquivada');
        $this->get('/transactions/create')->assertDontSee('Conta principal · Banco Aurora');
    }

    public function test_account_requires_and_persists_the_opening_balance_date(): void
    {
        $this->authenticated()->get('/accounts/create')
            ->assertOk()
            ->assertSee('Data do saldo inicial')
            ->assertSee('O saldo informado representa o início deste dia.')
            ->assertSee('os saldos, a evolução e os indicadores podem não ser apresentados corretamente');

        $payload = [
            'name' => 'Conta com marco',
            'institution' => 'Banco Exemplo',
            'type' => 'checking',
            'color' => '#0f766e',
            'opening_balance' => '1250,75',
        ];
        $this->post('/accounts', $payload)->assertSessionHasErrors('opening_balance_date');

        $date = now()->subDays(15)->toDateString();
        $this->post('/accounts', $payload + ['opening_balance_date' => $date])->assertSessionHasNoErrors();
        $account = Account::query()->where('name', 'Conta com marco')->firstOrFail();

        $this->assertSame($date, $account->opening_balance_date->toDateString());
        $this->assertSame(125075, $account->opening_balance);
        $this->get("/accounts/{$account->id}")
            ->assertOk()
            ->assertSee('Saldo inicial de')
            ->assertSee(now()->subDays(15)->format('d/m/Y'));
    }

    public function test_balance_uses_the_opening_date_but_earlier_transactions_remain_allowed(): void
    {
        $this->authenticated();
        $anchor = now()->subDays(2);
        $account = Account::create([
            'user_id' => auth()->id(),
            'name' => 'Conta temporal',
            'institution' => '',
            'type' => 'checking',
            'color' => '#2563eb',
            'opening_balance' => 10000,
            'opening_balance_date' => $anchor->toDateString(),
        ]);
        $base = ['account_id' => $account->id, 'notes' => ''];

        $this->post('/transactions', $base + [
            'description' => 'Receita anterior', 'type' => 'income', 'amount' => '50,00',
            'date' => $anchor->copy()->subDay()->toDateString(), 'category_id' => $this->categoryId('Trabalho'),
        ])->assertSessionHasNoErrors();
        $this->post('/transactions', $base + [
            'description' => 'Receita no marco', 'type' => 'income', 'amount' => '20,00',
            'date' => $anchor->toDateString(), 'category_id' => $this->categoryId('Trabalho'),
        ])->assertSessionHasNoErrors();
        $this->post('/transactions', $base + [
            'description' => 'Despesa posterior', 'type' => 'expense', 'amount' => '10,00',
            'date' => $anchor->copy()->addDay()->toDateString(), 'category_id' => $this->categoryId('Alimentação'),
        ])->assertSessionHasNoErrors();

        $store = app(FinanceStore::class);
        $this->assertSame(0, $store->balanceAt($account->id, $anchor->copy()->subDay()->toDateString()));
        $this->assertSame(12000, $store->balanceAt($account->id, $anchor->toDateString()));
        $this->assertSame(11000, $store->balance($account->id));
        $this->get("/accounts/{$account->id}")
            ->assertOk()
            ->assertSee('possui lançamentos anteriores à data do saldo inicial');

        $this->put("/accounts/{$account->id}", [
            'name' => $account->name,
            'institution' => '',
            'type' => $account->type,
            'color' => $account->color,
            'opening_balance' => '100,00',
            'opening_balance_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'opening_balance_date' => now()->toDateString()]);
    }

    public function test_financial_data_persists_after_logout_and_login(): void
    {
        $this->authenticated();
        $this->post('/transactions', [
            'description' => 'Registro persistente', 'type' => 'expense', 'amount' => '10,00',
            'date' => now()->format('Y-m-d'), 'account_id' => 1, 'category_id' => $this->categoryId('Alimentação'),
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
            'opening_balance_date' => now()->toDateString(),
        ]);

        $this->get("/accounts/{$otherAccount->id}")->assertNotFound();
        $this->post('/transactions', [
            'description' => 'Tentativa indevida', 'type' => 'expense', 'amount' => '10,00',
            'date' => now()->format('Y-m-d'), 'account_id' => $otherAccount->id, 'category_id' => $this->categoryId('Alimentação'),
        ])->assertSessionHasErrors('account_id');
        $this->assertFalse(Transaction::query()->where('description', 'Tentativa indevida')->exists());
    }
}
