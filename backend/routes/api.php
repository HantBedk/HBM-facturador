<?php

use App\Http\Controllers\Api\AdminActivityLogController;
use App\Http\Controllers\Api\AdminCompanyController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminExportController;
use App\Http\Controllers\Api\AdminInvoiceController;
use App\Http\Controllers\Api\AdminPanelNotificationController;
use App\Http\Controllers\Api\AdminServiceCatalogController;
use App\Http\Controllers\Api\AdminServiceCatalogSuggestionController;
use App\Http\Controllers\Api\AdminTechnicianCatalogPricingController;
use App\Http\Controllers\Api\AdminCorreoSolicitudController;
use App\Http\Controllers\Api\AdminEmpleadoNotificacionSettingsController;
use App\Http\Controllers\Api\AdminEmpleadoPerfilController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\EmpleadoCorreoSolicitudController;
use App\Http\Controllers\Api\EmpleadoDashboardController;
use App\Http\Controllers\Api\EmpleadoNotificacionPreferenciasController;
use App\Http\Controllers\Api\EmpleadoPanelNotificationController;
use App\Http\Controllers\Api\EmpleadoPasswordController;
use App\Http\Controllers\Api\EmpleadoPerfilController;
use App\Http\Controllers\Api\EmployeeHistorialController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\ServiceCatalogController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:15,1');

Route::prefix('public')
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::post('/invoices/consult', [PublicInvoiceController::class, 'consult']);
        Route::post('/invoices/pdf', [PublicInvoiceController::class, 'pdf']);
    });

