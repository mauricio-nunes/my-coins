<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CardInstallment;
use App\Models\CardPurchase;
use App\Models\CardStatement;
use App\Models\CreditCard;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CreditCardManagementTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_card_purchase_generates_installments_in_statements_and_budget_expenses(): void
    {
        $this->freeze('2026-09-15');
        $this->signInWithFinanceData();
        $accountId = Account::query()->where('type', 'checking')->value('id');
        $categoryId = $this->categoryId('Alimentação');

        $this->post('/credit-cards', [
            'name' => 'Nubank',
            'network' => 'Mastercard',
            'credit_limit' => '10000,00',
            'closing_day' => 10,
            'due_day' => 17,
            'default_payment_account_id' => $accountId,
            'color' => '#6f42c1',
        ])->assertRedirect();
        $card = CreditCard::firstOrFail();
        $this->get('/credit-cards')->assertOk()->assertSee('Nubank');
        $this->get('/credit-cards/create')->assertOk()->assertSee('Novo cartão');
        $this->get("/credit-cards/{$card->id}/edit")->assertOk()->assertSee('Editar cartão');
        $this->get("/credit-cards/{$card->id}/purchases/create")->assertOk()->assertSee('Nova compra');

        $this->post("/credit-cards/{$card->id}/purchases", [
            'description' => 'Notebook',
            'purchase_date' => '2026-08-11',
            'total_amount' => '1200,00',
            'category_id' => $categoryId,
            'installments_count' => 6,
        ])->assertRedirect();

        $purchase = CardPurchase::firstOrFail();
        $this->assertSame(6, CardInstallment::query()->where('card_purchase_id', $purchase->id)->count());
        $this->assertSame([20000, 20000, 20000, 20000, 20000, 20000], CardInstallment::query()->orderBy('installment_number')->pluck('amount')->all());
        $this->assertDatabaseHas('card_statements', ['credit_card_id' => $card->id, 'month' => '2026-09', 'closing_date' => '2026-09-10', 'due_date' => '2026-09-17']);
        $this->assertDatabaseHas('transactions', ['description' => 'Notebook (1/6)', 'type' => 'expense', 'amount' => 20000, 'date' => '2026-09-10']);
        $this->assertSame(120000, app(FinanceStore::class)->cardUsedLimit($card->id));

        $budget = app(FinanceStore::class)->budgetsForMonth('2026-09')->firstWhere('name', 'Alimentação');
        $this->assertSame(68670, $budget['spent']);
        $this->get("/credit-cards/{$card->id}?statement=2026-09")->assertOk()->assertSee('Notebook')->assertSee('1/6');
    }

    public function test_statement_payment_is_a_transfer_releases_limit_and_is_not_an_expense(): void
    {
        $this->freeze('2026-09-15');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $accountId = Account::query()->where('type', 'checking')->value('id');
        $card = $store->createCreditCard([
            'name' => 'Inter', 'network' => 'Visa', 'credit_limit' => 500000, 'closing_day' => 10,
            'due_day' => 17, 'default_payment_account_id' => $accountId, 'color' => '#ff7a00',
        ]);
        $store->createCardPurchase($card['id'], [
            'description' => 'Mercado', 'purchase_date' => '2026-09-01', 'total_amount' => 60000,
            'category_id' => $this->categoryId('Alimentação'), 'installments_count' => 1,
        ]);
        $statement = CardStatement::query()->where('month', '2026-09')->firstOrFail();

        $this->post("/card-statements/{$statement->id}/payments", [
            'source_account_id' => $accountId,
            'amount' => '300,00',
            'payment_date' => '2026-09-15',
        ])->assertRedirect();

        $paymentTransaction = Transaction::query()->where('description', 'like', 'Pagamento da fatura%')->firstOrFail();
        $this->assertSame('transfer', $paymentTransaction->type);
        $this->assertSame(30000, $paymentTransaction->amount);
        $this->assertSame(30000, $store->cardUsedLimit($card['id']));
        $this->assertSame(60000, (int) Transaction::query()->whereNotNull('card_installment_id')->where('type', 'expense')->sum('amount'));
        $cashFlow = $store->dailyCashFlow(CarbonImmutable::parse('2026-09-15'));
        $this->assertSame(30000, $cashFlow->firstWhere('date', '2026-09-15')['expense']);
        $this->assertSame(30000, $cashFlow->firstWhere('date', '2026-09-17')['card_statements']);
        $this->assertSame(
            $cashFlow->firstWhere('date', '2026-09-17')['balance'] - 30000,
            $cashFlow->firstWhere('date', '2026-09-17')['balance_after_cards'],
        );
        $this->assertSame(30000, $store->cashFlowByMonth()->firstWhere('month', '2026-09')['card_statements']);
        $this->assertSame(30000, $store->cardCommitmentSummary()['total']);
        $this->assertSame('partially_paid', $store->cardStatement($statement->id)['status']);
        $this->get('/dashboard')->assertOk()->assertSee('Próximas faturas')->assertSee('Inter')->assertSee('R$ 300,00');
    }

    public function test_card_resources_are_owner_scoped_and_generated_transactions_cannot_be_edited_directly(): void
    {
        $this->freeze('2026-09-01');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $accountId = Account::query()->where('type', 'checking')->value('id');
        $card = $store->createCreditCard([
            'name' => 'Meu cartão', 'network' => 'Elo', 'credit_limit' => 100000, 'closing_day' => 20,
            'due_day' => 27, 'default_payment_account_id' => $accountId, 'color' => '#123456',
        ]);
        $purchase = $store->createCardPurchase($card['id'], [
            'description' => 'Teste', 'purchase_date' => '2026-09-01', 'total_amount' => 10000,
            'category_id' => $this->categoryId('Outros'), 'installments_count' => 1,
        ]);
        $transactionId = $purchase['installments'][0]['transaction_id'];

        $this->get("/transactions/{$transactionId}/edit")->assertRedirect("/card-purchases/{$purchase['id']}/edit");
        $this->delete("/transactions/{$transactionId}")->assertRedirect("/transactions/{$transactionId}");

        $other = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($other)->get("/credit-cards/{$card['id']}")->assertNotFound();
    }

    public function test_overdue_statement_is_projected_today_and_full_payment_replaces_the_projection(): void
    {
        $this->freeze('2026-09-20');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $accountId = Account::query()->where('type', 'checking')->value('id');
        $card = $store->createCreditCard([
            'name' => 'Cartão vencido', 'network' => 'Visa', 'credit_limit' => 100000, 'closing_day' => 10,
            'due_day' => 17, 'default_payment_account_id' => $accountId, 'color' => '#dc2626',
        ]);
        $store->createCardPurchase($card['id'], [
            'description' => 'Compra vencida', 'purchase_date' => '2026-09-01', 'total_amount' => 60000,
            'category_id' => $this->categoryId('Outros'), 'installments_count' => 1,
        ]);
        $statement = CardStatement::query()->where('credit_card_id', $card['id'])->firstOrFail();

        $beforePayment = $store->dailyCashFlow(CarbonImmutable::parse('2026-09-20'));
        $this->assertSame(0, $beforePayment->firstWhere('date', '2026-09-17')['card_statements']);
        $this->assertSame(60000, $beforePayment->firstWhere('date', '2026-09-20')['card_statements']);
        $this->assertTrue($store->cardCommitmentSummary()['items']->first()['overdue']);

        $store->payCardStatement($statement->id, $accountId, 60000, '2026-09-20');
        $afterPayment = $store->dailyCashFlow(CarbonImmutable::parse('2026-09-20'));
        $today = $afterPayment->firstWhere('date', '2026-09-20');
        $this->assertSame(60000, $today['expense']);
        $this->assertSame(0, $today['card_statements']);
        $this->assertSame($today['balance'], $today['balance_after_cards']);
        $this->assertSame(0, $store->cardCommitmentSummary()['total']);
    }

    public function test_closing_and_due_days_are_clamped_and_open_purchase_can_be_recalculated(): void
    {
        $this->freeze('2026-09-01');
        $this->signInWithFinanceData();
        $store = app(FinanceStore::class);
        $accountId = Account::query()->where('type', 'checking')->value('id');
        $card = $store->createCreditCard([
            'name' => 'Dia 31', 'network' => 'Visa', 'credit_limit' => 100000, 'closing_day' => 31,
            'due_day' => 31, 'default_payment_account_id' => $accountId, 'color' => '#654321',
        ]);
        $purchase = $store->createCardPurchase($card['id'], [
            'description' => 'Compra ajustável', 'purchase_date' => '2026-09-30', 'total_amount' => 10001,
            'category_id' => $this->categoryId('Outros'), 'installments_count' => 3,
        ]);

        $this->assertSame('2026-09', $purchase['installments'][0]['statement_month']);
        $this->assertSame('2026-09-30', $purchase['installments'][0]['closing_date']);
        $updated = $store->updateCardPurchase($purchase['id'], [
            'description' => 'Compra recalculada', 'purchase_date' => '2026-09-30', 'total_amount' => 12000,
            'category_id' => $this->categoryId('Outros'), 'installments_count' => 2,
        ]);

        $this->assertCount(2, $updated['installments']);
        $this->assertSame([6000, 6000], collect($updated['installments'])->pluck('amount')->all());
        $this->assertSame(2, Transaction::query()->whereNotNull('card_installment_id')->count());
    }

    private function freeze(string $date): void
    {
        Carbon::setTestNow($date);
        CarbonImmutable::setTestNow($date);
    }
}
