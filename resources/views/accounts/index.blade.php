@extends('layouts.app')
@section('title', 'Contas')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Contas e carteiras')
@section('page_subtitle', 'Veja onde seu dinheiro está e acompanhe cada saldo.')
@section('page_actions')<a href="{{ route('accounts.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nova conta</a>@endsection
@section('page_content')
<div class="row g-4">
@foreach($accounts as $account)
    <div class="col-md-6 col-xl-4"><div class="card account-card border-0 shadow-sm h-100 {{ $account['archived'] ? 'opacity-75' : '' }}" style="--account-color: {{ $account['color'] }}"><div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-4"><span class="account-symbol"><i class="bi {{ match($account['type']) {'cash'=>'bi-cash-stack','savings'=>'bi-piggy-bank','investment'=>'bi-graph-up-arrow',default=>'bi-bank'} }}"></i></span>@if($account['archived'])<span class="badge text-bg-secondary">Arquivada</span>@else<a href="{{ route('accounts.edit', $account['id']) }}" class="btn btn-sm btn-light" aria-label="Editar {{ $account['name'] }}"><i class="bi bi-three-dots"></i></a>@endif</div>
        <p class="text-body-secondary mb-1">{{ $account['institution'] }}</p><h2 class="h5 mb-4">{{ $account['name'] }}</h2><div class="text-body-secondary small">Saldo atual</div><div class="h3 mt-1 mb-4"><x-money :value="$account['balance']" /></div><a href="{{ route('accounts.show', $account['id']) }}" class="stretched-link text-decoration-none">Ver movimentações <i class="bi bi-arrow-right ms-1"></i></a>
    </div></div></div>
@endforeach
</div>
@endsection
