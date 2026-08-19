<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;

class DemoFinanceStore
{
    private const KEY = 'my_coins.demo_data';

    public function __construct(private readonly Store $session) {}

    public function reset(): void
    {
        $this->session->put(self::KEY, $this->fixtures());
    }

    public function all(string $resource): array
    {
        return array_values($this->data()[$resource] ?? []);
    }

    public function find(string $resource, int $id): ?array
    {
        return collect($this->all($resource))->firstWhere('id', $id);
    }

    public function create(string $resource, array $attributes): array
    {
        $data = $this->data();
        $items = $data[$resource];
        $attributes['id'] = collect($items)->max('id') + 1;
        $items[] = $attributes;
        $data[$resource] = $items;
        $this->save($data);

        return $attributes;
    }

    public function update(string $resource, int $id, array $attributes): ?array
    {
        $data = $this->data();
        foreach ($data[$resource] as $index => $item) {
            if ($item['id'] === $id) {
                $data[$resource][$index] = array_merge($item, $attributes, ['id' => $id]);
                $this->save($data);

                return $data[$resource][$index];
            }
        }

        return null;
    }

    public function delete(string $resource, int $id): bool
    {
        $data = $this->data();
        $before = count($data[$resource]);
        $data[$resource] = array_values(array_filter(
            $data[$resource],
            fn (array $item): bool => $item['id'] !== $id,
        ));
        $this->save($data);

        return count($data[$resource]) !== $before;
    }

