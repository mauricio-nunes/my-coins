@extends('layouts.app')
@php
    $selectedLabel = old('label', '');
    $tagSelectConfig = json_encode([
        'create' => true,
        'persist' => false,
        'maxItems' => 1,
        'placeholder' => 'Selecione ou digite para criar...',
        'items' => $selectedLabel !== '' ? [$selectedLabel] : [],
    ]);
@endphp
@section('title', 'Importar OFX')
@section('eyebrow', 'TRANSAÇÕES')
@section('page_title', 'Importar transações')
@section('page_subtitle', 'Envie um extrato OFX para revisar e classificar as movimentações antes da importação.')
@section('page_actions')<a href="{{ route('transactions.index') }}" class="btn btn-outline-secondary">Cancelar</a>@endsection

@section('page_content')
<div class="row justify-content-center"><div class="col-xl-9">
    @include('imports.steps', ['step' => 1])
    <div class="card border-0 shadow-sm"><div class="card-body p-4 p-lg-5">
        <div class="d-flex align-items-start gap-3 mb-4"><span class="import-icon"><i class="bi bi-file-earmark-arrow-up"></i></span><div><h2 class="h5 mb-1">Selecione o extrato</h2><p class="text-body-secondary mb-0">O arquivo será usado apenas nesta sessão e não será armazenado após a leitura.</p></div></div>
        <form method="post" action="{{ route('imports.preview') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-4">
                <div class="col-12"><label for="ofx_file" class="form-label">Arquivo OFX</label><input id="ofx_file" name="ofx_file" type="file" accept=".ofx,application/x-ofx" class="form-control @error('ofx_file') is-invalid @enderror" required aria-describedby="ofx-help"><div id="ofx-help" class="form-text">Formato .ofx, moeda BRL e tamanho máximo de 2 MB.</div><x-field-error name="ofx_file" /></div>
                <div class="col-md-6"><label for="account_id" class="form-label">Conta</label><select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" required><option value="">Selecione a conta do extrato</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}" @selected(old('account_id') == $account['id'])>{{ $account['name'] }} · {{ $account['institution'] ?: 'Sem instituição' }}</option>@endforeach</select><x-field-error name="account_id" /></div>
                <div class="col-md-6"><label for="label" class="form-label">Tag da importação</label><select id="label" name="label" aria-label="Tag da importação" class="form-select @error('label') is-invalid @enderror" data-tom-select data-tom-select-config="{{ $tagSelectConfig }}"><option value=""></option>@foreach($tags as $tag)<option value="{{ $tag['name'] }}" @selected($selectedLabel === $tag['name'])>{{ $tag['name'] }}</option>@endforeach @if($selectedLabel !== '' && !$tags->contains('name', $selectedLabel))<option value="{{ $selectedLabel }}" selected>{{ $selectedLabel }}</option>@endif</select><div class="form-text">A Tag será aplicada a todos os registros importados. Digite um nome para criar uma nova.</div><x-field-error name="label" /></div>
            </div>
            <div class="d-flex justify-content-end mt-4"><button type="submit" class="btn btn-primary"><span>Continuar para classificação</span><i class="bi bi-arrow-right ms-2"></i></button></div>
        </form>
    </div></div>
</div></div>
@endsection
