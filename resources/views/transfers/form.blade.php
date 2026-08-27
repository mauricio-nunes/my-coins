@extends('layouts.app')
@php
    $editing = (bool) $transfer;
    $returnTo = $returnTo ?? null;
    $cancelTarget = $returnTo ?: ($editing ? route('transactions.show', $transfer['id']) : route('transactions.index'));
@endphp
@section('title', $editing ? 'Editar transferência' : 'Nova transferência')
@section('eyebrow', 'TRANSFERÊNCIAS')
@section('page_title', $editing ? 'Editar transferência' : 'Transferir entre contas')
@section('page_subtitle', 'Mova dinheiro sem alterar receitas, despesas ou orçamentos.')
@section('page_actions')<a href="{{ $cancelTarget }}" class="btn btn-outline-secondary">Cancelar</a>@endsection

@section('page_content')
<div class="row"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
<form method="post" action="{{ $editing ? route('transfers.update', $transfer['id']) : route('transfers.store') }}" data-transfer-form>
    @csrf @if($editing) @method('put') @endif
    @if($editing && $returnTo)<input type="hidden" name="return_to" value="{{ $returnTo }}">@endif
    <div class="row g-3">
        <div class="col-md-6"><label for="source_account_id" class="form-label">Conta de origem</label><select id="source_account_id" name="source_account_id" class="form-select @error('source_account_id') is-invalid @enderror" required><option value="">Selecione</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}" @selected(old('source_account_id', $transfer['source_account_id'] ?? $defaultSourceId) == $account['id'])>{{ $account['name'] }} · {{ $account['institution'] }}{{ $account['archived'] ? ' (Arquivada)' : '' }}</option>@endforeach</select><x-field-error name="source_account_id" /></div>
        <div class="col-md-6"><label for="destination_account_id" class="form-label">Conta de destino</label><select id="destination_account_id" name="destination_account_id" class="form-select @error('destination_account_id') is-invalid @enderror" required><option value="">Selecione</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}" @selected(old('destination_account_id', $transfer['destination_account_id'] ?? '') == $account['id'])>{{ $account['name'] }} · {{ $account['institution'] }}{{ $account['archived'] ? ' (Arquivada)' : '' }}</option>@endforeach</select><x-field-error name="destination_account_id" /></div>
        <div class="col-md-6"><label for="amount" class="form-label">Valor</label><div class="input-group"><span class="input-group-text">R$</span><input id="amount" name="amount" inputmode="decimal" value="{{ old('amount', $transfer ? number_format($transfer['amount'] / 100, 2, ',', '') : '') }}" class="form-control @error('amount') is-invalid @enderror" placeholder="0,00" required></div><x-field-error name="amount" /></div>
        <div class="col-md-6"><label for="date" class="form-label">Data efetiva</label><input id="date" type="date" name="date" value="{{ old('date', $transfer['date'] ?? now()->format('Y-m-d')) }}" class="form-control @error('date') is-invalid @enderror" required><x-field-error name="date" /></div>
        <div class="col-12"><label for="description" class="form-label">Descrição <span class="text-body-secondary fw-normal">(opcional)</span></label><input id="description" name="description" value="{{ old('description', $transfer['description'] ?? '') }}" maxlength="120" class="form-control @error('description') is-invalid @enderror" placeholder="Ex.: Reserva do mês"><x-field-error name="description" /></div>
        <div class="col-12"><input type="hidden" name="reconciled" value="0"><div class="form-check form-switch"><input id="reconciled" name="reconciled" value="1" type="checkbox" role="switch" class="form-check-input @error('reconciled') is-invalid @enderror" @checked(old('reconciled', $transfer['reconciled'] ?? false))><label for="reconciled" class="form-check-label fw-semibold">Transferência conciliada</label></div><div class="form-text">Este status é apenas organizacional e não altera saldos, orçamentos ou relatórios.</div><x-field-error name="reconciled" /></div>
    </div>
    <div class="alert alert-light border d-flex gap-2 mt-4 mb-0" role="note"><i class="bi bi-info-circle text-primary"></i><span class="small">A transferência reduz a origem e aumenta o destino na data efetiva, sem contar como receita ou despesa.</span></div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ $cancelTarget }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-left-right me-1"></i>{{ $editing ? 'Salvar alterações' : 'Confirmar transferência' }}</button></div>
</form>
</div></div></div></div>
@endsection
