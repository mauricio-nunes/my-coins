<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
        });

        DB::table('accounts')->orderBy('id')->get(['id', 'created_at'])->each(function (object $account): void {
            $firstTransactionDate = DB::table('transactions')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($account): void {
                    $query->where('account_id', $account->id)
                        ->orWhere('source_account_id', $account->id)
                        ->orWhere('destination_account_id', $account->id);
                })
                ->min('date');

            $fallbackDate = $account->created_at
                ? CarbonImmutable::parse($account->created_at)->toDateString()
                : CarbonImmutable::today()->toDateString();
            $openingBalanceDate = $firstTransactionDate
                ? min($firstTransactionDate, $fallbackDate)
                : $fallbackDate;

            DB::table('accounts')->where('id', $account->id)->update([
                'opening_balance_date' => $openingBalanceDate,
            ]);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->date('opening_balance_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('opening_balance_date');
        });
    }
};
