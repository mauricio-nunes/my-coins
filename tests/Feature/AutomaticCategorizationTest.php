<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\User;
use App\Services\FinanceStore;
use Tests\TestCase;

class AutomaticCategorizationTest extends TestCase
{
    public function test_user_can_manage_normalized_keywords_for_a_category(): void
    {
        $this->signInWithFinanceData();
        $categoryId = $this->categoryId('Alimentação');

        $this->put("/category-mappings/{$categoryId}", [
            'mapping_category_id' => $categoryId,
            'keywords' => [' Supermercado ', 'São   José'],
        ])->assertRedirect("/category-mappings#category-{$categoryId}");

        $this->assertDatabaseHas('category_keywords', [
            'category_id' => $categoryId,
            'keyword' => 'São José',
            'normalized_keyword' => 'sao jose',
        ]);
        $this->get('/category-mappings')->assertOk()->assertSee('Supermercado')->assertSee('São José');

        $this->put("/category-mappings/{$categoryId}", [
            'mapping_category_id' => $categoryId,
            'keywords' => ['São José', 'sao jose'],
        ])->assertSessionHasErrors('keywords');
    }

    public function test_first_compatible_category_in_configured_order_wins(): void
    {
        $this->signInWithFinanceData();
        $housingId = $this->categoryId('Moradia');
        $foodId = $this->categoryId('Alimentação');
        $workId = $this->categoryId('Trabalho');
        $store = app(FinanceStore::class);
        $store->syncCategoryKeywords($housingId, ['mercado']);
        $store->syncCategoryKeywords($foodId, ['supermercado são josé']);
        $store->syncCategoryKeywords($workId, ['supermercado']);

        $this->assertSame($housingId, $store->suggestCategory('expense', '  SUPERMERCADO   SAO JOSÉ  ')['category_id']);
        $this->assertSame($workId, $store->suggestCategory('income', 'Supermercado São José')['category_id']);
        $this->assertNull($store->suggestCategory('expense', 'Posto de combustível'));

        $this->patch("/category-mappings/{$foodId}/position", ['direction' => 'up'])
            ->assertRedirect("/category-mappings#category-{$foodId}");
        $this->assertSame($foodId, app(FinanceStore::class)->suggestCategory('expense', 'Supermercado São José')['category_id']);
    }

    public function test_keyword_management_is_scoped_to_the_authenticated_owner(): void
    {
        $this->signInWithFinanceData();
        $other = User::factory()->create(['must_change_password' => false]);
        $category = Category::create([
            'user_id' => $other->id,
            'name' => 'Categoria privada',
            'type' => 'expense',
            'match_priority' => 1,
            'icon' => 'bi-lock',
            'color' => '#000000',
        ]);

        $this->put("/category-mappings/{$category->id}", [
            'mapping_category_id' => $category->id,
            'keywords' => ['segredo'],
        ])->assertNotFound();
        $this->patch("/category-mappings/{$category->id}/position", ['direction' => 'up'])->assertNotFound();
        $this->assertSame(0, CategoryKeyword::where('category_id', $category->id)->count());
    }
}
