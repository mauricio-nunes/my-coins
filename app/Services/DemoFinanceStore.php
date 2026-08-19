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

    public function hasImportedOfxTransaction(int $accountId, string $fitId): bool
    {
        return collect($this->all('transactions'))->contains(
            fn (array $transaction): bool => ($transaction['ofx_account_id'] ?? null) === $accountId
                && ($transaction['ofx_fitid'] ?? null) === $fitId,
        );
    }

    public function importOfxTransactions(int $accountId, string $label, array $transactions): array
    {
        $data = $this->data();
        $displayLabel = $this->cleanTagName($label);
        $normalizedLabel = $this->normalizeTagName($displayLabel);
        $tag = collect($data['tags'])->firstWhere('normalized_name', $normalizedLabel);

        if (! $tag) {
            $tag = [
                'id' => (int) collect($data['tags'])->max('id') + 1,
                'name' => $displayLabel,
                'normalized_name' => $normalizedLabel,
            ];
            $data['tags'][] = $tag;
        }

        $knownKeys = collect($data['transactions'])->mapWithKeys(
            fn (array $transaction): array => isset($transaction['ofx_account_id'], $transaction['ofx_fitid'])
                ? [$transaction['ofx_account_id'].'|'.$transaction['ofx_fitid'] => true]
                : [],
        )->all();
        $nextId = (int) collect($data['transactions'])->max('id') + 1;
        $imported = [];
        $duplicates = 0;

        foreach ($transactions as $transaction) {
            $key = $accountId.'|'.$transaction['ofx_fitid'];
            if (isset($knownKeys[$key])) {
                $duplicates++;

                continue;
            }

            $transaction['id'] = $nextId++;
            $transaction['tag_ids'] = [$tag['id']];
            $data['transactions'][] = $transaction;
            $imported[] = $transaction;
            $knownKeys[$key] = true;
        }

        $this->save($data);

        return ['tag' => $tag, 'transactions' => $imported, 'duplicates' => $duplicates];
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

        $tags = collect($this->all('tags'))->keyBy('id');
        $accounts = collect($this->all('accounts'))->keyBy('id');

        return $transactions
            ->when($filters['search'] ?? null, function (Collection $items, string $search) use ($accounts, $tags): Collection {
                $needle = mb_strtolower($search);

                return $items->filter(function (array $item) use ($accounts, $needle, $tags): bool {
                    $tagNames = collect($item['tag_ids'] ?? [])->map(
                        fn (int $id): string => mb_strtolower($tags->get($id)['name'] ?? ''),
                    );
                    $accountNames = $item['type'] === 'transfer'
                        ? collect([$item['source_account_id'], $item['destination_account_id']])->map(
                            fn (int $id): string => mb_strtolower($accounts->get($id)['name'] ?? ''),
                        )
                        : collect();

                    return str_contains(mb_strtolower($item['description'] ?? ''), $needle)
                        || $tagNames->contains(fn (string $name): bool => str_contains($name, $needle))
                        || $accountNames->contains(fn (string $name): bool => str_contains($name, $needle));
                });
            })
            ->when($filters['type'] ?? null, fn (Collection $items, string $type) => $items->where('type', $type))
            ->when($filters['account_id'] ?? null, function (Collection $items, mixed $id): Collection {
                $accountId = (int) $id;

                return $items->filter(fn (array $item): bool => $item['type'] === 'transfer'
                    ? $item['source_account_id'] === $accountId || $item['destination_account_id'] === $accountId
                    : $item['account_id'] === $accountId);
            })
            ->when($filters['category_id'] ?? null, fn (Collection $items, mixed $id) => $items->where('category_id', (int) $id))
            ->when($filters['from'] ?? null, fn (Collection $items, string $date) => $items->where('date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Collection $items, string $date) => $items->where('date', '<=', $date))
            ->when($filters['tag_ids'] ?? null, function (Collection $items, array $tagIds): Collection {
                $required = array_values(array_unique(array_map('intval', $tagIds)));

                return $items->filter(fn (array $item): bool => array_diff($required, $item['tag_ids'] ?? []) === []);
            })
            ->sortByDesc(fn (array $item) => $item['date'].'-'.$item['id'])
            ->values();
    }

    public function findTagByName(string $name): ?array
    {
        $normalized = $this->normalizeTagName($name);

        return collect($this->all('tags'))->firstWhere('normalized_name', $normalized);
    }

    public function resolveTagIds(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $displayName = $this->cleanTagName((string) $name);
            $tag = $this->findTagByName($displayName)
                ?? $this->create('tags', ['name' => $displayName, 'normalized_name' => $this->normalizeTagName($displayName)]);
            $ids[] = $tag['id'];
        }

        return array_values(array_unique($ids));
    }

    public function tagUsage(int $id): int
    {
        return collect($this->all('transactions'))->filter(
            fn (array $transaction): bool => in_array($id, $transaction['tag_ids'] ?? [], true),
        )->count();
    }

    public function renameTag(int $id, string $name): ?array
    {
        $displayName = $this->cleanTagName($name);

        return $this->update('tags', $id, [
            'name' => $displayName,
            'normalized_name' => $this->normalizeTagName($displayName),
        ]);
    }

    public function deleteTag(int $id): bool
    {
        $data = $this->data();
        $before = count($data['tags']);
        $data['tags'] = array_values(array_filter($data['tags'], fn (array $tag): bool => $tag['id'] !== $id));

        if (count($data['tags']) === $before) {
            return false;
        }

        foreach ($data['transactions'] as &$transaction) {
            $transaction['tag_ids'] = array_values(array_filter(
                $transaction['tag_ids'] ?? [],
                fn (int $tagId): bool => $tagId !== $id,
            ));
        }
        unset($transaction);
        $this->save($data);

        return true;
    }

    public function balance(int $accountId): int
    {
        $account = $this->find('accounts', $accountId);
        if (! $account) {
            return 0;
        }

        return (int) $account['opening_balance'] + $this->transactions(['account_id' => $accountId])
            ->where('date', '<=', now()->format('Y-m-d'))
            ->sum(fn (array $transaction): int => $this->transactionEffect($transaction, $accountId));
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
        $byMonth = $transactions->whereIn('type', ['income', 'expense'])->groupBy(fn (array $item): string => substr($item['date'], 0, 7))->map(
            fn (Collection $items, string $month): array => [
                'month' => $month,
                'income' => $items->where('type', 'income')->sum('amount'),
                'expense' => $items->where('type', 'expense')->sum('amount'),
            ],
        )->sortBy('month')->values();

        return compact('transactions', 'income', 'expenses', 'byCategory', 'byMonth') + ['result' => $income - $expenses];
    }

    public function transactionEffect(array $transaction, int $accountId): int
    {
        if ($transaction['type'] === 'transfer') {
            if ($transaction['source_account_id'] === $accountId) {
                return -$transaction['amount'];
            }

            return $transaction['destination_account_id'] === $accountId ? $transaction['amount'] : 0;
        }

        if ($transaction['account_id'] !== $accountId) {
            return 0;
        }

        return $transaction['type'] === 'income' ? $transaction['amount'] : -$transaction['amount'];
    }

    private function data(): array
    {
        if (! $this->session->has(self::KEY)) {
            $this->reset();
        }

        $data = $this->session->get(self::KEY);
        $changed = false;

        if (! array_key_exists('tags', $data)) {
            $data['tags'] = [];
            $changed = true;
        }

        foreach ($data['transactions'] ?? [] as $index => $transaction) {
            if (! array_key_exists('tag_ids', $transaction)) {
                $data['transactions'][$index]['tag_ids'] = [];
                $changed = true;
            }
        }

        if ($changed) {
            $this->save($data);
        }

        return $data;
    }

    private function save(array $data): void
    {
        $this->session->put(self::KEY, $data);
    }

    private function cleanTagName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    private function normalizeTagName(string $name): string
    {
        return mb_strtolower($this->cleanTagName($name));
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
            'tags' => [
                ['id' => 1, 'name' => 'Essencial', 'normalized_name' => 'essencial'],
                ['id' => 2, 'name' => 'Trabalho', 'normalized_name' => 'trabalho'],
                ['id' => 3, 'name' => 'Fim de semana', 'normalized_name' => 'fim de semana'],
            ],
            'transactions' => [
                ['id' => 1, 'description' => 'Salário mensal', 'type' => 'income', 'amount' => 780000, 'date' => $date(12), 'account_id' => 1, 'category_id' => 1, 'notes' => 'Crédito em conta', 'tag_ids' => [1]],
                ['id' => 2, 'description' => 'Aluguel', 'type' => 'expense', 'amount' => 235000, 'date' => $date(10), 'account_id' => 1, 'category_id' => 3, 'notes' => 'Apartamento', 'tag_ids' => [1]],
                ['id' => 3, 'description' => 'Supermercado Vila', 'type' => 'expense', 'amount' => 48670, 'date' => $date(7), 'account_id' => 1, 'category_id' => 4, 'notes' => 'Compra semanal', 'tag_ids' => [1]],
                ['id' => 4, 'description' => 'Projeto freelance', 'type' => 'income', 'amount' => 160000, 'date' => $date(6), 'account_id' => 2, 'category_id' => 2, 'notes' => 'Landing page', 'tag_ids' => [2]],
                ['id' => 5, 'description' => 'Combustível', 'type' => 'expense', 'amount' => 21000, 'date' => $date(4), 'account_id' => 1, 'category_id' => 5, 'notes' => 'Posto Central', 'tag_ids' => []],
                ['id' => 6, 'description' => 'Cinema', 'type' => 'expense', 'amount' => 9200, 'date' => $date(2), 'account_id' => 3, 'category_id' => 6, 'notes' => 'Ingressos e lanche', 'tag_ids' => [3]],
                ['id' => 7, 'description' => 'Farmácia', 'type' => 'expense', 'amount' => 13780, 'date' => $date(1), 'account_id' => 1, 'category_id' => 7, 'notes' => 'Medicamentos', 'tag_ids' => [1]],
                ['id' => 8, 'description' => 'Salário mensal', 'type' => 'income', 'amount' => 780000, 'date' => $today->subMonth()->day(5)->format('Y-m-d'), 'account_id' => 1, 'category_id' => 1, 'notes' => 'Crédito em conta', 'tag_ids' => [1]],
                ['id' => 9, 'description' => 'Aluguel', 'type' => 'expense', 'amount' => 235000, 'date' => $today->subMonth()->day(7)->format('Y-m-d'), 'account_id' => 1, 'category_id' => 3, 'notes' => 'Apartamento', 'tag_ids' => [1]],
                ['id' => 10, 'description' => 'Reserva mensal', 'type' => 'transfer', 'amount' => 50000, 'date' => $date(3), 'source_account_id' => 1, 'destination_account_id' => 2, 'account_id' => null, 'category_id' => null, 'notes' => '', 'tag_ids' => []],
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
