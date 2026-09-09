<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('default_payment_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('network', 40);
            $table->unsignedBigInteger('credit_limit');
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'archived']);
        });

        Schema::create('card_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('description', 120);
            $table->date('purchase_date');
            $table->unsignedBigInteger('total_amount');
            $table->unsignedSmallInteger('installments_count');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'purchase_date']);
        });

        Schema::create('card_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained()->restrictOnDelete();
            $table->char('month', 7);
            $table->date('period_start');
            $table->date('closing_date');
            $table->date('due_date');
            $table->timestamps();
            $table->unique(['credit_card_id', 'month']);
            $table->index(['user_id', 'closing_date']);
        });

        Schema::create('card_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('card_statement_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['card_purchase_id', 'installment_number'], 'card_installments_purchase_number_unique');
            $table->index(['user_id', 'card_statement_id']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('card_installment_id')->nullable()->after('recurrence_date')->unique()->constrained('card_installments')->restrictOnDelete();
        });

        Schema::create('card_statement_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_statement_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('payment_date');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'card_statement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_statement_payments');
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('card_installment_id');
        });
        Schema::dropIfExists('card_installments');
        Schema::dropIfExists('card_statements');
        Schema::dropIfExists('card_purchases');
        Schema::dropIfExists('credit_cards');
    }
};
