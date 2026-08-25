<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\Tag;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class FinanceStore
{
    private array $automaticCategorizationRules = [];

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
        if ($resource === 'categories' && ! isset($attributes['match_priority'])) {
            $attributes['match_priority'] = $this->nextCategoryPriority($attributes['type']);
        }
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

        if ($model instanceof Category && isset($attributes['type']) && $attributes['type'] !== $model->type) {
            $attributes['match_priority'] = $this->nextCategoryPriority($attributes['type']);
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

    public function hasImportedOfxTransaction(int $accountId, array $transaction): bool
    {
        return Transaction::query()->where('user_id', $this->userId())
            ->where('active_ofx_key', $this->ofxDuplicateKey($accountId, $transaction))->exists();
    }

    public function ofxDuplicateKey(int $accountId, array $transaction): string
    {
        $bankFormat = strtolower(trim((string) ($transaction['_ofx_bank'] ?? $transaction['bank_format'] ?? 'bradesco')));
        if ($bankFormat === 'inter') {
            $fitId = Str::upper(trim((string) ($transaction['ofx_fitid'] ?? $transaction['fitid'] ?? '')));

            return hash('sha256', implode('|', [$this->userId(), $accountId, 'inter', $fitId]));
        }

        $checkNumber = Str::upper(trim((string) ($transaction['ofx_checknum'] ?? $transaction['checknum'] ?? '')));
        $description = Str::lower(Str::ascii((string) ($transaction['description'] ?? '')));
        $description = trim(preg_replace('/[^a-z0-9]+/', ' ', $description) ?? $description);
        $identity = [
            $this->userId(),
            $accountId,
            $checkNumber,
            (string) ($transaction['date'] ?? ''),
            (string) ($transaction['type'] ?? ''),
            (int) ($transaction['amount'] ?? 0),
            $description,
        ];

        return hash('sha256', implode('|', $identity));
    }

    public function importOfxTransactions(int $accountId, string $label, array $transactions): array
    {
        return DB::transaction(function () use ($accountId, $label, $transactions): array {
            $tag = $this->resolveTag($label);
            $imported = [];
            $duplicates = 0;

            foreach ($transactions as $attributes) {
                $key = $this->ofxDuplicateKey($accountId, $attributes);
                if (Transaction::query()->where('user_id', $this->userId())->where('active_ofx_key', $key)->exists()) {
                    $duplicates++;

                    continue;
                }
                $attributes['active_ofx_key'] = $key;
                unset($attributes['_ofx_bank'], $attributes['bank_format']);
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
        return $this->balanceAt($accountId, CarbonImmutable::today()->toDateString());
    }

    public function balanceAt(int $accountId, string $date): int
    {
        $account = $this->find('accounts', $accountId);
        if (! $account) {
            return 0;
        }

        $balanceDate = CarbonImmutable::parse($account['opening_balance_date'])->toDateString();
        $requestedDate = CarbonImmutable::parse($date)->toDateString();
        if ($requestedDate < $balanceDate) {
            return 0;
        }

        return (int) $account['opening_balance'] + $this->transactions(['account_id' => $accountId])
            ->where('date', '>=', $balanceDate)
            ->where('date', '<=', $requestedDate)
            ->sum(fn (array $transaction): int => $this->transactionEffect($transaction, $accountId));
    }

    public function hasTransactionsBeforeOpeningBalance(int $accountId): bool
    {
        $account = $this->find('accounts', $accountId);
        if (! $account) {
            return false;
        }

        return $this->transactions(['account_id' => $accountId])
            ->contains(fn (array $transaction): bool => $transaction['date'] < $account['opening_balance_date']);
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

    public function automaticCategorizationCategories(): Collection
    {
        return Category::query()
            ->where('user_id', $this->userId())
            ->with(['keywords' => fn (HasMany|Builder $query) => $query->orderBy('id')])
            ->orderBy('type')
            ->orderBy('match_priority')
            ->orderBy('id')
            ->get()
            ->map(function (Category $category): array {
                $attributes = $this->toArray($category);
                $attributes['keywords'] = $category->keywords->map(fn (CategoryKeyword $keyword): array => [
                    'id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                ])->all();

                return $attributes;
            });
    }

    public function syncCategoryKeywords(int $categoryId, array $keywords): ?array
    {
        $category = Category::query()->where('user_id', $this->userId())->find($categoryId);
        if (! $category) {
            return null;
        }

        $prepared = collect($keywords)->map(function (string $keyword): array {
            $clean = $this->cleanCategoryKeyword($keyword);

            return ['keyword' => $clean, 'normalized_keyword' => $this->normalizeCategoryKeyword($clean)];
        })->filter(fn (array $keyword): bool => $keyword['normalized_keyword'] !== '');

        if ($prepared->pluck('normalized_keyword')->duplicates()->isNotEmpty()) {
            throw new LogicException('Duplicate normalized category keywords.');
        }

        DB::transaction(function () use ($category, $prepared): void {
            $category->keywords()->delete();
            foreach ($prepared as $keyword) {
                $category->keywords()->create($keyword + ['user_id' => $this->userId()]);
            }
        });
        $this->automaticCategorizationRules = [];

        return $this->automaticCategorizationCategories()->firstWhere('id', $categoryId);
    }

    public function moveCategoryPriority(int $categoryId, string $direction): bool
    {
        $category = Category::query()->where('user_id', $this->userId())->find($categoryId);
        if (! $category || ! in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        DB::transaction(function () use ($category, $direction): void {
            $categories = Category::query()->where('user_id', $this->userId())->where('type', $category->type)
                ->orderBy('match_priority')->orderBy('id')->lockForUpdate()->get()->values();
            $current = $categories->search(fn (Category $item): bool => $item->is($category));
            if ($current === false) {
                return;
            }
            $target = $direction === 'up' ? $current - 1 : $current + 1;
            if (! $categories->has($target)) {
                return;
            }
            $categories->each(function (Category $item, int $index): void {
                $item->match_priority = $index + 1;
                $item->save();
            });
            $first = $categories[$current];
            $second = $categories[$target];
            [$first->match_priority, $second->match_priority] = [$second->match_priority, $first->match_priority];
            $first->save();
            $second->save();
        });
        $this->automaticCategorizationRules = [];

        return true;
    }

    public function suggestCategory(string $type, string $description): ?array
    {
        if (! in_array($type, ['income', 'expense'], true)) {
            return null;
        }
        $description = $this->normalizeCategoryKeyword($description);
        if ($description === '') {
            return null;
        }

        foreach ($this->automaticCategorizationRules($type) as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (str_contains($description, $keyword['normalized_keyword'])) {
                    return ['category_id' => $rule['category_id'], 'keyword' => $keyword['keyword']];
                }
            }
        }

        return null;
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

    private function automaticCategorizationRules(string $type): array
    {
        return $this->automaticCategorizationRules[$type] ??= Category::query()
            ->where('user_id', $this->userId())
            ->where('type', $type)
            ->whereHas('keywords')
            ->with(['keywords' => fn (HasMany|Builder $query) => $query->orderBy('id')])
            ->orderBy('match_priority')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category): array => [
                'category_id' => $category->id,
                'keywords' => $category->keywords->map(fn (CategoryKeyword $keyword): array => [
                    'keyword' => $keyword->keyword,
                    'normalized_keyword' => $keyword->normalized_keyword,
                ])->all(),
            ])->all();
    }

    private function nextCategoryPriority(string $type): int
    {
        return ((int) Category::query()->where('user_id', $this->userId())->where('type', $type)->max('match_priority')) + 1;
    }

    private function cleanCategoryKeyword(string $keyword): string
    {
        return Str::squish($keyword);
    }

    private function normalizeCategoryKeyword(string $keyword): string
    {
        return Str::lower(Str::ascii($this->cleanCategoryKeyword($keyword)));
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

    private function userId(): int
    {
        return auth()->id() ?? throw new LogicException('FinanceStore requires an authenticated user.');
    }
}
