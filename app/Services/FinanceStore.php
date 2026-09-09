<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\CardInstallment;
use App\Models\CardPurchase;
use App\Models\CardStatement;
use App\Models\CardStatementPayment;
use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\CreditCard;
use App\Models\RecurringTransaction;
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
        $query = Transaction::query()->where('user_id', $this->userId())->with([
            'tags',
            'cardInstallment.purchase.card',
            'cardInstallment.statement',
            'statementPayment.statement.card',
        ]);
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
        if (isset($filters['reconciled'])) {
            $query->where('reconciled', $filters['reconciled'] === 'yes');
        }
        $query->when($filters['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '>=', $date));
        $query->when($filters['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '<=', $date));
        foreach (array_unique(array_map('intval', $filters['tag_ids'] ?? [])) as $tagId) {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereKey($tagId));
        }

        $dateOrder = ($filters['date_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('date', $dateOrder)->orderBy('id', $dateOrder)->get()
            ->map(fn (Transaction $transaction): array => $this->toArray($transaction));
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

        return $tag ? $tag->transactions()->count() + $tag->recurringTransactions()->count() : 0;
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
            $tag->recurringTransactions()->detach();

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
            'balance' => collect($this->all('accounts'))->where('archived', false)->where('type', '!=', 'credit_card')->sum(fn (array $account): int => $this->balance($account['id'])),
            'income' => $income,
            'expenses' => $expenses,
            'result' => $income - $expenses,
            'upcoming' => $this->upcomingTransactions(),
        ];
    }

    public function upcomingTransactions(int $limit = 10): Collection
    {
        return Transaction::query()
            ->where('user_id', $this->userId())
            ->whereDate('date', '>=', CarbonImmutable::today()->toDateString())
            ->with('tags')
            ->orderBy('date')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Transaction $transaction): array => $this->toArray($transaction));
    }

    public function dailyCashFlow(?CarbonImmutable $referenceDate = null, ?Collection $commitments = null): Collection
    {
        $monthStart = ($referenceDate ?? CarbonImmutable::now())->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $today = CarbonImmutable::today();
        $accounts = collect($this->all('accounts'))->where('archived', false)->where('type', '!=', 'credit_card')->values();
        $accountIds = $accounts->pluck('id')->map(fn (int $id): int => $id)->all();
        $transactions = $this->transactions(['to' => $monthEnd->toDateString()]);
        $monthlyTransactions = $transactions->filter(fn (array $transaction): bool => $transaction['date'] >= $monthStart->toDateString());
        $cardCommitments = ($commitments ?? $this->cardCommitments())->map(function (array $statement) use ($today): array {
            $dueDate = CarbonImmutable::parse($statement['due_date']);

            return $statement + ['projection_date' => $dueDate->lt($today) ? $today->toDateString() : $dueDate->toDateString()];
        })->filter(fn (array $statement): bool => $statement['projection_date'] >= $monthStart->toDateString()
            && $statement['projection_date'] <= $monthEnd->toDateString())
            ->groupBy('projection_date');
        $balance = $this->consolidatedBalanceAt($accounts, $transactions, $monthStart->subDay());
        $committedBalance = $balance;
        $points = collect();

        for ($date = $monthStart; $date->lte($monthEnd); $date = $date->addDay()) {
            $daily = $monthlyTransactions->where('date', $date->toDateString());
            $income = $daily->filter(fn (array $item): bool => $item['type'] === 'income' && in_array($item['account_id'], $accountIds, true))->sum('amount');
            $expenses = $daily->filter(fn (array $item): bool => $item['type'] === 'expense' && in_array($item['account_id'], $accountIds, true))->sum('amount');
            foreach ($daily->where('type', 'transfer') as $transfer) {
                $sourceTracked = in_array($transfer['source_account_id'], $accountIds, true);
                $destinationTracked = in_array($transfer['destination_account_id'], $accountIds, true);
                if ($sourceTracked && ! $destinationTracked) {
                    $expenses += $transfer['amount'];
                } elseif (! $sourceTracked && $destinationTracked) {
                    $income += $transfer['amount'];
                }
            }
            $balance += $income - $expenses;
            $cardStatements = (int) ($cardCommitments->get($date->toDateString())?->sum('outstanding') ?? 0);
            $committedBalance += $income - $expenses - $cardStatements;
            $points->push([
                'date' => $date->toDateString(),
                'income' => $income,
                'expense' => $expenses,
                'card_statements' => $cardStatements,
                'balance' => $balance,
                'balance_after_cards' => $committedBalance,
            ]);
        }

        return $points;
    }

    public function categoryIsUsed(int $id): bool
    {
        return Transaction::query()->where('user_id', $this->userId())->where('category_id', $id)->exists()
            || Budget::query()->where('user_id', $this->userId())->whereHas('categories', fn (Builder $query): Builder => $query->whereKey($id))->exists()
            || CardPurchase::query()->where('user_id', $this->userId())->where('category_id', $id)->exists()
            || RecurringTransaction::query()->where('user_id', $this->userId())->where('category_id', $id)->where('status', 'active')->exists();
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

    public function cashFlowByMonth(?Collection $commitments = null): Collection
    {
        $accountIds = collect($this->all('accounts'))->where('type', '!=', 'credit_card')->pluck('id')->all();
        $registered = $this->transactions()->filter(function (array $item) use ($accountIds): bool {
            if ($item['type'] === 'transfer') {
                return in_array($item['source_account_id'], $accountIds, true)
                    !== in_array($item['destination_account_id'], $accountIds, true);
            }

            return in_array($item['account_id'], $accountIds, true);
        })->groupBy(fn (array $item): string => substr($item['date'], 0, 7))
            ->map(function (Collection $items, string $month) use ($accountIds): array {
                $income = $items->filter(fn (array $item): bool => $item['type'] === 'income' && in_array($item['account_id'], $accountIds, true))->sum('amount');
                $expense = $items->filter(fn (array $item): bool => $item['type'] === 'expense' && in_array($item['account_id'], $accountIds, true))->sum('amount');
                foreach ($items->where('type', 'transfer') as $transfer) {
                    $sourceTracked = in_array($transfer['source_account_id'], $accountIds, true);
                    $destinationTracked = in_array($transfer['destination_account_id'], $accountIds, true);
                    if ($sourceTracked && ! $destinationTracked) {
                        $expense += $transfer['amount'];
                    } elseif (! $sourceTracked && $destinationTracked) {
                        $income += $transfer['amount'];
                    }
                }

                return compact('month', 'income', 'expense');
            });
        $commitmentsByMonth = ($commitments ?? $this->cardCommitments())->groupBy(fn (array $item): string => substr($item['due_date'], 0, 7))
            ->map(fn (Collection $items): int => (int) $items->sum('outstanding'));

        return $registered->keys()->merge($commitmentsByMonth->keys())->unique()->sort()->values()
            ->map(function (string $month) use ($registered, $commitmentsByMonth): array {
                $values = $registered->get($month, ['income' => 0, 'expense' => 0]);

                return [
                    'month' => $month,
                    'income' => (int) $values['income'],
                    'expense' => (int) $values['expense'],
                    'card_statements' => (int) $commitmentsByMonth->get($month, 0),
                ];
            });
    }

    public function cardCommitments(): Collection
    {
        $today = CarbonImmutable::today();

        return CardStatement::query()->where('user_id', $this->userId())->whereHas('installments')
            ->with('card.account')->get()->map(function (CardStatement $statement) use ($today): array {
                $metric = $this->statementMetric($statement->card, $statement->month);
                $dueDate = CarbonImmutable::parse($metric['due_date']);

                return $metric + [
                    'card_name' => $statement->card->name,
                    'card_color' => $statement->card->account?->color ?? '#6f42c1',
                    'card_archived' => (bool) $statement->card->archived,
                    'overdue' => $dueDate->lt($today),
                ];
            })->filter(fn (array $statement): bool => $statement['total'] > 0 && $statement['outstanding'] > 0)
            ->sortBy(fn (array $statement): string => $statement['due_date'].'-'.str_pad((string) $statement['id'], 12, '0', STR_PAD_LEFT))
            ->values();
    }

    public function cardCommitmentSummary(?Collection $commitments = null, int $limit = 5): array
    {
        $commitments ??= $this->cardCommitments();

        return [
            'total' => (int) $commitments->sum('outstanding'),
            'items' => $commitments->take(max(1, $limit))->values(),
        ];
    }

    public function paymentAccounts(): Collection
    {
        return collect($this->all('accounts'))->where('archived', false)->whereIn('type', ['checking', 'savings'])->values();
    }

    public function creditCards(): Collection
    {
        return CreditCard::query()->where('user_id', $this->userId())->with(['account', 'defaultPaymentAccount'])
            ->orderBy('archived')->orderBy('name')->get()->map(function (CreditCard $card): array {
                $attributes = $this->creditCardArray($card);
                $month = $this->statementMonthForDate($card, CarbonImmutable::today());

                return $attributes + ['statement' => $this->statementMetric($card, $month)];
            });
    }

    public function creditCard(int $id): ?array
    {
        $card = $this->creditCardModel($id);

        return $card ? $this->creditCardArray($card) : null;
    }

    public function creditCardDetails(int $id, ?string $month = null): ?array
    {
        $card = $this->creditCardModel($id);
        if (! $card) {
            return null;
        }
        $month ??= $this->statementMonthForDate($card, CarbonImmutable::today());

        return $this->creditCardArray($card) + ['statement' => $this->statementMetric($card, $month)];
    }

    public function createCreditCard(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $account = Account::create([
                'user_id' => $this->userId(),
                'name' => $attributes['name'],
                'institution' => $attributes['network'],
                'type' => 'credit_card',
                'color' => $attributes['color'],
                'opening_balance' => 0,
                'opening_balance_date' => '1900-01-01',
                'archived' => false,
            ]);
            unset($attributes['color']);
            $card = CreditCard::create($attributes + [
                'user_id' => $this->userId(),
                'account_id' => $account->id,
                'archived' => false,
            ]);

            return $this->creditCardArray($card->load(['account', 'defaultPaymentAccount']));
        });
    }

    public function updateCreditCard(int $id, array $attributes): ?array
    {
        $card = $this->creditCardModel($id);
        if (! $card) {
            return null;
        }

        return DB::transaction(function () use ($card, $attributes): array {
            $color = $attributes['color'];
            unset($attributes['color']);
            $card->update($attributes);
            $card->account()->update(['name' => $card->name, 'institution' => $card->network, 'color' => $color]);

            return $this->creditCardArray($card->refresh()->load(['account', 'defaultPaymentAccount']));
        });
    }

    public function archiveCreditCard(int $id): bool
    {
        $card = $this->creditCardModel($id);
        if (! $card) {
            return false;
        }
        DB::transaction(function () use ($card): void {
            $card->update(['archived' => true]);
            $card->account()->update(['archived' => true]);
        });

        return true;
    }

    public function cardUsedLimit(int|CreditCard $card): int
    {
        $model = $card instanceof CreditCard ? $card : $this->creditCardModel($card);
        if (! $model) {
            return 0;
        }
        $purchases = (int) CardPurchase::query()->where('user_id', $this->userId())->where('credit_card_id', $model->id)->sum('total_amount');
        $payments = (int) CardStatementPayment::query()->where('user_id', $this->userId())
            ->whereHas('statement', fn (Builder $query): Builder => $query->where('credit_card_id', $model->id))
            ->whereDate('payment_date', '<=', CarbonImmutable::today()->toDateString())->sum('amount');

        return max(0, $purchases - $payments);
    }

    public function createCardPurchase(int $cardId, array $attributes): array
    {
        $card = $this->creditCardModel($cardId);
        if (! $card || $card->archived) {
            throw new LogicException('Cartão indisponível.');
        }
        if ($attributes['total_amount'] > $card->credit_limit - $this->cardUsedLimit($card)) {
            throw new LogicException('Limite disponível insuficiente.');
        }

        return DB::transaction(function () use ($card, $attributes): array {
            $purchase = CardPurchase::create($attributes + ['user_id' => $this->userId(), 'credit_card_id' => $card->id]);
            $this->generateCardInstallments($card, $purchase);

            return $this->cardPurchaseArray($purchase->load(['card', 'category', 'installments.statement']));
        });
    }

    public function cardPurchase(int $id): ?array
    {
        $purchase = CardPurchase::query()->where('user_id', $this->userId())
            ->with(['card.account', 'category', 'installments.statement'])->find($id);

        return $purchase ? $this->cardPurchaseArray($purchase) : null;
    }

    public function cardPurchaseCanChange(int $id): bool
    {
        $purchase = CardPurchase::query()->where('user_id', $this->userId())->with('installments.statement.payments')->find($id);
        if (! $purchase) {
            return false;
        }

        return $purchase->installments->every(fn (CardInstallment $installment): bool => $installment->statement->closing_date->gte(CarbonImmutable::today()) && $installment->statement->payments->isEmpty());
    }

    public function updateCardPurchase(int $id, array $attributes): ?array
    {
        $purchase = CardPurchase::query()->where('user_id', $this->userId())->with(['card', 'installments'])->find($id);
        if (! $purchase || ! $this->cardPurchaseCanChange($id)) {
            return null;
        }
        $usedWithoutPurchase = $this->cardUsedLimit($purchase->card) - $purchase->total_amount;
        if ($attributes['total_amount'] > $purchase->card->credit_limit - max(0, $usedWithoutPurchase)) {
            throw new LogicException('Limite disponível insuficiente.');
        }

        return DB::transaction(function () use ($purchase, $attributes): array {
            $this->removePurchaseInstallments($purchase);
            $purchase->update($attributes);
            $this->generateCardInstallments($purchase->card, $purchase);

            return $this->cardPurchaseArray($purchase->refresh()->load(['card', 'category', 'installments.statement']));
        });
    }

    public function deleteCardPurchase(int $id): bool
    {
        $purchase = CardPurchase::query()->where('user_id', $this->userId())->with('installments')->find($id);
        if (! $purchase || ! $this->cardPurchaseCanChange($id)) {
            return false;
        }

        return DB::transaction(function () use ($purchase): bool {
            $this->removePurchaseInstallments($purchase);

            return (bool) $purchase->delete();
        });
    }

    public function payCardStatement(int $statementId, int $sourceAccountId, int $amount, string $date): ?array
    {
        $statement = CardStatement::query()->where('user_id', $this->userId())->with('card.account')->find($statementId);
        $source = Account::query()->where('user_id', $this->userId())->whereKey($sourceAccountId)
            ->where('archived', false)->whereIn('type', ['checking', 'savings'])->first();
        if (! $statement || ! $source) {
            return null;
        }
        $metric = $this->statementMetric($statement->card, $statement->month);
        $paymentDate = CarbonImmutable::parse($date);
        if ($statement->closing_date->gte(CarbonImmutable::today()) || $paymentDate->lt($statement->closing_date)
            || $paymentDate->gt(CarbonImmutable::today()) || $amount <= 0 || $amount > $metric['outstanding']) {
            throw new LogicException('Pagamento inválido para esta fatura.');
        }

        return DB::transaction(function () use ($statement, $source, $amount, $date): array {
            $transaction = Transaction::create([
                'user_id' => $this->userId(),
                'description' => 'Pagamento da fatura '.$statement->card->name.' '.$statement->month,
                'type' => 'transfer',
                'amount' => $amount,
                'date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $statement->card->account_id,
                'notes' => '',
                'reconciled' => false,
            ]);
            $payment = CardStatementPayment::create([
                'user_id' => $this->userId(),
                'card_statement_id' => $statement->id,
                'source_account_id' => $source->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'payment_date' => $date,
            ]);

            return ['id' => $payment->id, 'amount' => $amount, 'payment_date' => $date];
        });
    }

    public function deleteCardStatementPayment(int $id): bool
    {
        $payment = CardStatementPayment::query()->where('user_id', $this->userId())->with('transaction')->find($id);
        if (! $payment) {
            return false;
        }

        return DB::transaction(function () use ($payment): bool {
            $payment->transaction->delete();

            return (bool) $payment->delete();
        });
    }

    public function cardStatement(int $id): ?array
    {
        $statement = CardStatement::query()->where('user_id', $this->userId())->with('card')->find($id);

        return $statement ? $this->statementMetric($statement->card, $statement->month) : null;
    }

    public function cardStatementPayment(int $id): ?array
    {
        $payment = CardStatementPayment::query()->where('user_id', $this->userId())->with('statement.card')->find($id);

        return $payment ? [
            'id' => $payment->id,
            'credit_card_id' => $payment->statement->credit_card_id,
            'statement_month' => $payment->statement->month,
        ] : null;
    }

    public function budgetSpent(array $budget): int
    {
        return $this->budgetMetrics($budget)['spent'];
    }

    public function budgetsForMonth(string $month): Collection
    {
        return Budget::query()->where('user_id', $this->userId())->where('month', $month)
            ->with(['categories' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')->get()->map(fn (Budget $budget): array => $this->budgetMetrics($this->toArray($budget)));
    }

    public function budgetDetails(int $id): ?array
    {
        $budget = Budget::query()->where('user_id', $this->userId())
            ->with(['categories' => fn ($query) => $query->orderBy('name')])->find($id);

        return $budget ? $this->budgetMetrics($this->toArray($budget)) : null;
    }

    public function budgetNameExists(string $name, string $month, ?int $ignore = null): bool
    {
        return Budget::query()->where('user_id', $this->userId())->where('month', $month)
            ->where('normalized_name', $this->normalizeBudgetName($name))
            ->when($ignore, fn (Builder $query, int $id): Builder => $query->whereKeyNot($id))->exists();
    }

    public function createBudget(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $categoryIds = $attributes['category_ids'];
            unset($attributes['category_ids']);
            $attributes = $this->prepareBudgetAttributes($attributes);
            $budget = Budget::create($attributes + ['user_id' => $this->userId()]);
            $budget->categories()->sync($categoryIds);

            return $this->toArray($budget->load('categories'));
        });
    }

    public function updateBudget(int $id, array $attributes): ?array
    {
        $budget = Budget::query()->where('user_id', $this->userId())->find($id);
        if (! $budget) {
            return null;
        }

        return DB::transaction(function () use ($budget, $attributes): array {
            $categoryIds = $attributes['category_ids'];
            unset($attributes['category_ids']);
            $budget->update($this->prepareBudgetAttributes($attributes));
            $budget->categories()->sync($categoryIds);

            return $this->toArray($budget->refresh()->load('categories'));
        });
    }

    public function copyBudget(int $id, string $destinationMonth): ?array
    {
        $source = Budget::query()->where('user_id', $this->userId())->with('categories')->find($id);
        if (! $source) {
            return null;
        }

        return $this->createBudget([
            'name' => $source->name,
            'month' => $destinationMonth,
            'limit' => (int) $source->limit,
            'category_ids' => $source->categories->pluck('id')->all(),
        ]);
    }

    public function deleteBudget(int $id): bool
    {
        $budget = Budget::query()->where('user_id', $this->userId())->find($id);
        if (! $budget) {
            return false;
        }

        return DB::transaction(function () use ($budget): bool {
            $budget->active_name_key = null;
            $budget->save();

            return (bool) $budget->delete();
        });
    }

    public function budgetMetrics(array $budget): array
    {
        $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $budget['month'].'-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $today = CarbonImmutable::today();
        $isCurrent = $monthStart->format('Y-m') === $today->format('Y-m');
        $isPast = $monthEnd->lt($today);
        $cutoff = $isPast ? $monthEnd : ($isCurrent ? $today : $monthStart->subDay());
        $categoryIds = array_map('intval', $budget['category_ids'] ?? collect($budget['categories'] ?? [])->pluck('id')->all());
        $spent = $cutoff->gte($monthStart) && $categoryIds !== []
            ? (int) Transaction::query()->where('user_id', $this->userId())->where('type', 'expense')
                ->whereIn('category_id', $categoryIds)->whereDate('date', '>=', $monthStart->toDateString())
                ->whereDate('date', '<=', $cutoff->toDateString())->sum('amount')
            : 0;
        $projection = $categoryIds !== []
            ? (int) Transaction::query()->where('user_id', $this->userId())->where('type', 'expense')
                ->whereIn('category_id', $categoryIds)->whereDate('date', '>=', $monthStart->toDateString())
                ->whereDate('date', '<=', $monthEnd->toDateString())->sum('amount')
            : 0;
        $limit = (int) $budget['limit'];
        $percentage = $limit > 0 ? ($spent / $limit) * 100 : 0.0;
        $projectionPercentage = $limit > 0 ? ($projection / $limit) * 100 : 0.0;
        $status = match (true) {
            $projection > $limit => 'exceeded',
            $projection * 10 > $limit * 9 => 'attention',
            default => 'within',
        };
        $statusLabels = [
            'within' => 'Dentro do limite',
            'attention' => 'Atenção',
            'exceeded' => 'Limite excedido',
        ];

        return $budget + [
            'spent' => $spent,
            'remaining' => $limit - $spent,
            'percentage' => $percentage,
            'progress' => min(100, max(0, (int) round($percentage))),
            'projection' => $projection,
            'projection_percentage' => $projectionPercentage,
            'projection_progress' => min(100, max(0, (int) round($projectionPercentage))),
            'status' => $status,
            'status_label' => $statusLabels[$status],
        ];
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

    private function consolidatedBalanceAt(Collection $accounts, Collection $transactions, CarbonImmutable $date): int
    {
        $requestedDate = $date->toDateString();

        return $accounts->sum(function (array $account) use ($transactions, $requestedDate): int {
            if ($requestedDate < $account['opening_balance_date']) {
                return 0;
            }

            return (int) $account['opening_balance'] + $transactions
                ->where('date', '>=', $account['opening_balance_date'])
                ->where('date', '<=', $requestedDate)
                ->sum(fn (array $transaction): int => $this->transactionEffect($transaction, $account['id']));
        });
    }

    private function query(string $resource): Builder
    {
        $model = self::MODELS[$resource] ?? throw new LogicException("Unknown finance resource: {$resource}");
        $query = $model::query()->where('user_id', $this->userId());
        if ($resource === 'transactions') {
            $query->with(['tags', 'cardInstallment.purchase.card', 'cardInstallment.statement', 'statementPayment.statement.card']);
        }

        return $query;
    }

    private function creditCardModel(int $id): ?CreditCard
    {
        return CreditCard::query()->where('user_id', $this->userId())
            ->with(['account', 'defaultPaymentAccount'])->find($id);
    }

    private function creditCardArray(CreditCard $card): array
    {
        $usedLimit = $this->cardUsedLimit($card);

        return [
            'id' => $card->id,
            'account_id' => $card->account_id,
            'default_payment_account_id' => $card->default_payment_account_id,
            'default_payment_account_name' => $card->defaultPaymentAccount?->name,
            'name' => $card->name,
            'network' => $card->network,
            'credit_limit' => (int) $card->credit_limit,
            'used_limit' => $usedLimit,
            'available_limit' => max(0, (int) $card->credit_limit - $usedLimit),
            'closing_day' => (int) $card->closing_day,
            'due_day' => (int) $card->due_day,
            'color' => $card->account?->color ?? '#6f42c1',
            'archived' => (bool) $card->archived,
        ];
    }

    private function cycleDate(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $start = $month->startOfMonth();

        return $start->day(min($day, $start->daysInMonth));
    }

    private function statementMonthForDate(CreditCard $card, CarbonImmutable $date): string
    {
        $closing = $this->cycleDate($date, $card->closing_day);

        return ($date->lte($closing) ? $closing : $this->cycleDate($date->addMonthNoOverflow(), $card->closing_day))->format('Y-m');
    }

    private function statementDates(CreditCard $card, string $month): array
    {
        $monthDate = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $closing = $this->cycleDate($monthDate, $card->closing_day);
        $previousClosing = $this->cycleDate($monthDate->subMonthNoOverflow(), $card->closing_day);
        $dueMonth = $card->due_day > $card->closing_day ? $monthDate : $monthDate->addMonthNoOverflow();

        return [
            'period_start' => $previousClosing->addDay(),
            'closing_date' => $closing,
            'due_date' => $this->cycleDate($dueMonth, $card->due_day),
        ];
    }

    private function statementModel(CreditCard $card, string $month): CardStatement
    {
        $dates = $this->statementDates($card, $month);

        return CardStatement::firstOrCreate(
            ['credit_card_id' => $card->id, 'month' => $month],
            ['user_id' => $this->userId()] + collect($dates)->map(fn (CarbonImmutable $date): string => $date->toDateString())->all(),
        );
    }

    private function statementMetric(CreditCard $card, string $month): array
    {
        $statement = CardStatement::query()->where('user_id', $this->userId())
            ->where('credit_card_id', $card->id)->where('month', $month)
            ->with(['installments.purchase.category', 'payments.sourceAccount'])->first();
        $dates = $statement ? [
            'period_start' => CarbonImmutable::parse($statement->period_start),
            'closing_date' => CarbonImmutable::parse($statement->closing_date),
            'due_date' => CarbonImmutable::parse($statement->due_date),
        ] : $this->statementDates($card, $month);
        $installments = $statement?->installments ?? collect();
        $payments = $statement?->payments ?? collect();
        $total = (int) $installments->sum('amount');
        $paid = (int) $payments->where('payment_date', '<=', CarbonImmutable::today())->sum('amount');
        $outstanding = max(0, $total - $paid);
        $status = match (true) {
            $dates['closing_date']->gte(CarbonImmutable::today()) => 'open',
            $paid <= 0 => 'closed',
            $paid < $total => 'partially_paid',
            default => 'paid',
        };

        return [
            'id' => $statement?->id,
            'credit_card_id' => $card->id,
            'month' => $month,
            'period_start' => $dates['period_start']->toDateString(),
            'closing_date' => $dates['closing_date']->toDateString(),
            'due_date' => $dates['due_date']->toDateString(),
            'total' => $total,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'status' => $status,
            'status_label' => [
                'open' => 'Aberta',
                'closed' => 'Fechada',
                'partially_paid' => 'Parcialmente paga',
                'paid' => 'Paga',
            ][$status],
            'installments' => $installments->sortBy(fn (CardInstallment $item): string => $item->purchase->purchase_date->format('Y-m-d').'-'.$item->id)
                ->map(fn (CardInstallment $item): array => [
                    'id' => $item->id,
                    'purchase_id' => $item->card_purchase_id,
                    'description' => $item->purchase->description,
                    'purchase_date' => $item->purchase->purchase_date->format('Y-m-d'),
                    'category_name' => $item->purchase->category->name,
                    'number' => (int) $item->installment_number,
                    'total_installments' => (int) $item->purchase->installments_count,
                    'amount' => (int) $item->amount,
                ])->values()->all(),
            'payments' => $payments->sortByDesc('payment_date')->map(fn (CardStatementPayment $payment): array => [
                'id' => $payment->id,
                'source_account_name' => $payment->sourceAccount->name,
                'amount' => (int) $payment->amount,
                'payment_date' => $payment->payment_date->format('Y-m-d'),
                'transaction_id' => $payment->transaction_id,
            ])->values()->all(),
        ];
    }

    private function generateCardInstallments(CreditCard $card, CardPurchase $purchase): void
    {
        $firstMonth = $this->statementMonthForDate($card, CarbonImmutable::parse($purchase->purchase_date));
        $firstMonthDate = CarbonImmutable::createFromFormat('Y-m-d', $firstMonth.'-01');
        $baseAmount = intdiv((int) $purchase->total_amount, (int) $purchase->installments_count);
        $remainder = (int) $purchase->total_amount % (int) $purchase->installments_count;

        for ($number = 1; $number <= $purchase->installments_count; $number++) {
            $month = $firstMonthDate->addMonthsNoOverflow($number - 1)->format('Y-m');
            $statement = $this->statementModel($card, $month);
            $amount = $baseAmount + ($number === 1 ? $remainder : 0);
            $installment = CardInstallment::create([
                'user_id' => $this->userId(),
                'card_purchase_id' => $purchase->id,
                'card_statement_id' => $statement->id,
                'installment_number' => $number,
                'amount' => $amount,
            ]);
            Transaction::create([
                'user_id' => $this->userId(),
                'description' => $purchase->description.' ('.$number.'/'.$purchase->installments_count.')',
                'type' => 'expense',
                'amount' => $amount,
                'date' => $statement->closing_date,
                'account_id' => $card->account_id,
                'category_id' => $purchase->category_id,
                'notes' => '',
                'reconciled' => false,
                'card_installment_id' => $installment->id,
            ]);
        }
    }

    private function cardPurchaseArray(CardPurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'credit_card_id' => $purchase->credit_card_id,
            'card_name' => $purchase->card->name,
            'category_id' => $purchase->category_id,
            'category_name' => $purchase->category->name,
            'description' => $purchase->description,
            'purchase_date' => $purchase->purchase_date->format('Y-m-d'),
            'total_amount' => (int) $purchase->total_amount,
            'installments_count' => (int) $purchase->installments_count,
            'can_change' => $this->cardPurchaseCanChange($purchase->id),
            'installments' => $purchase->installments->sortBy('installment_number')->map(fn (CardInstallment $item): array => [
                'id' => $item->id,
                'number' => (int) $item->installment_number,
                'amount' => (int) $item->amount,
                'statement_month' => $item->statement->month,
                'closing_date' => $item->statement->closing_date->format('Y-m-d'),
                'due_date' => $item->statement->due_date->format('Y-m-d'),
                'transaction_id' => $item->transaction()->value('id'),
            ])->values()->all(),
        ];
    }

    private function removePurchaseInstallments(CardPurchase $purchase): void
    {
        $purchase->installments()->get()->each(function (CardInstallment $installment): void {
            Transaction::query()->where('user_id', $this->userId())->where('card_installment_id', $installment->id)
                ->get()->each->forceDelete();
            $installment->forceDelete();
        });
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
            $attributes['card_installment'] = $model->cardInstallment ? [
                'purchase_id' => $model->cardInstallment->card_purchase_id,
                'installment_number' => (int) $model->cardInstallment->installment_number,
                'total_installments' => (int) $model->cardInstallment->purchase->installments_count,
                'card_name' => $model->cardInstallment->purchase->card->name,
                'statement_month' => $model->cardInstallment->statement->month,
            ] : null;
            $attributes['statement_payment'] = $model->statementPayment ? [
                'payment_id' => $model->statementPayment->id,
                'credit_card_id' => $model->statementPayment->statement->credit_card_id,
                'card_name' => $model->statementPayment->statement->card->name,
                'statement_month' => $model->statementPayment->statement->month,
            ] : null;
        }
        if ($model instanceof Budget) {
            $categories = $model->relationLoaded('categories') ? $model->categories : $model->categories()->orderBy('name')->get();
            $attributes['categories'] = $categories->map(fn (Category $category): array => $this->toArray($category))->all();
            $attributes['category_ids'] = $categories->pluck('id')->map(fn (int $id): int => $id)->all();
            unset($attributes['normalized_name']);
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

    private function normalizeBudgetName(string $name): string
    {
        return mb_strtolower(Str::squish($name));
    }

    private function prepareBudgetAttributes(array $attributes): array
    {
        $attributes['name'] = Str::squish($attributes['name']);
        $attributes['normalized_name'] = $this->normalizeBudgetName($attributes['name']);
        $attributes['active_name_key'] = hash('sha256', implode('|', [
            $this->userId(),
            $attributes['month'],
            $attributes['normalized_name'],
        ]));

        return $attributes;
    }

    private function userId(): int
    {
        return auth()->id() ?? throw new LogicException('FinanceStore requires an authenticated user.');
    }
}
