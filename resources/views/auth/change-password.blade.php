@extends('layouts.app')
@section('title', 'Alterar senha')
@section('eyebrow', 'SEGURANÇA')
@section('page_title', 'Crie uma nova senha')
@section('page_subtitle', auth()->user()->must_change_password ? 'A senha temporária precisa ser substituída antes de acessar seus dados.' : 'Atualize a senha usada para entrar no My Coins.')
@section('page_content')
<div class="row justify-content-center"><div class="col-lg-7 col-xl-6"><div class="card border-0 shadow-sm"><div class="card-body p-4 p-lg-5">
    <form method="post" action="{{ route('password.update') }}">@csrf @method('put')
        <div class="mb-3"><label for="current_password" class="form-label">Senha atual</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" class="form-control @error('current_password') is-invalid @enderror" required autofocus><x-field-error name="current_password" /></div>
        <div class="mb-3"><label for="password" class="form-label">Nova senha</label><input id="password" type="password" name="password" autocomplete="new-password" class="form-control @error('password') is-invalid @enderror" required aria-describedby="password-help"><div id="password-help" class="form-text">Use pelo menos 12 caracteres, com maiúscula, minúscula, número e símbolo.</div><x-field-error name="password" /></div>
        <div class="mb-4"><label for="password_confirmation" class="form-label">Confirme a nova senha</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" class="form-control" required></div>
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-shield-check me-1"></i> Salvar nova senha</button>
    </form>
</div></div></div></div>
@endsection
