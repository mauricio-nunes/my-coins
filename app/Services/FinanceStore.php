<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class FinanceStore
{
    private const MODELS = [
        'accounts' => Account::class,
        'categories' => Category::class,
        'tags' => Tag::class,
        'transactions' => Transaction::class,
        'budgets' => Budget::class,
    ];

    public function all(string $resource): array
    {
        return $this->query($resource)->get()->map(fn (Model $model): array => $this->toArray($model))->all();
    }

    public function find(string $resource, int $id): ?array
    {
        $model = $this->query($resource)->find($id);

        return $model ? $this->toArray($model) : null;
    }

    public function create(string $resource, array $attributes): array
    {
        $tagIds = $attributes['tag_ids'] ?? null;
        unset($attributes['tag_ids']);
        $model = $this->query($resource)->create($attributes + ['user_id' => $this->userId()]);
        if ($model instanceof Transaction && is_array($tagIds)) {
            $model->tags()->sync($tagIds);
        }

        return $this->toArray($model->refresh());
    }

    public function update(string $resource, int $id, array $attributes): ?array
    {
        $model = $this->query($resource)->find($id);
        if (! $model) {
            return null;
        }

        $tagIds = $attributes['tag_ids'] ?? null;
        unset($attributes['tag_ids'], $attributes['user_id']);
        $model->update($attributes);
        if ($model instanceof Transaction && is_array($tagIds)) {
            $model->tags()->sync($tagIds);
        }

        return $this->toArray($model->refresh());
    }

    public function delete(string $resource, int $id): bool
    {
        $model = $this->query($resource)->find($id);
        if (! $model) {
            return false;
        }

        return DB::transaction(function () use ($model): bool {
            if ($model instanceof Transaction) {
                $model->active_ofx_key = null;
                $model->save();
            }

            return (bool) $model->delete();
        });
    }

    public function transactions(array $filters = []): Collection
    {
        $query = Transaction::query()->where('user_id', $this->userId())->with('tags');
        $query->when($filters['search'] ?? null, function (Builder $query, string $search): void {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhereHas('tags', fn (Builder $tags): Builder => $tags->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('account', fn (Builder $account): Builder => $account->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('sourceAccount', fn (Builder $account): Builder => $account->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('destinationAccount', fn (Builder $account): Builder => $account->where('name', 'like', "%{$search}%"));
            });
        });
        $query->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type));
        $query->when($filters['account_id'] ?? null, function (Builder $query, mixed $id): void {
            $query->where(function (Builder $query) use ($id): void {
                $query->where('account_id', (int) $id)
                    ->orWhere('source_account_id', (int) $id)
                    ->orWhere('destination_account_id', (int) $id);
            });
        });
        $query->when($filters['category_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('category_id', (int) $id));
        $query->when($filters['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '>=', $date));
        $query->when($filters['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '<=', $date));
        foreach (array_unique(array_map('intval', $filters['tag_ids'] ?? [])) as $tagId) {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereKey($tagId));
        }

        return $query->orderByDesc('date')->orderByDesc('id')->get()->map(fn (Transaction $transaction): array => $this->toArray($transaction));
    }

    public function hasImportedOfxTransaction(int $accountId, string $fitId): bool
    {
        return Transaction::query()->where('user_id', $this->userId())
            ->where('ofx_account_id', $accountId)->where('ofx_fitid', $fitId)->exists();
    }

    public function importOfxTransactions(int $accountId, string $label, array $transactions): array
    {
        return DB::transaction(function () use ($accountId, $label, $transactions): array {
            $tag = $this->resolveTag($label);
            $imported = [];
            $duplicates = 0;

            foreach ($transactions as $attributes) {
                $key = $this->ofxKey($accountId, $attributes['ofx_fitid']);
                if (Transaction::query()->where('active_ofx_key', $key)->exists()) {
                    $duplicates++;

                    continue;
                }
                $attributes['active_ofx_key'] = $key;
                try {
                    $imported[] = $this->create('transactions', $attributes + ['tag_ids' => [$tag->id]]);
                } catch (UniqueConstraintViolationException) {
                    // A parallel import may win after the duplicate check.
                    $duplicates++;
                }
            }

            return ['tag' => $this->toArray($tag), 'transactions' => $imported, 'duplicates' => $duplicates];
        });
    }

    public function findTagByName(string $name): ?array
    {
        $tag = Tag::withTrashed()->where('user_id', $this->userId())->where('normalized_name', $this->normalizeTagName($name))->first();

        return $tag && ! $tag->trashed() ? $this->toArray($tag) : null;
    }

    public function resolveTagIds(array $names): array
    {
        return collect($names)->map(fn (string $name): int => $this->resolveTag($name)->id)->unique()->values()->all();
    }

    public function tagUsage(int $id): int
    {
        $tag = Tag::query()->where('user_id', $this->userId())->find($id);

        return $tag ? $tag->transactions()->count() : 0;
    }

    public function renameTag(int $id, string $name): ?array
    {
        return $this->update('tags', $id, ['name' => $this->cleanTagName($name), 'normalized_name' => $this->normalizeTagName($name)]);
    }

    public function deleteTag(int $id): bool
    {
        $tag = Tag::query()->where('user_id', $this->userId())->find($id);
        if (! $tag) {
            return false;
        }

        return DB::transaction(function () use ($tag): bool {
            $tag->transactions()->detach();

            return (bool) $tag->delete();
        });
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
        return Transaction::query()->where('user_id', $this->userId())->where('category_id', $id)->exists()
            || Budget::query()->where('user_id', $this->userId())->where('category_id', $id)->exists();
    }

    public function budgetSpent(array $budget): int
    {
        return $this->transactions(['category_id' => $budget['category_id']])->where('type', 'expense')
            ->filter(fn (array $item): bool => str_starts_with($item['date'], $budget['month']))->sum('amount');
    }

    public function report(array $filters): array
    {
        $transactions = $this->transactions($filters);
        $income = $transactions->where('type', 'income')->sum('amount');
        $expenses = $transactions->where('type', 'expense')->sum('amount');
        $categories = collect($this->all('categories'))->keyBy('id');
        $byCategory = $transactions->where('type', 'expense')->groupBy('category_id')->map(
            fn (Collection $items, int|string $categoryId): array => ['name' => $categories->get((int) $categoryId)['name'] ?? 'Sem categoria', 'amount' => $items->sum('amount')],
        )->sortByDesc('amount')->values();
        $byMonth = $transactions->whereIn('type', ['income', 'expense'])->groupBy(fn (array $item): string => substr($item['date'], 0, 7))->map(
            fn (Collection $items, string $month): array => ['month' => $month, 'income' => $items->where('type', 'income')->sum('amount'), 'expense' => $items->where('type', 'expense')->sum('amount')],
        )->sortBy('month')->values();

        return compact('transactions', 'income', 'expenses', 'byCategory', 'byMonth') + ['result' => $income - $expenses];
    }

    public function transactionEffect(array $transaction, int $accountId): int
    {
        if ($transaction['type'] === 'transfer') {
            return $transaction['source_account_id'] === $accountId
                ? -$transaction['amount']
                : ($transaction['destination_account_id'] === $accountId ? $transaction['amount'] : 0);
        }

        if ($transaction['account_id'] !== $accountId) {
            return 0;
        }

        return $transaction['type'] === 'income' ? $transaction['amount'] : -$transaction['amount'];
    }

    private function query(string $resource): Builder
    {
        $model = self::MODELS[$resource] ?? throw new LogicException("Unknown finance resource: {$resource}");

        return $model::query()->where('user_id', $this->userId());
    }

    private function toArray(Model $model): array
    {
        $attributes = $model->attributesToArray();
        unset($attributes['user_id'], $attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at'], $attributes['active_ofx_key']);
        if ($model instanceof Transaction) {
            $attributes['date'] = $model->date->format('Y-m-d');
            $attributes['amount'] = (int) $model->amount;
            $attributes['tag_ids'] = $model->relationLoaded('tags')
                ? $model->tags->pluck('id')->map(fn (int $id): int => $id)->all()
                : $model->tags()->pluck('tags.id')->map(fn (int $id): int => $id)->all();
        }

        return $attributes;
    }

    private function resolveTag(string $name): Tag
    {
        $displayName = $this->cleanTagName($name);
        $tag = Tag::withTrashed()->where('user_id', $this->userId())->where('normalized_name', $this->normalizeTagName($displayName))->first();
        if ($tag) {
            if ($tag->trashed()) {
                $tag->restore();
            }

            return $tag;
        }

        return Tag::create(['user_id' => $this->userId(), 'name' => $displayName, 'normalized_name' => $this->normalizeTagName($displayName)]);
    }

    private function cleanTagName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    private function normalizeTagName(string $name): string
    {
        return mb_strtolower($this->cleanTagName($name));
    }

    private function ofxKey(int $accountId, string $fitId): string
    {
        return hash('sha256', $this->userId().'|'.$accountId.'|'.$fitId);
    }

    private function userId(): int
    {
        return auth()->id() ?? throw new LogicException('FinanceStore requires an authenticated user.');
    }
}
