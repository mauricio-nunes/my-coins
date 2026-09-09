@extends('layouts.app')
@php($editing = (bool) $card)
@section('title', $editing ? 'Editar cartão' : 'Novo cartão')
@section('eyebrow', 'CARTÕES DE CRÉDITO')
@section('page_title', $editing ? 'Editar cartão' : 'Novo cartão')
@section('page_subtitle', 'Defina o limite e o ciclo mensal da fatura.')
@section('page_actions')<a href="{{ $editing ? route('credit-cards.show', $card['id']) : route('credit-cards.index') }}" class="btn btn-outline-secondary">Cancelar</a>@endsection
@section('page_content')
<div class="row"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="post" action="{{ $editing ? route('credit-cards.update', $card['id']) : route('credit-cards.store') }}">@csrf @if($editing)@method('put')@endif
<div class="row g-3">
<div class="col-md-6"><label class="form-label" for="name">Nome do cartão</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $card['name'] ?? '') }}" required><x-field-error name="name" /></div>
<div class="col-md-6"><label class="form-label" for="network">Bandeira</label><input class="form-control @error('network') is-invalid @enderror" id="network" name="network" value="{{ old('network', $card['network'] ?? '') }}" placeholder="Visa, Mastercard, Elo…" required><x-field-error name="network" /></div>
<div class="col-md-6"><label class="form-label" for="credit_limit">Limite total</label><div class="input-group"><span class="input-group-text">R$</span><input class="form-control @error('credit_limit') is-invalid @enderror" id="credit_limit" name="credit_limit" value="{{ old('credit_limit', $card ? number_format($card['credit_limit']/100, 2, ',', '') : '') }}" required></div><x-field-error name="credit_limit" />@if($editing)<div class="form-text">Já utilizado: <x-money :value="$card['used_limit']" />.</div>@endif</div>
<div class="col-md-6"><label class="form-label" for="default_payment_account_id">Conta padrão para pagamento</label><select class="form-select @error('default_payment_account_id') is-invalid @enderror" id="default_payment_account_id" name="default_payment_account_id" required><option value="">Selecione</option>@foreach($paymentAccounts as $account)<option value="{{ $account['id'] }}" @selected(old('default_payment_account_id', $card['default_payment_account_id'] ?? '') == $account['id'])>{{ $account['name'] }}</option>@endforeach</select><x-field-error name="default_payment_account_id" /></div>
<div class="col-md-4"><label class="form-label" for="closing_day">Dia de fechamento</label><input type="number" min="1" max="31" class="form-control @error('closing_day') is-invalid @enderror" id="closing_day" name="closing_day" value="{{ old('closing_day', $card['closing_day'] ?? 10) }}" required><x-field-error name="closing_day" /></div>
<div class="col-md-4"><label class="form-label" for="due_day">Dia de vencimento</label><input type="number" min="1" max="31" class="form-control @error('due_day') is-invalid @enderror" id="due_day" name="due_day" value="{{ old('due_day', $card['due_day'] ?? 17) }}" required><x-field-error name="due_day" /></div>
<div class="col-md-4"><label class="form-label" for="color">Cor</label><input type="color" class="form-control form-control-color w-100" id="color" name="color" value="{{ old('color', $card['color'] ?? '#6f42c1') }}" required></div>
<div class="col-12"><div class="alert alert-info mb-0"><i class="bi bi-calendar3 me-2"></i>Em meses curtos, dias 29, 30 ou 31 são ajustados automaticamente para o último dia do mês. Compras no dia do fechamento entram na fatura daquele ciclo.</div></div>
</div><div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ $editing ? route('credit-cards.show', $card['id']) : route('credit-cards.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary">{{ $editing ? 'Salvar alterações' : 'Cadastrar cartão' }}</button></div></form></div></div></div></div>
@endsection
