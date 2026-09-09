<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceStore;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardDailyCashFlowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_daily_cash_flow_shows_daily_movements_and_accumulates_only_the_balance(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user);
        $primary = $this->account($user, 'Principal', 100000, '2026-07-01');
        $reserve = $this->account($user, 'Reserva', 50000, '2026-07-01');
        $this->account($user, 'Nova conta', 20000, '2026-08-10');
        $archived = $this->account($user, 'Arquivada', 900000, '2026-07-01', true);

        $this->transaction($user, 'income', 10000, '2026-07-31', $primary);
        $this->transaction($user, 'expense', 10000, '2026-08-01', $primary);
        $this->transaction($user, 'income', 30000, '2026-08-02', $primary);
        Transaction::create([
            'user_id' => $user->id,
            'description' => 'Transferência interna',
            'type' => 'transfer',
            'amount' => 5000,
            'date' => '2026-08-03',
            'source_account_id' => $primary->id,
            'destination_account_id' => $reserve->id,
        ]);
        $this->transaction($user, 'expense', 5000, '2026-08-20', $primary);
        $this->transaction($user, 'income', 800000, '2026-08-05', $archived);

        $other = User::factory()->create();
        $otherAccount = $this->account($other, 'Outra pessoa', 700000, '2026-07-01');
        $this->transaction($other, 'income', 600000, '2026-08-04', $otherAccount);

        $points = app(FinanceStore::class)->dailyCashFlow(CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(31, $points);
        $this->assertSame(['date' => '2026-08-01', 'income' => 0, 'expense' => 10000, 'card_statements' => 0, 'balance' => 150000, 'balance_after_cards' => 150000], $points[0]);
        $this->assertSame(['date' => '2026-08-02', 'income' => 30000, 'expense' => 0, 'card_statements' => 0, 'balance' => 180000, 'balance_after_cards' => 180000], $points[1]);
        $this->assertSame(['date' => '2026-08-03', 'income' => 0, 'expense' => 0, 'card_statements' => 0, 'balance' => 180000, 'balance_after_cards' => 180000], $points[2]);
        $this->assertSame(180000, $points->firstWhere('date', '2026-08-03')['balance']);
        $this->assertSame(180000, $points->firstWhere('date', '2026-08-10')['balance']);
        $this->assertSame([
            'date' => '2026-08-20',
            'income' => 0,
            'expense' => 5000,
            'card_statements' => 0,
            'balance' => 175000,
            'balance_after_cards' => 175000,
        ], $points->firstWhere('date', '2026-08-20'));
        $this->assertSame(175000, $points->last()['balance']);
    }

    #[DataProvider('monthLengths')]
    public function test_daily_cash_flow_contains_every_day_of_the_month(string $month, int $expected): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user);
        $this->account($user, 'Principal', 100000, '2020-01-01');

        $points = app(FinanceStore::class)->dailyCashFlow(CarbonImmutable::parse($month.'-15'));

        $this->assertCount($expected, $points);
        $this->assertSame($month.'-01', $points->first()['date']);
        $this->assertSame(CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString(), $points->last()['date']);
        $this->assertTrue($points->every(fn (array $point): bool => $point['income'] === 0 && $point['expense'] === 0));
    }

    public static function monthLengths(): array
    {
        return [
            '28 dias' => ['2026-02', 28],
            '29 dias' => ['2028-02', 29],
            '30 dias' => ['2026-04', 30],
            '31 dias' => ['2026-08', 31],
        ];
    }

    public function test_dashboard_renders_daily_columns_and_the_balance_line(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');
        CarbonImmutable::setTestNow('2026-08-15 12:00:00');
        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user);
        $this->account($user, 'Principal', 100000, '2026-07-01');

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Fluxo de caixa diário')
            ->assertSee('Entradas')
            ->assertSee('saídas registradas')
            ->assertSee('faturas previstas')
            ->assertSee('Saldo em conta')
            ->assertSee('saldo após faturas')
            ->assertSee('Próximas faturas')
            ->assertSee('&quot;type&quot;:&quot;column&quot;', false)
            ->assertSee('&quot;type&quot;:&quot;line&quot;', false)
            ->assertSee('data-apexchart-currency="BRL"', false);
    }

    private function account(User $user, string $name, int $openingBalance, string $openingDate, bool $archived = false): Account
    {
        return Account::create([
            'user_id' => $user->id,
            'name' => $name,
            'institution' => '',
            'type' => 'checking',
            'color' => '#0f766e',
            'opening_balance' => $openingBalance,
            'opening_balance_date' => $openingDate,
            'archived' => $archived,
        ]);
    }

    private function transaction(User $user, string $type, int $amount, string $date, Account $account): Transaction
    {
        return Transaction::create([
            'user_id' => $user->id,
            'description' => "{$type} {$date}",
            'type' => $type,
            'amount' => $amount,
            'date' => $date,
            'account_id' => $account->id,
        ]);
    }
}
