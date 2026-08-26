@extends('layouts.app')
@section('title', 'Recorrências')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Transações recorrentes')
@section('page_subtitle', 'Acompanhe e controle receitas e despesas que se repetem automaticamente.')
@section('page_actions')<a href="{{ route('transactions.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nova recorrência</a>@endsection

@section('page_content')
@php
    $frequencyLabels = ['weekly' => 'Semanal', 'monthly' => 'Mensal', 'yearly' => 'Anual'];
    $statusLabels = ['active' => 'Ativa', 'paused' => 'Pausada', 'cancelled' => 'Cancelada', 'completed' => 'Concluída', 'superseded' => 'Substituída'];
    $statusClasses = ['active' => 'text-bg-success', 'paused' => 'text-bg-warning', 'cancelled' => 'text-bg-secondary', 'completed' => 'text-bg-info', 'superseded' => 'text-bg-light'];
@endphp
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Séries recorrentes</h2><span class="badge text-bg-light">{{ $items->count() }} resultados</span></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Descrição</th><th>Periodicidade</th><th>Próxima ocorrência</th><th>Status</th><th class="text-end">Valor</th><th class="text-end">Ações</th></tr></thead><tbody>
    @forelse($items as $item)
        @php($rule = $item['rule'])
        <tr>
            <td><span class="fw-semibold">{{ $rule->description }}</span><div class="small text-body-secondary">{{ $rule->account?->name ?? 'Conta indisponível' }} · {{ $rule->category?->name ?? 'Categoria indisponível' }}</div>@if($rule->paused_reason)<div class="small text-warning-emphasis mt-1">{{ $rule->paused_reason }}</div>@endif</td>
            <td>{{ $frequencyLabels[$rule->frequency] }}@if($rule->end_date)<div class="small text-body-secondary">até {{ $rule->end_date->format('d/m/Y') }}</div>@else<div class="small text-body-secondary">sem data final</div>@endif</td>
            <td>{{ $item['next'] ? $item['next']->date->format('d/m/Y') : '—' }}</td>
            <td><span class="badge {{ $statusClasses[$rule->status] ?? 'text-bg-light' }}">{{ $statusLabels[$rule->status] ?? $rule->status }}</span></td>
            <td class="text-end fw-semibold {{ $rule->type === 'income' ? 'text-success' : 'text-danger' }}"><x-money :value="($rule->type === 'income' ? 1 : -1) * $rule->amount" :signed="true" /></td>
            <td class="text-end">
                @if($rule->status === 'active' && $item['next'])<a href="{{ route('recurrences.edit', $rule) }}" class="btn btn-sm btn-outline-primary" aria-label="Editar próximas ocorrências de {{ $rule->description }}"><i class="bi bi-pencil"></i></a>@endif
                @if($rule->status === 'active')<form method="post" action="{{ route('recurrences.destroy', $rule) }}" class="d-inline" data-confirm="Cancelar esta recorrência? As ocorrências de hoje em diante serão removidas.">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" aria-label="Cancelar recorrência {{ $rule->description }}"><i class="bi bi-x-circle"></i></button></form>@endif
            </td>
        </tr>
        @if($rule->status === 'paused')<tr><td colspan="6" class="bg-body-tertiary"><form method="post" action="{{ route('recurrences.resume', $rule) }}" class="row g-2 align-items-end">@csrf @method('patch')<div class="col-md-5"><label for="account-{{ $rule->id }}" class="form-label small">Nova conta ativa</label><select id="account-{{ $rule->id }}" name="account_id" class="form-select form-select-sm" required><option value="">Selecione</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }} · {{ $account['institution'] }}</option>@endforeach</select></div><div class="col-md-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-play-fill me-1"></i>Retomar recorrência</button></div></form></td></tr>@endif
    @empty
        <tr><td colspan="6" class="text-center py-5"><i class="bi bi-arrow-repeat fs-2 text-body-secondary"></i><p class="mt-2 mb-1 fw-semibold">Nenhuma recorrência cadastrada</p><p class="small text-body-secondary mb-3">Crie uma transação e ative a opção de repetição.</p><a href="{{ route('transactions.create') }}" class="btn btn-primary">Criar recorrência</a></td></tr>
    @endforelse
    </tbody></table></div>
</div>
@endsection
