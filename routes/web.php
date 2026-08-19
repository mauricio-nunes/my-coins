<?php

use App\Http\Controllers\DemoAuthController;
use App\Http\Controllers\Finance\AccountController;
use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\CategoryController;
use App\Http\Controllers\Finance\DashboardController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Finance\ResetDemoController;
use App\Http\Controllers\Finance\TagController;
use App\Http\Controllers\Finance\TransactionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/login', [DemoAuthController::class, 'create'])->name('login');
Route::post('/login', [DemoAuthController::class, 'store']);
Route::post('/logout', [DemoAuthController::class, 'destroy'])->name('logout');

Route::middleware('demo.auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('transactions', TransactionController::class);
    Route::resource('accounts', AccountController::class);
    Route::resource('categories', CategoryController::class)->except('show');
    Route::resource('tags', TagController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
    Route::resource('budgets', BudgetController::class)->except('show');
    Route::get('/reports', ReportController::class)->name('reports.index');
    Route::post('/demo/reset', ResetDemoController::class)->name('demo.reset');
});
