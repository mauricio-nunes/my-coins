<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DefaultCategories;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') && ! env('PLAYWRIGHT_TEST')) {
            $this->command?->error('BrowserTestSeeder may only run in a test environment.');

            return;
        }

        $user = User::create([
            'name' => 'Maurício',
            'email' => 'owner@mycoins.local',
            'password' => 'Password!234',
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
        $accounts = collect([
            ['name' => 'Conta principal', 'institution' => 'Banco Aurora', 'type' => 'checking', 'color' => '#0f766e', 'opening_balance' => 725000],
            ['name' => 'Reserva', 'institution' => 'Banco Horizonte', 'type' => 'savings', 'color' => '#2563eb', 'opening_balance' => 1850000],
            ['name' => 'Carteira', 'institution' => 'Dinheiro', 'type' => 'cash', 'color' => '#d97706', 'opening_balance' => 18000],
        ])->map(fn (array $attributes): Account => Account::create($attributes + ['user_id' => $user->id]));
        DefaultCategories::createFor($user);
        $categories = Category::query()->where('user_id', $user->id)->get()->keyBy('name');
        $tags = collect(['Essencial', 'Trabalho', 'Fim de semana'])->map(fn (string $name): Tag => Tag::create([
            'user_id' => $user->id,
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
        ]));
        $today = CarbonImmutable::today();
        $rows = [
            ['Salário mensal', 'income', 780000, $today->subDays(12), 0, 'Trabalho', 0],
            ['Aluguel', 'expense', 235000, $today->subDays(10), 0, 'Moradia', 0],
            ['Supermercado Vila', 'expense', 48670, $today->subDays(7), 0, 'Alimentação', 0],
            ['Projeto freelance', 'income', 160000, $today->subDays(6), 1, 'Trabalho', 1],
            ['Combustível', 'expense', 21000, $today->subDays(4), 0, 'Transporte', null],
            ['Cinema', 'expense', 9200, $today->subDays(2), 2, 'Lazer e compras', 2],
            ['Farmácia', 'expense', 13780, $today->subDay(), 0, 'Saúde e cuidados pessoais', 0],
        ];
        foreach ($rows as [$description, $type, $amount, $date, $account, $category, $tag]) {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'description' => $description,
                'type' => $type,
                'amount' => $amount,
                'date' => $date,
                'account_id' => $accounts[$account]->id,
                'category_id' => $categories[$category]->id,
            ]);
            if ($tag !== null) {
                $transaction->tags()->attach($tags[$tag]);
            }
        }
        Transaction::create([
            'user_id' => $user->id,
            'description' => 'Reserva mensal',
            'type' => 'transfer',
            'amount' => 50000,
            'date' => $today->subDays(3),
            'source_account_id' => $accounts[0]->id,
            'destination_account_id' => $accounts[1]->id,
        ]);
        foreach ([['Moradia', 250000], ['Alimentação', 90000], ['Transporte', 45000], ['Lazer e compras', 35000]] as [$category, $limit]) {
            Budget::create([
                'user_id' => $user->id,
                'category_id' => $categories[$category]->id,
                'month' => $today->format('Y-m'),
                'limit' => $limit,
            ]);
        }
    }
}
