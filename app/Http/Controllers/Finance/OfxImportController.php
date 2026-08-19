<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\DemoFinanceStore;
use App\Services\OfxParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class OfxImportController extends Controller
{
    private const DRAFT_KEY = 'my_coins.ofx_import_draft';

    private const RESULT_KEY = 'my_coins.ofx_import_result';

    public function create(DemoFinanceStore $store): View
    {
        return view('imports.create', $this->formData($store));
    }

    public function preview(Request $request, DemoFinanceStore $store, OfxParser $parser): RedirectResponse
    {
        $validated = $request->validate([
            'ofx_file' => ['required', 'file', 'max:2048'],
            'account_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:30'],
        ]);
        $account = $store->find('accounts', (int) $validated['account_id']);
        if (! $account || $account['archived']) {
            throw ValidationException::withMessages(['account_id' => 'Selecione uma conta ativa.']);
        }

        $file = $request->file('ofx_file');
        if (! $file || strtolower($file->getClientOriginalExtension()) !== 'ofx') {
            throw ValidationException::withMessages(['ofx_file' => 'Envie um arquivo com a extensão .ofx.']);
        }

        try {
            $contents = $file->get();
            if (! is_string($contents)) {
                throw new InvalidArgumentException('Não foi possível ler o arquivo OFX.');
            }
            $rows = $parser->parse($contents);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['ofx_file' => $exception->getMessage()]);
        }

        $seen = [];
        foreach ($rows as $index => $row) {
            $key = $row['fitid'];
            $rows[$index]['duplicate'] = isset($seen[$key]) || $store->hasImportedOfxTransaction($account['id'], $key);
            $seen[$key] = true;
        }

        $request->session()->put(self::DRAFT_KEY, [
            'token' => Str::random(40),
            'file_name' => $file->getClientOriginalName(),
            'account_id' => $account['id'],
            'label' => trim(preg_replace('/\s+/u', ' ', $validated['label']) ?? $validated['label']),
            'rows' => $rows,
        ]);
        $request->session()->forget(self::RESULT_KEY);

        return redirect()->route('imports.review');
    }

    public function review(Request $request, DemoFinanceStore $store): View|RedirectResponse
    {
        $draft = $request->session()->get(self::DRAFT_KEY);
        if (! is_array($draft)) {
            return redirect()->route('imports.create')->with('warning', 'Envie um arquivo OFX antes de revisar as movimentações.');
        }

        $account = $store->find('accounts', $draft['account_id']);
        if (! $account || $account['archived']) {
            $request->session()->forget(self::DRAFT_KEY);

            return redirect()->route('imports.create')->with('warning', 'A conta selecionada não está mais disponível.');
        }

        return view('imports.review', [
            'draft' => $draft,
            'account' => $account,
            'accounts' => collect($store->all('accounts'))->where('archived', false)->values(),
            'categories' => collect($store->all('categories')),
        ]);
    }

    public function store(Request $request, DemoFinanceStore $store): RedirectResponse
    {
        $draft = $request->session()->get(self::DRAFT_KEY);
        if (! is_array($draft)) {
            return redirect()->route('imports.create')->with('warning', 'A prévia expirou. Envie o arquivo novamente.');
        }

        $validated = $request->validate([
            'draft_token' => ['required', 'string'],
            'rows' => ['nullable', 'array'],
            'rows.*.ignore' => ['nullable', 'boolean'],
            'rows.*.category_id' => ['nullable', 'integer'],
            'rows.*.is_transfer' => ['nullable', 'boolean'],
            'rows.*.destination_account_id' => ['nullable', 'integer'],
        ]);
        if (! hash_equals($draft['token'], $validated['draft_token'])) {
            $request->session()->forget(self::DRAFT_KEY);

            return redirect()->route('imports.create')->with('warning', 'A prévia expirou. Envie o arquivo novamente.');
        }

        $account = $store->find('accounts', $draft['account_id']);
        if (! $account || $account['archived']) {
            throw ValidationException::withMessages(['account' => 'A conta selecionada não está mais ativa.']);
        }

        $decisions = $validated['rows'] ?? [];
        $transactions = [];
        $errors = [];
        $ignored = 0;
        $previewDuplicates = 0;

        foreach ($draft['rows'] as $index => $row) {
            if ($row['duplicate'] || $store->hasImportedOfxTransaction($account['id'], $row['fitid'])) {
                $previewDuplicates++;

                continue;
            }

            $decision = $decisions[$index] ?? [];
            if ((bool) ($decision['ignore'] ?? false)) {
                $ignored++;

                continue;
            }

            $common = [
                'description' => $row['description'],
                'amount' => $row['amount'],
                'date' => $row['date'],
                'notes' => $row['fitid'],
                'ofx_type' => $row['ofx_type'],
                'ofx_fitid' => $row['fitid'],
                'ofx_account_id' => $account['id'],
                'imported_at' => now()->toIso8601String(),
            ];

            $isTransfer = (bool) ($decision['is_transfer'] ?? false);
            if ($isTransfer) {
                if ($row['type'] !== 'expense') {
                    $errors["rows.{$index}.is_transfer"] = 'Somente débitos podem ser importados como transferência.';

                    continue;
                }
                $destinationId = (int) ($decision['destination_account_id'] ?? 0);
                $destination = $store->find('accounts', $destinationId);
                if (! $destination || $destination['archived'] || $destinationId === $account['id']) {
                    $errors["rows.{$index}.destination_account_id"] = 'Selecione outra conta ativa como destino.';

                    continue;
                }
                $transactions[] = $common + [
                    'type' => 'transfer',
                    'source_account_id' => $account['id'],
                    'destination_account_id' => $destinationId,
                    'account_id' => null,
                    'category_id' => null,
                ];

                continue;
            }

            $categoryId = (int) ($decision['category_id'] ?? 0);
            $category = $store->find('categories', $categoryId);
            if (! $category || $category['type'] !== $row['type']) {
                $errors["rows.{$index}.category_id"] = 'Selecione uma categoria compatível com o tipo.';

                continue;
            }
            $transactions[] = $common + [
                'type' => $row['type'],
                'account_id' => $account['id'],
                'category_id' => $categoryId,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $result = $transactions === []
            ? ['tag' => null, 'transactions' => [], 'duplicates' => 0]
            : $store->importOfxTransactions($account['id'], $draft['label'], $transactions);
        $summary = [
            'file_name' => $draft['file_name'],
            'account_name' => $account['name'],
            'tag' => $result['tag'],
            'imported' => count($result['transactions']),
            'ignored' => $ignored,
            'duplicates' => $previewDuplicates + $result['duplicates'],
            'transfers' => collect($result['transactions'])->where('type', 'transfer')->count(),
        ];

        $request->session()->forget(self::DRAFT_KEY);
        $request->session()->put(self::RESULT_KEY, $summary);

        return redirect()->route('imports.result');
    }

    public function result(Request $request): View|RedirectResponse
    {
        $result = $request->session()->get(self::RESULT_KEY);
        if (! is_array($result)) {
            return redirect()->route('imports.create');
        }

        return view('imports.result', ['result' => $result]);
    }

    private function formData(DemoFinanceStore $store): array
    {
        return [
            'accounts' => collect($store->all('accounts'))->where('archived', false)->values(),
            'tags' => collect($store->all('tags'))->sortBy('name')->values(),
        ];
    }
}
