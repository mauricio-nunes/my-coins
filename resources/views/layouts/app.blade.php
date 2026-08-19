@extends('adminlte::page')

@section('content_header')
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
        <div>
            <p class="eyebrow mb-1">@yield('eyebrow', 'MY COINS')</p>
            <h1 class="h3 mb-0">@yield('page_title')</h1>
            @hasSection('page_subtitle')<p class="text-body-secondary mb-0 mt-1">@yield('page_subtitle')</p>@endif
        </div>
        <div class="d-flex gap-2">@yield('page_actions')</div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button></div>
    @endif
    @if (session('warning'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>{{ session('warning') }}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button></div>
    @endif
    @yield('page_content')
@stop
