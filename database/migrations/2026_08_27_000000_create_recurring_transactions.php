<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('series_uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description', 120);
            $table->string('type', 10);
            $table->unsignedBigInteger('amount');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('frequency', 10);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('generated_until')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('paused_reason', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'series_uuid']);
        });

        Schema::create('recurring_transaction_tag', function (Blueprint $table): void {
            $table->foreignId('recurring_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['recurring_transaction_id', 'tag_id'], 'recurring_transaction_tag_primary');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('recurring_transaction_id')->nullable()->after('category_id')->constrained()->restrictOnDelete();
            $table->date('recurrence_date')->nullable()->after('recurring_transaction_id');
            $table->unique(['recurring_transaction_id', 'recurrence_date'], 'transactions_recurrence_occurrence_unique');
            $table->index(['user_id', 'recurring_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['recurring_transaction_id']);
            $table->dropUnique('transactions_recurrence_occurrence_unique');
            $table->dropIndex(['user_id', 'recurring_transaction_id']);
            $table->dropColumn(['recurring_transaction_id', 'recurrence_date']);
        });

        Schema::dropIfExists('recurring_transaction_tag');
        Schema::dropIfExists('recurring_transactions');
    }
};
