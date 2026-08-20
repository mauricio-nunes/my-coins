<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\DefaultCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(FinanceStore $store): View
    {
        return view('categories.index', ['categories' => collect($store->all('categories'))->groupBy('type')]);
    }

    public function create(): View
    {
        return view('categories.form', ['category' => null, 'icons' => DefaultCategories::iconOptions()]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $store->create('categories', $this->validated($request));

        return redirect()->route('categories.index')->with('success', 'Categoria adicionada com sucesso.');
    }

    public function edit(int $category, FinanceStore $store): View
    {
        return view('categories.form', [
            'category' => $store->find('categories', $category) ?? abort(404),
            'icons' => DefaultCategories::iconOptions(),
        ]);
    }

    public function update(Request $request, int $category, FinanceStore $store): RedirectResponse
    {
        $existing = $store->find('categories', $category) ?? abort(404);
        $validated = $this->validated($request);
        if ($existing['type'] !== $validated['type'] && $store->categoryIsUsed($category)) {
            throw ValidationException::withMessages(['type' => 'O tipo não pode ser alterado enquanto a categoria estiver em uso.']);
        }
        $store->update('categories', $category, $validated);

        return redirect()->route('categories.index')->with('success', 'Categoria atualizada com sucesso.');
    }

    public function destroy(int $category, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('categories', $category), 404);
        if ($store->categoryIsUsed($category)) {
            return back()->with('warning', 'A categoria está em uso e não pode ser excluída.');
        }
        $store->delete('categories', $category);

        return back()->with('success', 'Categoria excluída.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:income,expense'],
            'icon' => ['required', 'regex:/^bi-[a-z0-9-]+$/'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }
}
