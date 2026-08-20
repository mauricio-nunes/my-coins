<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Finance\AccountController;
use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\CategoryController;
use App\Http\Controllers\Finance\DashboardController;
use App\Http\Controllers\Finance\OfxImportController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Finance\TagController;
use App\Http\Controllers\Finance\TransactionController;
use App\Http\Controllers\Finance\TransferController;
use App\Http\Controllers\PasswordController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'password.changed'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/transactions/import', [OfxImportController::class, 'create'])->name('imports.create');
    Route::post('/transactions/import/preview', [OfxImportController::class, 'preview'])->name('imports.preview');
    Route::get('/transactions/import/review', [OfxImportController::class, 'review'])->name('imports.review');
    Route::post('/transactions/import', [OfxImportController::class, 'store'])->name('imports.store');
    Route::get('/transactions/import/result', [OfxImportController::class, 'result'])->name('imports.result');
    Route::resource('transactions', TransactionController::class);
    Route::resource('transfers', TransferController::class)->only(['create', 'store', 'edit', 'update']);
    Route::resource('accounts', AccountController::class);
    Route::resource('categories', CategoryController::class)->except('show');
    Route::resource('tags', TagController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
    Route::resource('budgets', BudgetController::class)->except('show');
    Route::get('/reports', ReportController::class)->name('reports.index');
});
