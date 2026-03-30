<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmpleadoDashboardController;
use App\Http\Controllers\Api\EmployeeHistorialController;
use App\Http\Controllers\Api\AdminCompanyController;
use App\Http\Controllers\Api\AdminInvoiceController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::prefix('public')
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::post('/invoices/consult', [PublicInvoiceController::class, 'consult']);
        Route::post('/invoices/pdf', [PublicInvoiceController::class, 'pdf']);
    });

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/companies', [CompanyController::class, 'index']);

    Route::middleware('role:admin,super_admin')->group(function () {
        Route::get('/admin/companies', [AdminCompanyController::class, 'index']);
        Route::post('/admin/companies', [AdminCompanyController::class, 'store']);
        Route::put('/admin/companies/{company}', [AdminCompanyController::class, 'update']);
        Route::patch('/admin/companies/{company}/estado', [AdminCompanyController::class, 'updateEstado']);

        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{user}', [AdminUserController::class, 'update']);
        Route::patch('/admin/users/{user}/estado', [AdminUserController::class, 'updateEstado']);

        Route::get('/admin/invoices/available-services', [AdminInvoiceController::class, 'availableServices']);
        Route::get('/admin/invoices', [AdminInvoiceController::class, 'index']);
        Route::post('/admin/invoices', [AdminInvoiceController::class, 'store']);
        Route::get('/admin/invoices/{invoice}/pdf', [AdminInvoiceController::class, 'pdf']);
        Route::patch('/admin/invoices/{invoice}/status', [AdminInvoiceController::class, 'updateStatus']);
        Route::post('/admin/invoices/{invoice}/payments', [AdminInvoiceController::class, 'storePayment']);
        Route::delete('/admin/invoices/{invoice}/payments/{payment}', [AdminInvoiceController::class, 'destroyPayment']);
        Route::get('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'show']);
        Route::put('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'update']);
    });

    Route::get('/empleados', [UserController::class, 'empleadosActivos'])->middleware('role:admin,super_admin');
    Route::get('/admin/empleados/{user}/historial', [EmployeeHistorialController::class, 'forUser'])
        ->middleware('role:admin,super_admin');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->middleware('role:admin,super_admin');

    Route::get('/empleado/dashboard', [EmpleadoDashboardController::class, 'index'])->middleware('role:empleado');
    Route::get('/empleado/historial', [EmployeeHistorialController::class, 'mine'])->middleware('role:empleado');

    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::get('/services/{service}', [ServiceController::class, 'show']);
    Route::put('/services/{service}', [ServiceController::class, 'update'])->middleware('role:admin,super_admin');
    Route::patch('/services/{service}/archive', [ServiceController::class, 'archive'])->middleware('role:admin,super_admin');
});
