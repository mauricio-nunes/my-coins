@extends('layouts.app')
@section('title', $account['name'])
@section('eyebrow', 'CONTA')
@section('page_title', $account['name'])
@section('page_subtitle', $account['institution'])
@section('page_actions')@unless($account['archived'])<a href="{{ route('transfers.create', ['source_account_id' => $account['id']]) }}" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right me-1"></i> Transferir</a>@endunless<a href="{{ route('accounts.edit', $account['id']) }}" class="btn btn-primary"><i class="bi bi-pencil me-1"></i> Editar</a>@endsection
@section('page_content')
<div class="row g-4"><div class="col-lg-4"><div class="card account-summary border-0 shadow-sm" style="--account-color: {{ $account['color'] }}"><div class="card-body p-4"><span class="small text-body-secondary">Saldo atual</span><div class="display-6 fw-semibold my-2"><x-money :value="$account['balance']" /></div><div class="small text-body-secondary mb-3">Saldo inicial de <x-money :value="$account['opening_balance']" /> em {{ \Carbon\Carbon::parse($account['opening_balance_date'])->format('d/m/Y') }}</div><span class="badge {{ $account['archived'] ? 'text-bg-secondary' : 'text-bg-success-subtle text-success' }}">{{ $account['archived'] ? 'Arquivada' : 'Ativa' }}</span></div></div>
@if($hasTransactionsBeforeOpeningBalance)<div class="alert alert-warning mt-4 mb-0" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>Esta conta possui lançamentos anteriores à data do saldo inicial. Os saldos, a evolução e os indicadores podem não ser apresentados corretamente.</div>@endif
@unless($account['archived'])<div class="card border-0 shadow-sm mt-4"><div class="card-body"><h2 class="h6">Arquivar conta</h2><p class="small text-body-secondary">A conta sai dos seletores, mas todo o histórico é preservado.</p><form method="post" action="{{ route('accounts.destroy', $account['id']) }}" data-confirm="Arquivar esta conta?">@csrf @method('delete')<button class="btn btn-outline-danger w-100">Arquivar conta</button></form></div></div>@endunless</div>
<div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-header bg-transparent border-0"><h2 class="h5 mb-0">Movimentações recentes</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Descrição</th><th>Data</th><th class="text-end">Valor</th></tr></thead><tbody>
@forelse($transactions as $transaction)
    @php
        $isTransfer = $transaction['type'] === 'transfer';
        $source = $isTransfer ? $accounts[$transaction['source_account_id']] : null;
        $destination = $isTransfer ? $accounts[$transaction['destination_account_id']] : null;
        $description = $transaction['description'] ?: "Transferência de {$source['name']} para {$destination['name']}";
        $effect = $isTransfer ? ($transaction['source_account_id'] === $account['id'] ? -$transaction['amount'] : $transaction['amount']) : ($transaction['type'] === 'income' ? $transaction['amount'] : -$transaction['amount']);
    @endphp
    <tr><td><a href="{{ route('transactions.show', $transaction['id']) }}" class="text-body fw-medium text-decoration-none">{{ $description }}</a><div class="small text-body-secondary">{{ $isTransfer ? ($effect < 0 ? 'Transferência enviada para '.$destination['name'] : 'Transferência recebida de '.$source['name']) : $categories[$transaction['category_id']]['name'] }}</div></td><td>{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}</td><td class="text-end {{ $effect >= 0 ? 'text-success' : 'text-danger' }}"><x-money :value="$effect" :signed="true" /></td></tr>
@empty<tr><td colspan="3" class="text-center py-4">Nenhuma movimentação.</td></tr>@endforelse
</tbody></table></div></div></div></div>
@endsection
