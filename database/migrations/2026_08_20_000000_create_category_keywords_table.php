<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedInteger('match_priority')->default(0)->after('type');
            $table->index(['user_id', 'type', 'match_priority']);
        });

        DB::table('categories')
            ->select(['id', 'user_id', 'type'])
            ->orderBy('user_id')
            ->orderBy('type')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $category): string => $category->user_id.'|'.$category->type)
            ->each(function ($categories): void {
                foreach ($categories->values() as $index => $category) {
                    DB::table('categories')->where('id', $category->id)->update(['match_priority' => $index + 1]);
                }
            });

        Schema::create('category_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->string('normalized_keyword', 120);
            $table->timestamps();
            $table->unique(['user_id', 'category_id', 'normalized_keyword'], 'category_keywords_owner_category_unique');
            $table->index(['user_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_keywords');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'type', 'match_priority']);
            $table->dropColumn('match_priority');
        });
    }
};
