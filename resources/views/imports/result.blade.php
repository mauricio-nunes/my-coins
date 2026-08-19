@extends('layouts.app')
@section('title', 'Importação concluída')
@section('eyebrow', 'TRANSAÇÕES')
@section('page_title', 'Importação concluída')
@section('page_subtitle', 'Confira o resultado do processamento do arquivo OFX.')

@section('page_content')
<div class="row justify-content-center"><div class="col-xl-9">
    @include('imports.steps', ['step' => 3])
    <div class="card border-0 shadow-sm"><div class="card-body p-4 p-lg-5 text-center">
        <span class="import-success-icon mb-3"><i class="bi bi-check-lg"></i></span>
        <h2 class="h4">Seu extrato foi processado</h2><p class="text-body-secondary">{{ $result['file_name'] }} · {{ $result['account_name'] }}</p>
        <div class="row g-3 my-4 text-start">
            <div class="col-sm-6 col-lg-3"><div class="result-metric"><span>Importadas</span><strong class="text-success">{{ $result['imported'] }}</strong></div></div>
            <div class="col-sm-6 col-lg-3"><div class="result-metric"><span>Transferências</span><strong>{{ $result['transfers'] }}</strong></div></div>
            <div class="col-sm-6 col-lg-3"><div class="result-metric"><span>Ignoradas</span><strong>{{ $result['ignored'] }}</strong></div></div>
            <div class="col-sm-6 col-lg-3"><div class="result-metric"><span>Duplicadas</span><strong class="text-warning">{{ $result['duplicates'] }}</strong></div></div>
        </div>
        @if($result['tag'])<p class="mb-4">Os registros receberam a Tag <span class="badge text-bg-light border fw-normal">#{{ $result['tag']['name'] }}</span>.</p>@else<p class="text-body-secondary mb-4">Nenhum registro novo foi criado.</p>@endif
        <div class="d-flex flex-column flex-sm-row justify-content-center gap-2">@if($result['tag'])<a href="{{ route('transactions.index', ['tags' => [$result['tag']['id']]]) }}" class="btn btn-primary">Ver transações importadas</a>@else<a href="{{ route('transactions.index') }}" class="btn btn-primary">Ver transações</a>@endif<a href="{{ route('imports.create') }}" class="btn btn-outline-secondary">Importar outro arquivo</a></div>
    </div></div>
</div></div>
@endsection
