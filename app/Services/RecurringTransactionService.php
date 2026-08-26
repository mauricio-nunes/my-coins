<?php

namespace App\Services;

use App\Models\Account;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RecurringTransactionService
{
    public function create(int $userId, array $transactionAttributes, string $frequency, ?string $endDate): Transaction
    {
        return DB::transaction(function () use ($userId, $transactionAttributes, $frequency, $endDate): Transaction {
            $tagIds = $transactionAttributes['tag_ids'] ?? [];
            unset($transactionAttributes['tag_ids']);

            $rule = RecurringTransaction::create($this->ruleAttributes(
                $userId,
                (string) Str::uuid(),
                $transactionAttributes,
                $frequency,
                $endDate,
            ));
            $rule->tags()->sync($tagIds);

            $transaction = Transaction::create($transactionAttributes + [
                'user_id' => $userId,
                'recurring_transaction_id' => $rule->id,
                'recurrence_date' => $transactionAttributes['date'],
            ]);
            $transaction->tags()->sync($tagIds);
            $this->generate($rule, skipPast: true);

            return $transaction->refresh();
        });
    }

    public function generateAll(): int
    {
        $created = 0;
        RecurringTransaction::query()->where('status', 'active')->orderBy('id')->eachById(
            function (RecurringTransaction $rule) use (&$created): void {
                $created += $this->generate($rule);
            },
        );

        return $created;
    }

    public function generate(
        RecurringTransaction $rule,
        ?CarbonImmutable $horizon = null,
        bool $includeStart = false,
        bool $skipPast = false,
    ): int {
        return DB::transaction(function () use ($rule, $horizon, $includeStart, $skipPast): int {
            $locked = RecurringTransaction::query()->lockForUpdate()->find($rule->id);
            if (! $locked || $locked->status !== 'active') {
                return 0;
            }

            $account = Account::query()->where('user_id', $locked->user_id)->find($locked->account_id);
            if (! $account || $account->archived || ! $locked->category()->exists()) {
                $this->pauseLocked($locked, 'Conta ou categoria indisponível.');

                return 0;
            }

            $today = CarbonImmutable::today();
            $limit = $horizon ?? $today->addMonthsNoOverflow(12);
            if ($locked->end_date) {
                $limit = $limit->min(CarbonImmutable::parse($locked->end_date));
            }

            $created = 0;
            $tagIds = $locked->tags()->pluck('tags.id')->all();
            $previousGeneratedUntil = $locked->generated_until
                ? CarbonImmutable::parse($locked->generated_until)
                : null;
            foreach ($this->occurrenceDates($locked, $limit) as $index => $date) {
                if ((! $includeStart && $index === 0)
                    || ($previousGeneratedUntil && $date->lte($previousGeneratedUntil))
                    || ($skipPast && $date->lt($today))) {
                    continue;
                }
                $exists = Transaction::withTrashed()
                    ->where('recurring_transaction_id', $locked->id)
                    ->whereDate('recurrence_date', $date->toDateString())
                    ->exists();
                if ($exists) {
                    continue;
                }

                $transaction = Transaction::create([
                    'user_id' => $locked->user_id,
                    'description' => $locked->description,
                    'type' => $locked->type,
                    'amount' => $locked->amount,
                    'date' => $date->toDateString(),
                    'account_id' => $locked->account_id,
                    'category_id' => $locked->category_id,
                    'notes' => $locked->notes ?? '',
                    'recurring_transaction_id' => $locked->id,
                    'recurrence_date' => $date->toDateString(),
                ]);
                $transaction->tags()->sync($tagIds);
                $created++;
            }

            $locked->generated_until = $limit;
            if ($locked->end_date && CarbonImmutable::parse($locked->end_date)->lt($today)) {
                $locked->status = 'completed';
            }
            $locked->save();

            return $created;
        });
    }

    public function updateThisAndFuture(
        int $userId,
        int $transactionId,
        array $transactionAttributes,
        string $frequency,
        ?string $endDate,
    ): Transaction {
        return DB::transaction(function () use ($userId, $transactionId, $transactionAttributes, $frequency, $endDate): Transaction {
            $transaction = Transaction::query()->where('user_id', $userId)->lockForUpdate()->findOrFail($transactionId);
            $oldRule = RecurringTransaction::query()->where('user_id', $userId)->lockForUpdate()->findOrFail($transaction->recurring_transaction_id);
            $boundary = CarbonImmutable::parse($transaction->recurrence_date);
            $tagIds = $transactionAttributes['tag_ids'] ?? [];
            unset($transactionAttributes['tag_ids']);

            Transaction::query()
                ->where('user_id', $userId)
                ->where('recurring_transaction_id', $oldRule->id)
                ->whereDate('recurrence_date', '>', $boundary->toDateString())
                ->delete();
            $oldRule->update(['status' => 'superseded', 'end_date' => $boundary->subDay()->toDateString()]);

            $newRule = RecurringTransaction::create($this->ruleAttributes(
                $userId,
                $oldRule->series_uuid,
                $transactionAttributes,
                $frequency,
                $endDate,
            ));
            $newRule->tags()->sync($tagIds);

            $transaction->update($transactionAttributes + [
                'recurring_transaction_id' => $newRule->id,
                'recurrence_date' => $transactionAttributes['date'],
            ]);
            $transaction->tags()->sync($tagIds);
            $this->generate($newRule, skipPast: true);

            return $transaction->refresh();
        });
    }

    public function cancelFromOccurrence(int $userId, int $transactionId): bool
    {
        return DB::transaction(function () use ($userId, $transactionId): bool {
            $transaction = Transaction::query()->where('user_id', $userId)->lockForUpdate()->find($transactionId);
            if (! $transaction || ! $transaction->recurring_transaction_id) {
                return false;
            }
            $rule = RecurringTransaction::query()->where('user_id', $userId)->lockForUpdate()->find($transaction->recurring_transaction_id);
            if (! $rule) {
                return false;
            }
            $boundary = CarbonImmutable::parse($transaction->recurrence_date);
            Transaction::query()
                ->where('user_id', $userId)
                ->where('recurring_transaction_id', $rule->id)
                ->whereDate('recurrence_date', '>=', $boundary->toDateString())
                ->delete();
            $rule->update(['status' => 'cancelled', 'end_date' => $boundary->subDay()->toDateString()]);

            return true;
        });
    }

    public function cancel(int $userId, int $ruleId): bool
    {
        return DB::transaction(function () use ($userId, $ruleId): bool {
            $rule = RecurringTransaction::query()->where('user_id', $userId)->lockForUpdate()->find($ruleId);
            if (! $rule) {
                return false;
            }
            $today = CarbonImmutable::today();
            Transaction::query()
                ->where('user_id', $userId)
                ->where('recurring_transaction_id', $rule->id)
                ->whereDate('recurrence_date', '>=', $today->toDateString())
                ->delete();
            $rule->update(['status' => 'cancelled', 'end_date' => $today->subDay()->toDateString()]);

            return true;
        });
    }

    public function pauseForAccount(int $userId, int $accountId): int
    {
        $paused = 0;
        RecurringTransaction::query()
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('status', 'active')
            ->each(function (RecurringTransaction $rule) use (&$paused): void {
                DB::transaction(function () use ($rule, &$paused): void {
                    $locked = RecurringTransaction::query()->lockForUpdate()->find($rule->id);
                    if ($locked && $locked->status === 'active') {
                        $this->pauseLocked($locked, 'A conta vinculada foi arquivada.');
                        $paused++;
                    }
                });
            });

        return $paused;
    }

    public function resume(int $userId, int $ruleId, int $accountId): ?RecurringTransaction
    {
        return DB::transaction(function () use ($userId, $ruleId, $accountId): ?RecurringTransaction {
            $oldRule = RecurringTransaction::query()->where('user_id', $userId)->lockForUpdate()->find($ruleId);
            $account = Account::query()->where('user_id', $userId)->where('archived', false)->find($accountId);
            if (! $oldRule || $oldRule->status !== 'paused' || ! $account) {
                return null;
            }

            $start = $this->nextDateOnOrAfter($oldRule, CarbonImmutable::today());
            if (! $start || ($oldRule->end_date && $start->gt(CarbonImmutable::parse($oldRule->end_date)))) {
                return null;
            }

            $oldRule->update(['status' => 'superseded']);
            $attributes = [
                'description' => $oldRule->description,
                'type' => $oldRule->type,
                'amount' => $oldRule->amount,
                'date' => $start->toDateString(),
                'account_id' => $account->id,
                'category_id' => $oldRule->category_id,
                'notes' => $oldRule->notes ?? '',
            ];
            $newRule = RecurringTransaction::create($this->ruleAttributes(
                $userId,
                $oldRule->series_uuid,
                $attributes,
                $oldRule->frequency,
                $oldRule->end_date?->format('Y-m-d'),
            ));
            $newRule->tags()->sync($oldRule->tags()->pluck('tags.id')->all());
            $this->generate($newRule, includeStart: true);

            return $newRule->refresh();
        });
    }

    public function latestForUser(int $userId): EloquentCollection
    {
        return RecurringTransaction::query()
            ->where('user_id', $userId)
            ->with(['account', 'category', 'tags'])
            ->orderBy('id')
            ->get()
            ->groupBy('series_uuid')
            ->map(fn (EloquentCollection $versions): RecurringTransaction => $versions->last())
            ->sortByDesc('id')
            ->values();
    }

    public function findForUser(int $userId, int $ruleId): ?RecurringTransaction
    {
        return RecurringTransaction::query()->where('user_id', $userId)->find($ruleId);
    }

    public function nextOccurrence(RecurringTransaction $rule): ?Transaction
    {
        return $rule->transactions()
            ->whereDate('recurrence_date', '>=', CarbonImmutable::today()->toDateString())
            ->orderBy('recurrence_date')
            ->first();
    }

    private function ruleAttributes(
        int $userId,
        string $seriesUuid,
        array $transactionAttributes,
        string $frequency,
        ?string $endDate,
    ): array {
        return [
            'series_uuid' => $seriesUuid,
            'user_id' => $userId,
            'description' => $transactionAttributes['description'],
            'type' => $transactionAttributes['type'],
            'amount' => $transactionAttributes['amount'],
            'account_id' => $transactionAttributes['account_id'],
            'category_id' => $transactionAttributes['category_id'],
            'notes' => $transactionAttributes['notes'] ?? '',
            'frequency' => $frequency,
            'start_date' => $transactionAttributes['date'],
            'end_date' => $endDate,
            'status' => 'active',
            'paused_reason' => null,
        ];
    }

    private function occurrenceDates(RecurringTransaction $rule, CarbonImmutable $limit): array
    {
        $start = CarbonImmutable::parse($rule->start_date);
        $dates = [];
        for ($index = 0; $index < 1000; $index++) {
            $date = match ($rule->frequency) {
                'weekly' => $start->addWeeks($index),
                'monthly' => $start->addMonthsNoOverflow($index),
                'yearly' => $start->addYearsNoOverflow($index),
                default => throw new LogicException("Unsupported recurrence frequency: {$rule->frequency}"),
            };
            if ($date->gt($limit)) {
                break;
            }
            $dates[] = $date;
        }

        return $dates;
    }

    private function nextDateOnOrAfter(RecurringTransaction $rule, CarbonImmutable $minimum): ?CarbonImmutable
    {
        $limit = $minimum->addMonthsNoOverflow(12);
        $limit = $rule->end_date ? CarbonImmutable::parse($rule->end_date) : $limit;

        foreach ($this->occurrenceDates($rule, $limit) as $date) {
            if ($date->gte($minimum)) {
                return $date;
            }
        }

        return null;
    }

    private function pauseLocked(RecurringTransaction $rule, string $reason): void
    {
        Transaction::query()
            ->where('user_id', $rule->user_id)
            ->where('recurring_transaction_id', $rule->id)
            ->whereDate('recurrence_date', '>=', CarbonImmutable::today()->toDateString())
            ->delete();
        $rule->update(['status' => 'paused', 'paused_reason' => $reason]);
    }
}
