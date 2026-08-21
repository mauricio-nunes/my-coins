@extends('layouts.app')
@section('title', 'Classificar importação OFX')
@section('eyebrow', 'TRANSAÇÕES')
@section('page_title', 'Revise as movimentações')
@section('page_subtitle', 'Classifique cada registro ou marque os que não devem ser importados.')
@section('page_actions')<a href="{{ route('imports.create') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Trocar arquivo</a>@endsection

@section('page_content')
@include('imports.steps', ['step' => 2])
<div class="card border-0 shadow-sm mb-4"><div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3">
    <div><span class="text-body-secondary small d-block">Arquivo</span><strong>{{ $draft['file_name'] }}</strong></div>
    <div><span class="text-body-secondary small d-block">Conta</span><strong>{{ $account['name'] }}</strong></div>
    <div><span class="text-body-secondary small d-block">Tag</span><span class="badge text-bg-light border fw-normal">#{{ $draft['label'] }}</span></div>
    <div><span class="text-body-secondary small d-block">Movimentações</span><strong>{{ count($draft['rows']) }}</strong></div>
</div></div>

@if($errors->any())<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle me-2"></i>Revise os campos destacados antes de concluir a importação.</div>@endif

<form method="post" action="{{ route('imports.store') }}" data-import-review>
    @csrf
    <input type="hidden" name="draft_token" value="{{ $draft['token'] }}">
    <div class="card border-0 shadow-sm"><div class="card-header bg-transparent border-0 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2"><div><h2 class="h5 mb-1">Movimentações encontradas</h2><p class="small text-body-secondary mb-0">Receitas e despesas exigem categoria. Transferências exigem conta de destino.</p></div><span class="badge text-bg-light">{{ collect($draft['rows'])->where('duplicate', true)->count() }} duplicadas</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0 import-table"><thead><tr><th>Movimentação</th><th>Tipo</th><th class="text-end">Valor</th><th>Ignorar</th><th>Transferência</th><th>Classificação</th><th>Conta de destino</th></tr></thead><tbody>
        @foreach($draft['rows'] as $index => $row)
            @php
                $decision = old("rows.$index", []);
                $hasPreviousSubmission = old('rows') !== null;
                $ignored = (bool) ($decision['ignore'] ?? false);
                $isTransfer = (bool) ($decision['is_transfer'] ?? false);
                $categoryId = $hasPreviousSubmission ? ($decision['category_id'] ?? '') : ($row['suggested_category_id'] ?? '');
                $destinationId = $decision['destination_account_id'] ?? '';
                $locked = (bool) $row['duplicate'];
                $automaticSuggestionSelected = ($row['suggested_category_id'] ?? null) && $categoryId == $row['suggested_category_id'];
            @endphp
            <tr data-import-row data-locked="{{ $locked ? 'true' : 'false' }}" class="{{ $locked ? 'table-light opacity-75' : '' }}">
                <td class="import-description"><strong>{{ $row['description'] }}</strong><span class="small text-body-secondary d-block">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }} · FITID {{ $row['fitid'] }}</span>@if($locked)<span class="badge text-bg-warning mt-1">Já importada</span>@endif</td>
                <td><span class="badge rounded-pill {{ $row['type'] === 'income' ? 'text-bg-success' : 'text-bg-danger' }}">{{ $row['ofx_type'] === 'CREDIT' ? 'Receita' : 'Despesa' }}</span></td>
                <td class="text-end fw-semibold {{ $row['type'] === 'income' ? 'text-success' : 'text-danger' }}"><x-money :value="($row['type'] === 'income' ? 1 : -1) * $row['amount']" :signed="true" /></td>
                <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ignore-{{ $index }}" name="rows[{{ $index }}][ignore]" value="1" data-import-ignore @checked($ignored || $locked) @disabled($locked)><label class="form-check-label small" for="ignore-{{ $index }}">Ignorar</label></div></td>
                <td>@if($row['type'] === 'expense' && !$locked)<div class="form-check form-switch"><input class="form-check-input @error("rows.$index.is_transfer") is-invalid @enderror" type="checkbox" role="switch" id="transfer-{{ $index }}" name="rows[{{ $index }}][is_transfer]" value="1" data-import-transfer @checked($isTransfer)><label class="form-check-label small" for="transfer-{{ $index }}">É transferência</label></div>@else<span class="text-body-secondary small">Não disponível</span>@endif @error("rows.$index.is_transfer")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</td>
                <td><label class="visually-hidden" for="category-{{ $index }}">Categoria de {{ $row['description'] }}</label><select id="category-{{ $index }}" name="rows[{{ $index }}][category_id]" data-import-category class="form-select form-select-sm import-select @error("rows.$index.category_id") is-invalid @enderror" @disabled($locked)><option value="">Selecione</option>@foreach($categories->where('type', $row['type']) as $category)<option value="{{ $category['id'] }}" @selected($categoryId == $category['id'])>{{ $category['name'] }}</option>@endforeach</select>@if($automaticSuggestionSelected && !$locked)<span class="badge text-bg-info mt-1" title="Correspondência: {{ $row['suggested_keyword'] }}"><i class="bi bi-magic me-1"></i>Sugerida automaticamente</span>@endif @error("rows.$index.category_id")<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
                <td><label class="visually-hidden" for="destination-{{ $index }}">Conta de destino de {{ $row['description'] }}</label><select id="destination-{{ $index }}" name="rows[{{ $index }}][destination_account_id]" data-import-destination class="form-select form-select-sm import-select @error("rows.$index.destination_account_id") is-invalid @enderror" @disabled($locked || !$isTransfer)><option value="">Selecione</option>@foreach($accounts->where('id', '!=', $account['id']) as $destination)<option value="{{ $destination['id'] }}" @selected($destinationId == $destination['id'])>{{ $destination['name'] }}</option>@endforeach</select>@error("rows.$index.destination_account_id")<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
            </tr>
        @endforeach
        </tbody></table></div>
        <div class="card-footer bg-transparent d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3"><p class="small text-body-secondary mb-0"><i class="bi bi-shield-check me-1"></i>Duplicatas serão verificadas novamente ao concluir.</p><button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-check me-1"></i> Importar movimentações</button></div>
    </div>
</form>
@endsection
