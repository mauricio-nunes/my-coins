<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(Request $request, FinanceStore $store): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:30'],
            'usage' => ['nullable', 'in:used,unused'],
        ]);
        $tags = collect($store->all('tags'))->map(
            fn (array $tag): array => $tag + ['usage_count' => $store->tagUsage($tag['id'])],
        )->when($filters['search'] ?? null, fn ($items, string $search) => $items->filter(
            fn (array $tag): bool => str_contains(mb_strtolower($tag['name']), mb_strtolower($search)),
        ))->when(($filters['usage'] ?? null) === 'used', fn ($items) => $items->where('usage_count', '>', 0))
            ->when(($filters['usage'] ?? null) === 'unused', fn ($items) => $items->where('usage_count', 0))
            ->sortBy('name')->values();

        return view('tags.index', compact('tags', 'filters'));
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $name = $this->validatedName($request, $store);
        $store->resolveTagIds([$name]);

        return redirect()->route('tags.index')->with('success', 'Tag adicionada com sucesso.');
    }

    public function edit(int $tag, FinanceStore $store): View
    {
        return view('tags.edit', ['tag' => $store->find('tags', $tag) ?? abort(404)]);
    }

    public function update(Request $request, int $tag, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('tags', $tag), 404);
        $name = $this->validatedName($request, $store, $tag);
        $store->renameTag($tag, $name);

        return redirect()->route('tags.index')->with('success', 'Tag renomeada em todas as transações.');
    }

    public function destroy(int $tag, FinanceStore $store): RedirectResponse
    {
        $usage = $store->tagUsage($tag);
        abort_unless($store->deleteTag($tag), 404);

        return redirect()->route('tags.index')->with(
            'success',
            trans_choice('Tag excluída e removida de :count transação.|Tag excluída e removida de :count transações.', $usage, ['count' => $usage]),
        );
    }

    private function validatedName(Request $request, FinanceStore $store, ?int $ignore = null): string
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:30']]);
        $name = preg_replace('/\s+/u', ' ', trim($validated['name'])) ?? trim($validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Informe um nome para a tag.']);
        }

        $existing = $store->findTagByName($name);
        if ($existing && $existing['id'] !== $ignore) {
            throw ValidationException::withMessages(['name' => 'Já existe uma tag com este nome.']);
        }

        return $name;
    }
}
