<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('institution', 80)->nullable();
            $table->string('type', 20);
            $table->string('color', 7);
            $table->bigInteger('opening_balance')->default(0);
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'archived']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 10);
            $table->string('icon', 60);
            $table->string('color', 7);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'type']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('normalized_name', 30);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'normalized_name']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description', 120)->default('');
            $table->string('type', 10);
            $table->unsignedBigInteger('amount');
            $table->date('date');
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('destination_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('ofx_type', 20)->nullable();
            $table->string('ofx_fitid', 500)->nullable();
            $table->foreignId('ofx_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('active_ofx_key', 64)->nullable()->unique();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('tag_transaction', function (Blueprint $table): void {
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['transaction_id', 'tag_id']);
        });

        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->char('month', 7);
            $table->unsignedBigInteger('limit');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('tag_transaction');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
    }
};
