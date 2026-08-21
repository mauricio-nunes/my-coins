<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AutomaticCategorizationController extends Controller
{
    public function index(FinanceStore $store): View
    {
        return view('category-mappings.index', [
            'categories' => $store->automaticCategorizationCategories()->groupBy('type'),
        ]);
    }

    public function update(Request $request, int $category, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('categories', $category), 404);
        $validated = $request->validate([
            'mapping_category_id' => ['required', 'integer', 'in:'.$category],
            'keywords' => ['nullable', 'array', 'max:50'],
            'keywords.*' => ['required', 'string', 'max:120'],
        ]);
        $keywords = $validated['keywords'] ?? [];
        $normalized = collect($keywords)->map(fn (string $keyword): string => Str::lower(Str::ascii(Str::squish($keyword))));
        if ($normalized->contains('') || $normalized->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['keywords' => 'Cada palavra ou frase deve ser única nesta categoria.']);
        }

        $store->syncCategoryKeywords($category, $keywords);

        return redirect()->to(route('category-mappings.index').'#category-'.$category)
            ->with('success', 'Palavras-chave atualizadas com sucesso.');
    }

    public function move(Request $request, int $category, FinanceStore $store): RedirectResponse
    {
        $validated = $request->validate(['direction' => ['required', 'in:up,down']]);
        abort_unless($store->moveCategoryPriority($category, $validated['direction']), 404);

        return redirect()->to(route('category-mappings.index').'#category-'.$category)
            ->with('success', 'Prioridade de categorização atualizada.');
    }
}
