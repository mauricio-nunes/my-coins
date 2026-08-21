@extends('layouts.app')
@section('title', 'Categorização automática')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Categorização automática')
@section('page_subtitle', 'Associe palavras ou frases às categorias para agilizar a revisão de arquivos OFX.')

@section('page_content')
@php
    $failedCategoryId = (int) old('mapping_category_id', 0);
@endphp
<div class="alert alert-info d-flex gap-2 align-items-start" role="note">
    <i class="bi bi-info-circle mt-1"></i>
    <div>A busca ignora maiúsculas, acentos e espaços repetidos. Quando mais de uma categoria corresponder, vence a primeira na ordem de prioridade do mesmo tipo.</div>
</div>
<div class="row g-4">
    @foreach(['expense' => ['Despesas', 'bi-arrow-down-circle', 'danger'], 'income' => ['Receitas', 'bi-arrow-up-circle', 'success']] as $type => [$title, $icon, $color])
        @php
            $items = $categories->get($type, collect())->values();
        @endphp
        <div class="col-12 col-xxl-6">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h5 mb-0"><i class="bi {{ $icon }} text-{{ $color }} me-2"></i>{{ $title }}</h2>
                <span class="badge text-bg-light">{{ $items->count() }} categorias</span>
            </div>
            <div class="d-grid gap-3">
                @foreach($items as $position => $category)
                    @php
                        $keywords = $failedCategoryId === $category['id']
                            ? old('keywords', [])
                            : collect($category['keywords'])->pluck('keyword')->all();
                        $keywordConfig = ['create' => true, 'persist' => false, 'plugins' => ['remove_button'], 'maxItems' => 50, 'createOnBlur' => true];
                    @endphp
                    <section id="category-{{ $category['id'] }}" class="card border-0 shadow-sm mb-0 category-mapping-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <span class="category-icon" style="--category-color: {{ $category['color'] }}"><i class="bi {{ $category['icon'] }}"></i></span>
                                <div class="flex-grow-1 min-width-0"><h3 class="h6 mb-1">{{ $category['name'] }}</h3><span class="small text-body-secondary">Prioridade {{ $position + 1 }}</span></div>
                                <div class="btn-group" role="group" aria-label="Alterar prioridade de {{ $category['name'] }}">
                                    <form method="post" action="{{ route('category-mappings.move', $category['id']) }}">@csrf @method('patch')<input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary" aria-label="Aumentar prioridade de {{ $category['name'] }}" @disabled($loop->first)><i class="bi bi-arrow-up"></i></button></form>
                                    <form method="post" action="{{ route('category-mappings.move', $category['id']) }}">@csrf @method('patch')<input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary" aria-label="Diminuir prioridade de {{ $category['name'] }}" @disabled($loop->last)><i class="bi bi-arrow-down"></i></button></form>
                                </div>
                            </div>
                            <form method="post" action="{{ route('category-mappings.update', $category['id']) }}">
                                @csrf @method('put')
                                <input type="hidden" name="mapping_category_id" value="{{ $category['id'] }}">
                                <label for="keywords-{{ $category['id'] }}" class="form-label">Palavras e frases</label>
                                <select id="keywords-{{ $category['id'] }}" name="keywords[]" multiple aria-label="Palavras e frases para {{ $category['name'] }}" data-tom-select data-tom-select-config='@json($keywordConfig)' class="form-select {{ $failedCategoryId === $category['id'] && $errors->has('keywords') ? 'is-invalid' : '' }}" placeholder="Digite um termo e pressione Enter">
                                    @foreach($keywords as $keyword)<option value="{{ $keyword }}" selected>{{ $keyword }}</option>@endforeach
                                </select>
                                @if($failedCategoryId === $category['id'])<x-field-error name="keywords" />@endif
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mt-3"><span class="small text-body-secondary">Ex.: supermercado, posto central, mensalidade</span><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i> Salvar termos</button></div>
                            </form>
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
