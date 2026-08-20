@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <div class="text-center mb-4">
        <span class="login-icon"><i class="bi bi-wallet2"></i></span>
        <h2 class="h4 mt-3 mb-1">Bem-vindo de volta</h2>
        <p class="text-body-secondary mb-0">Acesse seu painel financeiro pessoal.</p>
    </div>

    @if (session('success'))<div class="alert alert-success small">{{ session('success') }}</div>@endif
    @if (session('warning'))<div class="alert alert-warning small">{{ session('warning') }}</div>@endif

    <form action="{{ route('login') }}" method="post">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">E-mail</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" class="form-control @error('email') is-invalid @enderror" required autofocus>
            </div>
            <x-field-error name="email" />
        </div>
        <div class="mb-4">
            <label for="password" class="form-label">Senha</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input id="password" type="password" name="password" autocomplete="current-password" class="form-control @error('password') is-invalid @enderror" required>
            </div>
            <x-field-error name="password" />
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">Entrar no My Coins <i class="bi bi-arrow-right ms-1"></i></button>
    </form>

    <p class="small text-body-secondary text-center mt-4 mb-0">Seus dados são privados e armazenados com segurança.</p>
@endsection