    public function transactions(array $filters = []): Collection
    {
        $transactions = collect($this->all('transactions'));

        return $transactions
            ->when($filters['search'] ?? null, fn (Collection $items, string $search) => $items->filter(
                fn (array $item): bool => str_contains(mb_strtolower($item['description']), mb_strtolower($search)),
            ))
            ->when($filters['type'] ?? null, fn (Collection $items, string $type) => $items->where('type', $type))
            ->when($filters['account_id'] ?? null, fn (Collection $items, mixed $id) => $items->where('account_id', (int) $id))
            ->when($filters['category_id'] ?? null, fn (Collection $items, mixed $id) => $items->where('category_id', (int) $id))
            ->when($filters['from'] ?? null, fn (Collection $items, string $date) => $items->where('date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Collection $items, string $date) => $items->where('date', '<=', $date))
            ->sortByDesc(fn (array $item) => $item['date'].'-'.$item['id'])
            ->values();
    }

    public function balance(int $accountId): int
    {
        $account = $this->find('accounts', $accountId);
        if (! $account) {
            return 0;
        }

        return (int) $account['opening_balance'] + $this->transactions(['account_id' => $accountId])->sum(
            fn (array $transaction): int => $transaction['type'] === 'income' ? $transaction['amount'] : -$transaction['amount'],
        );
    }

    public function dashboard(): array
    {
        $month = now()->format('Y-m');
        $monthly = $this->transactions()->filter(fn (array $item): bool => str_starts_with($item['date'], $month));
        $income = $monthly->where('type', 'income')->sum('amount');
        $expenses = $monthly->where('type', 'expense')->sum('amount');

        return [
            'balance' => collect($this->all('accounts'))->where('archived', false)->sum(fn (array $account): int => $this->balance($account['id'])),
            'income' => $income,
            'expenses' => $expenses,
            'result' => $income - $expenses,
            'recent' => $this->transactions()->take(6),
        ];
    }

    public function categoryIsUsed(int $id): bool
    {
        return collect($this->all('transactions'))->contains('category_id', $id)
            || collect($this->all('budgets'))->contains('category_id', $id);
    }

    public function budgetSpent(array $budget): int
    {
        return $this->transactions(['category_id' => $budget['category_id']])
            ->where('type', 'expense')
            ->filter(fn (array $item): bool => str_starts_with($item['date'], $budget['month']))
            ->sum('amount');
    }

    public function report(array $filters): array
    {
        $transactions = $this->transactions($filters);
        $income = $transactions->where('type', 'income')->sum('amount');
        $expenses = $transactions->where('type', 'expense')->sum('amount');
        $categories = collect($this->all('categories'))->keyBy('id');
        $byCategory = $transactions->where('type', 'expense')->groupBy('category_id')->map(
            fn (Collection $items, int|string $categoryId): array => [
                'name' => $categories->get((int) $categoryId)['name'] ?? 'Sem categoria',
                'amount' => $items->sum('amount'),
            ],
        )->sortByDesc('amount')->values();
        $byMonth = $transactions->groupBy(fn (array $item): string => substr($item['date'], 0, 7))->map(
            fn (Collection $items, string $month): array => [
                'month' => $month,
                'income' => $items->where('type', 'income')->sum('amount'),
                'expense' => $items->where('type', 'expense')->sum('amount'),
            ],
        )->sortBy('month')->values();

        return compact('transactions', 'income', 'expenses', 'byCategory', 'byMonth') + ['result' => $income - $expenses];
    }

    private function data(): array
    {
        if (! $this->session->has(self::KEY)) {
            $this->reset();
        }

        return $this->session->get(self::KEY);
    }

    private function save(array $data): void
    {
        $this->session->put(self::KEY, $data);
    }

    private function fixtures(): array
    {
        $today = CarbonImmutable::today();
        $date = fn (int $daysAgo): string => $today->subDays($daysAgo)->format('Y-m-d');
        $month = $today->format('Y-m');
        $previousMonth = $today->subMonth()->format('Y-m');

        return [
            'accounts' => [
                ['id' => 1, 'name' => 'Conta principal', 'institution' => 'Banco Aurora', 'type' => 'checking', 'color' => '#0f766e', 'opening_balance' => 725000, 'archived' => false],
                ['id' => 2, 'name' => 'Reserva', 'institution' => 'Banco Horizonte', 'type' => 'savings', 'color' => '#2563eb', 'opening_balance' => 1850000, 'archived' => false],
                ['id' => 3, 'name' => 'Carteira', 'institution' => 'Dinheiro', 'type' => 'cash', 'color' => '#d97706', 'opening_balance' => 18000, 'archived' => false],
            ],
            'categories' => [
                ['id' => 1, 'name' => 'Salário', 'type' => 'income', 'icon' => 'bi-briefcase', 'color' => '#16a34a'],
                ['id' => 2, 'name' => 'Freelance', 'type' => 'income', 'icon' => 'bi-laptop', 'color' => '#0891b2'],
                ['id' => 3, 'name' => 'Moradia', 'type' => 'expense', 'icon' => 'bi-house', 'color' => '#7c3aed'],
                ['id' => 4, 'name' => 'Alimentação', 'type' => 'expense', 'icon' => 'bi-basket', 'color' => '#ea580c'],
                ['id' => 5, 'name' => 'Transporte', 'type' => 'expense', 'icon' => 'bi-car-front', 'color' => '#2563eb'],
                ['id' => 6, 'name' => 'Lazer', 'type' => 'expense', 'icon' => 'bi-controller', 'color' => '#db2777'],
                ['id' => 7, 'name' => 'Saúde', 'type' => 'expense', 'icon' => 'bi-heart-pulse', 'color' => '#dc2626'],
            ],
            'transactions' => [
                ['id' => 1, 'description' => 'Salário mensal', 'type' => 'income', 'amount' => 780000, 'date' => $date(12), 'account_id' => 1, 'category_id' => 1, 'notes' => 'Crédito em conta'],
                ['id' => 2, 'description' => 'Aluguel', 'type' => 'expense', 'amount' => 235000, 'date' => $date(10), 'account_id' => 1, 'category_id' => 3, 'notes' => 'Apartamento'],
                ['id' => 3, 'description' => 'Supermercado Vila', 'type' => 'expense', 'amount' => 48670, 'date' => $date(7), 'account_id' => 1, 'category_id' => 4, 'notes' => 'Compra semanal'],
                ['id' => 4, 'description' => 'Projeto freelance', 'type' => 'income', 'amount' => 160000, 'date' => $date(6), 'account_id' => 2, 'category_id' => 2, 'notes' => 'Landing page'],
                ['id' => 5, 'description' => 'Combustível', 'type' => 'expense', 'amount' => 21000, 'date' => $date(4), 'account_id' => 1, 'category_id' => 5, 'notes' => 'Posto Central'],
                ['id' => 6, 'description' => 'Cinema', 'type' => 'expense', 'amount' => 9200, 'date' => $date(2), 'account_id' => 3, 'category_id' => 6, 'notes' => 'Ingressos e lanche'],
                ['id' => 7, 'description' => 'Farmácia', 'type' => 'expense', 'amount' => 13780, 'date' => $date(1), 'account_id' => 1, 'category_id' => 7, 'notes' => 'Medicamentos'],
                ['id' => 8, 'description' => 'Salário mensal', 'type' => 'income', 'amount' => 780000, 'date' => $today->subMonth()->day(5)->format('Y-m-d'), 'account_id' => 1, 'category_id' => 1, 'notes' => 'Crédito em conta'],
                ['id' => 9, 'description' => 'Aluguel', 'type' => 'expense', 'amount' => 235000, 'date' => $today->subMonth()->day(7)->format('Y-m-d'), 'account_id' => 1, 'category_id' => 3, 'notes' => 'Apartamento'],
            ],
            'budgets' => [
                ['id' => 1, 'category_id' => 3, 'month' => $month, 'limit' => 250000],
                ['id' => 2, 'category_id' => 4, 'month' => $month, 'limit' => 90000],
                ['id' => 3, 'category_id' => 5, 'month' => $month, 'limit' => 45000],
                ['id' => 4, 'category_id' => 6, 'month' => $month, 'limit' => 35000],
                ['id' => 5, 'category_id' => 4, 'month' => $previousMonth, 'limit' => 85000],
            ],
        ];
    }
}
