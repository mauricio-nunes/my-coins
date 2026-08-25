<?php

namespace Tests;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DefaultCategories;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;

    protected function signInWithFinanceData(): static
    {
        $user = User::query()->first() ?? User::factory()->create([
            'name' => 'Maurício',
            'email' => 'owner@mycoins.local',
            'password' => 'Password!234',
            'must_change_password' => false,
        ]);
        if (! Account::query()->where('user_id', $user->id)->exists()) {
            $this->createFinanceData($user);
        }

        return $this->actingAs($user);
    }

    protected function createFinanceData(User $user): void
    {
        $today = CarbonImmutable::today();
        $date = fn (int $daysAgo): string => $today->subDays($daysAgo)->format('Y-m-d');
        $openingBalanceDate = $today->subMonth()->startOfMonth()->format('Y-m-d');
        $accounts = [
            ['name' => 'Conta principal', 'institution' => 'Banco Aurora', 'type' => 'checking', 'color' => '#0f766e', 'opening_balance' => 725000, 'opening_balance_date' => $openingBalanceDate],
            ['name' => 'Reserva', 'institution' => 'Banco Horizonte', 'type' => 'savings', 'color' => '#2563eb', 'opening_balance' => 1850000, 'opening_balance_date' => $openingBalanceDate],
            ['name' => 'Carteira', 'institution' => 'Dinheiro', 'type' => 'cash', 'color' => '#d97706', 'opening_balance' => 18000, 'opening_balance_date' => $openingBalanceDate],
        ];
        foreach ($accounts as $account) {
            Account::create($account + ['user_id' => $user->id, 'archived' => false]);
        }
        DefaultCategories::createFor($user);
        $categoryIds = Category::query()->where('user_id', $user->id)->pluck('id', 'name');
        foreach (['Essencial', 'Trabalho', 'Fim de semana'] as $name) {
            Tag::create(['user_id' => $user->id, 'name' => $name, 'normalized_name' => mb_strtolower($name)]);
        }
        $transactions = [
            ['description' => 'Salário mensal', 'type' => 'income', 'amount' => 780000, 'date' => $date(12), 'account_id' => 1, 'category_id' => $categoryIds['Trabalho'], 'notes' => 'Crédito em conta', 'tag_ids' => [1]],
            ['description' => 'Aluguel', 'type' => 'expense', 'amount' => 235000, 'date' => $date(10), 'account_id' => 1, 'category_id' => $categoryIds['Moradia'], 'notes' => 'Apartamento', 'tag_ids' => [1]],
            ['description' => 'Supermercado Vila', 'type' => 'expense', 'amount' => 48670, 'date' => $date(7), 'account_id' => 1, 'category_id' => $categoryIds['Alimentação'], 'notes' => 'Compra semanal', 'tag_ids' => [1]],
            ['description' => 'Projeto freelance', 'type' => 'income', 'amount' => 160000, 'date' => $date(6), 'account_id' => 2, 'category_id' => $categoryIds['Trabalho'], 'notes' => 'Landing page', 'tag_ids' => [2]],
            ['description' => 'Combustível', 'type' => 'expense', 'amount' => 21000, 'date' => $date(4), 'account_id' => 1, 'category_id' => $categoryIds['Transporte'], 'notes' => 'Posto Central', 'tag_ids' => []],
            ['description' => 'Cinema', 'type' => 'expense', 'amount' => 9200, 'date' => $date(2), 'account_id' => 3, 'category_id' => $categoryIds['Lazer e compras'], 'notes' => 'Ingressos e lanche', 'tag_ids' => [3]],
            ['description' => 'Farmácia', 'type' => 'expense', 'amount' => 13780, 'date' => $date(1), 'account_id' => 1, 'category_id' => $categoryIds['Saúde e cuidados pessoais'], 'notes' => 'Medicamentos', 'tag_ids' => [1]],
            ['description' => 'Salário mensal', 'type' => 'income', 'amount' => 780000, 'date' => $today->subMonth()->day(5)->format('Y-m-d'), 'account_id' => 1, 'category_id' => $categoryIds['Trabalho'], 'notes' => 'Crédito em conta', 'tag_ids' => [1]],
            ['description' => 'Aluguel', 'type' => 'expense', 'amount' => 235000, 'date' => $today->subMonth()->day(7)->format('Y-m-d'), 'account_id' => 1, 'category_id' => $categoryIds['Moradia'], 'notes' => 'Apartamento', 'tag_ids' => [1]],
            ['description' => 'Reserva mensal', 'type' => 'transfer', 'amount' => 50000, 'date' => $date(3), 'source_account_id' => 1, 'destination_account_id' => 2, 'notes' => '', 'tag_ids' => []],
        ];
        foreach ($transactions as $attributes) {
            $tags = $attributes['tag_ids'];
            unset($attributes['tag_ids']);
            $transaction = Transaction::create($attributes + ['user_id' => $user->id]);
            $transaction->tags()->sync($tags);
        }
        foreach ([['Moradia', 250000], ['Alimentação', 90000], ['Transporte', 45000], ['Lazer e compras', 35000]] as [$category, $limit]) {
            Budget::create(['user_id' => $user->id, 'category_id' => $categoryIds[$category], 'month' => $today->format('Y-m'), 'limit' => $limit]);
        }
        Budget::create(['user_id' => $user->id, 'category_id' => $categoryIds['Alimentação'], 'month' => $today->subMonth()->format('Y-m'), 'limit' => 85000]);
    }

    protected function categoryId(string $name): int
    {
        return Category::query()->where('name', $name)->value('id') ?? throw new \RuntimeException("Categoria {$name} não encontrada.");
    }
}
