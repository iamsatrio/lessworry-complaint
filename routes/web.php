<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComplaintAttachmentController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\ComplaintLinkController;
use App\Http\Controllers\ComplaintNoteController;
use App\Http\Controllers\ComplaintResponsibleController;
use App\Http\Controllers\ComplaintStatusController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NeviraLookupController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

// auth.session memutus sesi yang hash passwordnya sudah basi — itu yang
// membuat penggantian password benar-benar berlaku ke perangkat lain.
Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Ganti password harus bisa diakses walau password sementara belum diganti.
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'auth.session', 'active', 'password.changed'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('/complaints/create', [ComplaintController::class, 'create'])->name('complaints.create');
    Route::post('/complaints', [ComplaintController::class, 'store'])->name('complaints.store');
    Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
    Route::post('/complaints/{complaint}/status', [ComplaintStatusController::class, 'update'])->name('complaints.status');
    Route::post('/complaints/{complaint}/assign', [ComplaintController::class, 'assign'])->name('complaints.assign');
    Route::post('/complaints/{complaint}/note', [ComplaintNoteController::class, 'store'])->name('complaints.note');
    Route::post('/complaints/{complaint}/resync', [ComplaintLinkController::class, 'resync'])->name('complaints.resync');
    Route::put('/complaints/{complaint}/link', [ComplaintLinkController::class, 'update'])->name('complaints.link');
    Route::get('/complaints/{complaint}/lampiran/{attachment}', [ComplaintAttachmentController::class, 'show'])->name('complaints.attachment');
    Route::get('/complaints/{complaint}/lampiran/{attachment}/kecil', [ComplaintAttachmentController::class, 'thumb'])->name('complaints.attachment.thumb');
    // Pelaku complaint — beberapa orang per complaint, wewenangnya dicek
    // ComplaintPolicy::manageResponsible (Customer Care dan supervisor
    // saja). (API-19)
    Route::post('/complaints/{complaint}/pelaku', [ComplaintResponsibleController::class, 'store'])->name('complaints.responsibles.store');
    Route::put('/complaints/{complaint}/pelaku/{responsible}', [ComplaintResponsibleController::class, 'update'])->name('complaints.responsibles.update');
    Route::delete('/complaints/{complaint}/pelaku/{responsible}', [ComplaintResponsibleController::class, 'destroy'])->name('complaints.responsibles.destroy');

    // Dibatasi lajunya: tanpa ini nomor nota bisa dicoba satu per satu.
    Route::get('/nevira/lookup', NeviraLookupController::class)
        ->middleware('throttle:20,1')
        ->name('nevira.lookup');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    // Pengelolaan pengguna — hanya supervisor (dicek di controller).
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
});