Route::middleware(['auth:sanctum', 'throttle:180,1'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/service-catalog/active', [ServiceCatalogController::class, 'active']);

    Route::middleware('role:admin,super_admin')->group(function () {
        Route::get('/admin/companies', [AdminCompanyController::class, 'index']);
        Route::post('/admin/companies', [AdminCompanyController::class, 'store']);
        Route::put('/admin/companies/{company}', [AdminCompanyController::class, 'update']);
        Route::patch('/admin/companies/{company}/estado', [AdminCompanyController::class, 'updateEstado']);

        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{user}', [AdminUserController::class, 'update']);
        Route::patch('/admin/users/{user}/estado', [AdminUserController::class, 'updateEstado']);
        Route::post('/admin/users/{user}/correo-solicitud/approve', [AdminCorreoSolicitudController::class, 'approve']);
        Route::post('/admin/users/{user}/correo-solicitud/reject', [AdminCorreoSolicitudController::class, 'reject']);
        Route::get('/admin/users/{user}/empleado-perfil', [AdminEmpleadoPerfilController::class, 'show']);
        Route::post('/admin/users/{user}/empleado-perfil/borrar-datos-pago', [AdminEmpleadoPerfilController::class, 'clearDatosPago']);
        Route::get('/admin/settings/empleado-notificaciones', [AdminEmpleadoNotificacionSettingsController::class, 'show']);
        Route::put('/admin/settings/empleado-notificaciones', [AdminEmpleadoNotificacionSettingsController::class, 'update']);

        Route::get('/admin/service-catalog', [AdminServiceCatalogController::class, 'index']);
        Route::post('/admin/service-catalog', [AdminServiceCatalogController::class, 'store']);
        Route::get('/admin/service-catalog/technician-pricing', [AdminTechnicianCatalogPricingController::class, 'show']);
        Route::put('/admin/service-catalog/technician-pricing', [AdminTechnicianCatalogPricingController::class, 'update']);
        Route::put('/admin/service-catalog/{service_catalog}', [AdminServiceCatalogController::class, 'update']);
        Route::patch('/admin/service-catalog/{service_catalog}/estado', [AdminServiceCatalogController::class, 'updateEstado']);

        Route::get('/admin/service-catalog-suggestions', [AdminServiceCatalogSuggestionController::class, 'index']);
        Route::post('/admin/service-catalog-suggestions/{service_catalog_suggestion}/approve', [AdminServiceCatalogSuggestionController::class, 'approve']);
        Route::post('/admin/service-catalog-suggestions/{service_catalog_suggestion}/reject', [AdminServiceCatalogSuggestionController::class, 'reject']);

        Route::get('/admin/notifications/unread-count', [AdminPanelNotificationController::class, 'unreadCount']);
        Route::get('/admin/notifications', [AdminPanelNotificationController::class, 'index']);
        Route::patch('/admin/notifications/{panel_notification}/read', [AdminPanelNotificationController::class, 'markRead']);
        Route::post('/admin/notifications/read-all', [AdminPanelNotificationController::class, 'readAll']);

        Route::get('/admin/export/services', [AdminExportController::class, 'services']);
        Route::get('/admin/export/invoices', [AdminExportController::class, 'invoices']);
        Route::get('/admin/activity-logs', [AdminActivityLogController::class, 'index']);

        Route::get('/admin/invoices/available-services', [AdminInvoiceController::class, 'availableServices']);
        Route::get('/admin/invoices', [AdminInvoiceController::class, 'index']);
        Route::post('/admin/invoices', [AdminInvoiceController::class, 'store']);
        Route::get('/admin/invoices/{invoice}/pdf', [AdminInvoiceController::class, 'pdf']);
        Route::patch('/admin/invoices/{invoice}/status', [AdminInvoiceController::class, 'updateStatus']);
        Route::post('/admin/invoices/{invoice}/payments', [AdminInvoiceController::class, 'storePayment']);
        Route::delete('/admin/invoices/{invoice}/payments/{payment}', [AdminInvoiceController::class, 'destroyPayment']);
        Route::post('/admin/invoices/{invoice}/public-access-token', [AdminInvoiceController::class, 'regeneratePublicAccess']);
        Route::get('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'show']);
        Route::put('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'update']);
    });

    Route::get('/empleados', [UserController::class, 'empleadosActivos'])->middleware('role:admin,super_admin');
    Route::get('/admin/empleados/{user}/historial', [EmployeeHistorialController::class, 'forUser'])
        ->middleware('role:admin,super_admin');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->middleware('role:admin,super_admin');

    Route::get('/empleado/dashboard', [EmpleadoDashboardController::class, 'index'])->middleware('role:empleado');
    Route::get('/empleado/historial', [EmployeeHistorialController::class, 'mine'])->middleware('role:empleado');
    Route::get('/empleado/perfil', [EmpleadoPerfilController::class, 'show'])->middleware('role:empleado');
    Route::put('/empleado/perfil', [EmpleadoPerfilController::class, 'update'])->middleware('role:empleado');
    Route::post('/empleado/correo-solicitud', [EmpleadoCorreoSolicitudController::class, 'store'])->middleware('role:empleado');
    Route::delete('/empleado/correo-solicitud', [EmpleadoCorreoSolicitudController::class, 'destroy'])->middleware('role:empleado');
    Route::get('/empleado/notifications/unread-count', [EmpleadoPanelNotificationController::class, 'unreadCount'])->middleware('role:empleado');
    Route::get('/empleado/notifications', [EmpleadoPanelNotificationController::class, 'index'])->middleware('role:empleado');
    Route::patch('/empleado/notifications/{panel_notification}/read', [EmpleadoPanelNotificationController::class, 'markRead'])->middleware('role:empleado');
    Route::post('/empleado/notifications/read-all', [EmpleadoPanelNotificationController::class, 'readAll'])->middleware('role:empleado');
    Route::put('/empleado/password', [EmpleadoPasswordController::class, 'update'])->middleware('role:empleado');
    Route::get('/empleado/notificaciones-preferencias', [EmpleadoNotificacionPreferenciasController::class, 'show'])->middleware('role:empleado');
    Route::put('/empleado/notificaciones-preferencias', [EmpleadoNotificacionPreferenciasController::class, 'update'])->middleware('role:empleado');

    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::get('/services/{service}', [ServiceController::class, 'show']);
    Route::put('/services/{service}', [ServiceController::class, 'update']);
    Route::patch('/services/{service}/archive', [ServiceController::class, 'archive']);
});
