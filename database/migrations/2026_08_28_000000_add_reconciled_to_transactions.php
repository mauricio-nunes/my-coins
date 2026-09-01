<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('reconciled')->default(false)->after('notes');
            $table->index(['user_id', 'reconciled']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'reconciled']);
            $table->dropColumn('reconciled');
        });
    }
};
