<?php

use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\CaseRatingController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriveSyncController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [GoogleLoginController::class, 'show'])->name('login');
    Route::get('/auth/google', [GoogleLoginController::class, 'redirect'])->name('auth.google');
    Route::get('/auth/google/callback', [GoogleLoginController::class, 'callback']);
    Route::post('/auth/dev', [GoogleLoginController::class, 'devLogin'])->name('auth.dev');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [GoogleLoginController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('cases', CaseController::class)->except('destroy');
    Route::post('/cases/{case}/rating', CaseRatingController::class)->name('cases.rate');

    Route::get('/consult', [ConsultationController::class, 'index'])->name('consult.index');
    Route::post('/consult', [ConsultationController::class, 'store'])->middleware('throttle:10,1')->name('consult.store');

    Route::get('/machines', [MachineController::class, 'index'])->name('machines.index');
    Route::post('/machines', [MachineController::class, 'store'])->name('machines.store');

    Route::middleware('admin')->group(function () {
        Route::delete('/cases/{case}', [CaseController::class, 'destroy'])->name('cases.destroy');
        Route::put('/machines/{machine}', [MachineController::class, 'update'])->name('machines.update');

        Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
        Route::post('/review/{case}', [ReviewController::class, 'decide'])->name('review.decide');

        Route::get('/drive', [DriveSyncController::class, 'index'])->name('drive.index');
        Route::post('/drive/run', [DriveSyncController::class, 'run'])->name('drive.run');
        Route::post('/drive/resolve', [DriveSyncController::class, 'resolve'])->name('drive.resolve');
    });
});
