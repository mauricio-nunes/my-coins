<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('user_id');
            $table->string('normalized_name', 100)->nullable()->after('name');
            $table->string('active_name_key', 64)->nullable()->unique()->after('normalized_name');
        });

        Schema::create('budget_category', function (Blueprint $table): void {
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->primary(['budget_id', 'category_id']);
        });

        $usedKeys = [];
        DB::table('budgets')->orderBy('id')->get()->each(function (object $budget) use (&$usedKeys): void {
            $category = DB::table('categories')->where('id', $budget->category_id)->first();
            $baseName = Str::squish((string) ($category->name ?? 'Orçamento'));
            $name = $baseName;
            $normalized = mb_strtolower($name);
            $key = hash('sha256', implode('|', [$budget->user_id, $budget->month, $normalized]));
            if (isset($usedKeys[$key])) {
                $name = "{$baseName} #{$budget->id}";
                $normalized = mb_strtolower($name);
                $key = hash('sha256', implode('|', [$budget->user_id, $budget->month, $normalized]));
            }
            $usedKeys[$key] = true;

            DB::table('budgets')->where('id', $budget->id)->update([
                'name' => $name,
                'normalized_name' => $normalized,
                'active_name_key' => $budget->deleted_at === null ? $key : null,
            ]);
            DB::table('budget_category')->insert([
                'budget_id' => $budget->id,
                'category_id' => $budget->category_id,
            ]);
        });

        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });
        DB::table('budget_category')->orderBy('budget_id')->get()->groupBy('budget_id')->each(function ($rows, $budgetId): void {
            DB::table('budgets')->where('id', $budgetId)->update(['category_id' => $rows->first()->category_id]);
        });
        Schema::dropIfExists('budget_category');
        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropUnique(['active_name_key']);
            $table->dropColumn(['name', 'normalized_name', 'active_name_key']);
        });
    }
};
