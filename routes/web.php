<?php

use App\Http\Controllers\InventarioVehiculoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ValidacionDocumentosController;
use App\Http\Controllers\VehiculoIngresoController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth'])->group(function () {
    // 1. MÓDULO DE INVENTARIO (Para consulta general)
    Route::get('/vehiculos/inventario', [InventarioVehiculoController::class, 'index'])
        ->name('vehiculos.index');

    // 2. MÓDULO DE COMPRAS / INGRESO (Para registrar adquisiciones)
    Route::get('/vehiculos/ingreso', [VehiculoIngresoController::class, 'create'])
        ->name('vehiculos.create');
    Route::post('/vehiculos/ingreso', [VehiculoIngresoController::class, 'store'])
        ->name('vehiculos.store');

    Route::post('/vehiculos/consultar-axis', [VehiculoIngresoController::class, 'consultarAxisCloud'])
        ->name('vehiculos.consultar.axis');

    Route::post('/vehiculos/escanear-matricula', [VehiculoIngresoController::class, 'escanearYCompararMatricula'])
        ->name('vehiculos.escanear.matricula');

    Route::get('/validacion-documentos', [ValidacionDocumentosController::class, 'index'])
        ->name('validacion-documentos.index');
    Route::get('/validacion-documentos/{validacion}', [ValidacionDocumentosController::class, 'show'])
        ->name('validacion-documentos.show');
    Route::get('/validacion-documentos/{validacion}/documento', [ValidacionDocumentosController::class, 'documento'])
        ->name('validacion-documentos.documento');
    Route::patch('/validacion-documentos/{validacion}', [ValidacionDocumentosController::class, 'decidir'])
        ->name('validacion-documentos.decidir');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
