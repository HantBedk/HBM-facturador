<?php

use App\Http\Controllers\Api\AdminActivityLogController;
use App\Http\Controllers\Api\AdminBackupController;
use App\Http\Controllers\Api\AdminBillingAutomationController;
use App\Http\Controllers\Api\AdminCompanyController;
use App\Http\Controllers\Api\AdminCompanyRecurringServiceController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminExportController;
use App\Http\Controllers\Api\AdminRecurringFixedChargesSpreadsheetController;
use App\Http\Controllers\Api\AdminInvoiceController;
use App\Http\Controllers\Api\AdminMailNotificationsSettingsController;
use App\Http\Controllers\Api\AdminSystemOrganizationController;
use App\Http\Controllers\Api\AdminInventoryHolderSettingsController;
use App\Http\Controllers\Api\AdminInventoryLocationSettingsController;
use App\Http\Controllers\Api\AdminPanelNotificationController;
use App\Http\Controllers\Api\AdminServiceCatalogController;
use App\Http\Controllers\Api\AdminServiceRegistrySpreadsheetController;
use App\Http\Controllers\Api\AdminServiceCatalogSuggestionController;
use App\Http\Controllers\Api\AdminTechnicianCatalogPricingController;
use App\Http\Controllers\Api\AdminCorreoSolicitudController;
use App\Http\Controllers\Api\AdminEmpleadoNotificacionSettingsController;
use App\Http\Controllers\Api\AdminEmpleadoPerfilController;
use App\Http\Controllers\Api\AdminEmpleadoTechnicianPaymentController;
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
use App\Http\Controllers\Api\InventoryLotAttachmentController;
use App\Http\Controllers\Api\InventoryLotController;
use App\Http\Controllers\Api\InventoryLotLifecycleSheetController;
use App\Http\Controllers\Api\InventoryHolderOptionController;
use App\Http\Controllers\Api\InventoryLocationOptionController;
use App\Http\Controllers\Api\InventoryOwnerAccrualController;
use App\Http\Controllers\Api\InventoryAuditEventController;
use App\Http\Controllers\Api\InventoryLifecycleController;
use App\Http\Controllers\Api\InventoryEmpleadoCommercialSettingsController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\InventoryRentalController;
use App\Http\Controllers\Api\InventorySaleController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\PublicCompanyInventoryController;
use App\Http\Controllers\Api\ServiceCatalogController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// SSE streams — auth via ticket de un solo uso (no middleware Sanctum)
Route::get('/admin/notifications/stream', [AdminPanelNotificationController::class, 'stream'])
    ->middleware('throttle:30,1');
Route::get('/empleado/notifications/stream', [EmpleadoPanelNotificationController::class, 'stream'])
    ->middleware('throttle:30,1');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,15');

Route::post('/auth/forgot-password', [AuthController::class, 'forgotPasswordRequest'])
    ->middleware('throttle:8,60');

Route::prefix('public')
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::post('/invoices/consult', [PublicInvoiceController::class, 'consult']);
        Route::post('/invoices/pdf', [PublicInvoiceController::class, 'pdf']);
        Route::post('/company-inventory/otp/request', [PublicCompanyInventoryController::class, 'requestOtp']);
        Route::post('/company-inventory/otp/verify', [PublicCompanyInventoryController::class, 'verifyOtp']);
        Route::get('/company-inventory/lots', [PublicCompanyInventoryController::class, 'lots']);
    });

Route::middleware(['auth:sanctum', 'throttle:180,1'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/inventory/lots', [InventoryLotController::class, 'index']);
    Route::get('/inventory/lots/{inventory_lot}', [InventoryLotController::class, 'show']);
    Route::get('/inventory/lots/{inventory_lot}/lifecycle-sheet', InventoryLotLifecycleSheetController::class);
    Route::get('/inventory/lots/{inventory_lot}/attachments', [InventoryLotAttachmentController::class, 'index']);
    Route::post('/inventory/lots/{inventory_lot}/attachments', [InventoryLotAttachmentController::class, 'store']);
    Route::delete('/inventory/lots/{inventory_lot}/attachments/{attachment}', [InventoryLotAttachmentController::class, 'destroy']);
    Route::get('/inventory/movements', InventoryMovementController::class);
    Route::get('/inventory/empleado-commercial-settings', InventoryEmpleadoCommercialSettingsController::class);
    Route::get('/inventory/location-options', InventoryLocationOptionController::class);
    Route::get('/inventory/holder-options', InventoryHolderOptionController::class);
    Route::get('/inventory/sales', [InventorySaleController::class, 'index']);
    Route::post('/inventory/sales', [InventorySaleController::class, 'store']);
    Route::delete('/inventory/sales/{inventory_sale}', [InventorySaleController::class, 'destroy']);
    Route::get('/inventory/rentals', [InventoryRentalController::class, 'index']);
    Route::post('/inventory/rentals', [InventoryRentalController::class, 'store']);
    Route::delete('/inventory/rentals/{inventory_rental}', [InventoryRentalController::class, 'destroy']);
    Route::post('/inventory/lots/{inventory_lot}/lifecycle/report', [InventoryLifecycleController::class, 'report']);
    Route::middleware('role:admin,super_admin')->group(function () {
        Route::post('/inventory/lots', [InventoryLotController::class, 'store']);
        Route::post('/inventory/lots/import', [InventoryLotController::class, 'importLots']);
        Route::patch('/inventory/lots/{inventory_lot}', [InventoryLotController::class, 'update']);
        Route::delete('/inventory/lots/{inventory_lot}', [InventoryLotController::class, 'destroy']);
        Route::get('/inventory/owner-accruals', InventoryOwnerAccrualController::class);
        Route::get('/inventory/audit-events', InventoryAuditEventController::class);
        Route::get('/inventory/lifecycle/requests', [InventoryLifecycleController::class, 'index']);
        Route::post('/inventory/lifecycle/requests/{transitionRequest}/approve', [InventoryLifecycleController::class, 'approve']);
        Route::post('/inventory/rentals/{inventory_rental}/close', [InventoryRentalController::class, 'close']);
    });

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/service-catalog/active', [ServiceCatalogController::class, 'active']);

    Route::middleware('role:admin,super_admin')->group(function () {
        Route::get('/admin/companies', [AdminCompanyController::class, 'index']);
        Route::get('/admin/companies/welcome-mail-attachment-ready', [AdminCompanyController::class, 'welcomeMailAttachmentReady']);
        Route::post('/admin/companies', [AdminCompanyController::class, 'store']);
        Route::put('/admin/companies/{company}', [AdminCompanyController::class, 'update']);
        Route::patch('/admin/companies/{company}/estado', [AdminCompanyController::class, 'updateEstado']);
        Route::delete('/admin/companies/{company}', [AdminCompanyController::class, 'destroy']);
        Route::get('/admin/companies/{company}/monthly-dashboard', [AdminCompanyController::class, 'monthlyDashboard']);
        Route::get('/admin/companies/{company}/recurring-services', [AdminCompanyRecurringServiceController::class, 'index']);
        Route::post('/admin/companies/{company}/recurring-services', [AdminCompanyRecurringServiceController::class, 'store']);
        Route::put('/admin/companies/{company}/recurring-services/{recurring_service}', [AdminCompanyRecurringServiceController::class, 'update']);
        Route::delete('/admin/companies/{company}/recurring-services/{recurring_service}', [AdminCompanyRecurringServiceController::class, 'destroy']);

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
        Route::get('/admin/settings/billing-automation', [AdminBillingAutomationController::class, 'show']);
        Route::put('/admin/settings/billing-automation', [AdminBillingAutomationController::class, 'update']);
        Route::get('/admin/settings/system-organization', [AdminSystemOrganizationController::class, 'show']);
        Route::put('/admin/settings/system-organization', [AdminSystemOrganizationController::class, 'update']);
        Route::post('/admin/settings/system-organization/logo', [AdminSystemOrganizationController::class, 'uploadLogo']);
        Route::delete('/admin/settings/system-organization/logo', [AdminSystemOrganizationController::class, 'deleteLogo']);
        Route::get('/admin/settings/system-organization/logo', [AdminSystemOrganizationController::class, 'logoFile']);
        Route::get('/admin/settings/mail-notifications/outbound-status', [AdminMailNotificationsSettingsController::class, 'outboundStatus']);
        Route::get('/admin/settings/mail-notifications/unlock-status', [AdminMailNotificationsSettingsController::class, 'unlockStatus']);
        Route::post('/admin/settings/mail-notifications/unlock', [AdminMailNotificationsSettingsController::class, 'unlock']);
        Route::get('/admin/settings/mail-notifications', [AdminMailNotificationsSettingsController::class, 'show']);
        Route::put('/admin/settings/mail-notifications', [AdminMailNotificationsSettingsController::class, 'update']);
        Route::post('/admin/settings/mail-notifications/test-send', [AdminMailNotificationsSettingsController::class, 'sendTestMail']);
        Route::post('/admin/settings/mail-notifications/template-pdf', [AdminMailNotificationsSettingsController::class, 'uploadTemplatePdf']);
        Route::delete('/admin/settings/mail-notifications/template-pdf', [AdminMailNotificationsSettingsController::class, 'deleteTemplatePdf']);
        Route::get('/admin/settings/inventory-locations', [AdminInventoryLocationSettingsController::class, 'show']);
        Route::put('/admin/settings/inventory-locations', [AdminInventoryLocationSettingsController::class, 'update']);
        Route::get('/admin/settings/inventory-holders', [AdminInventoryHolderSettingsController::class, 'show']);
        Route::put('/admin/settings/inventory-holders', [AdminInventoryHolderSettingsController::class, 'update']);

        Route::get('/admin/service-catalog', [AdminServiceCatalogController::class, 'index']);
        Route::post('/admin/service-catalog', [AdminServiceCatalogController::class, 'store']);
        Route::post('/admin/service-catalog/import', [AdminServiceCatalogController::class, 'import']);
        Route::post('/admin/service-catalog/bulk-destroy', [AdminServiceCatalogController::class, 'bulkDestroy']);
        Route::get('/admin/service-catalog/technician-pricing', [AdminTechnicianCatalogPricingController::class, 'show']);
        Route::put('/admin/service-catalog/technician-pricing', [AdminTechnicianCatalogPricingController::class, 'update']);
        Route::get('/admin/service-catalog/{service_catalog}', [AdminServiceCatalogController::class, 'show']);
        Route::put('/admin/service-catalog/{service_catalog}', [AdminServiceCatalogController::class, 'update']);
        Route::patch('/admin/service-catalog/{service_catalog}/estado', [AdminServiceCatalogController::class, 'updateEstado']);
        Route::delete('/admin/service-catalog/{service_catalog}', [AdminServiceCatalogController::class, 'destroy']);

        Route::get('/admin/service-catalog-suggestions', [AdminServiceCatalogSuggestionController::class, 'index']);
        Route::post('/admin/service-catalog-suggestions/{service_catalog_suggestion}/approve', [AdminServiceCatalogSuggestionController::class, 'approve']);
        Route::post('/admin/service-catalog-suggestions/{service_catalog_suggestion}/reject', [AdminServiceCatalogSuggestionController::class, 'reject']);

        Route::get('/admin/notifications/unread-count', [AdminPanelNotificationController::class, 'unreadCount']);
        Route::post('/admin/notifications/stream-ticket', [AdminPanelNotificationController::class, 'streamTicket']);
        Route::get('/admin/notifications', [AdminPanelNotificationController::class, 'index']);
        Route::patch('/admin/notifications/{panel_notification}/read', [AdminPanelNotificationController::class, 'markRead']);
        Route::post('/admin/notifications/read-all', [AdminPanelNotificationController::class, 'readAll']);

        Route::get('/admin/export/services', [AdminExportController::class, 'services']);
        Route::get('/admin/export/services/template', [AdminServiceRegistrySpreadsheetController::class, 'template']);
        Route::post('/admin/import/services', [AdminServiceRegistrySpreadsheetController::class, 'import'])
            ->middleware('throttle:10,1');
        Route::get('/admin/import/services/{jobId}/result', [AdminServiceRegistrySpreadsheetController::class, 'importResult'])
            ->middleware('throttle:120,1');
        Route::get('/admin/export/invoices', [AdminExportController::class, 'invoices']);
        Route::get('/admin/export/recurring-fixed-charges/template', [AdminRecurringFixedChargesSpreadsheetController::class, 'template']);
        Route::get('/admin/export/recurring-fixed-charges', [AdminRecurringFixedChargesSpreadsheetController::class, 'export']);
        Route::post('/admin/import/recurring-fixed-charges', [AdminRecurringFixedChargesSpreadsheetController::class, 'import'])
            ->middleware('throttle:10,1');
        Route::get('/admin/activity-logs', [AdminActivityLogController::class, 'index']);

        Route::post('/admin/system/backup', [AdminBackupController::class, 'trigger'])
            ->middleware('throttle:30,60');
        Route::get('/admin/system/backups', [AdminBackupController::class, 'index']);
        Route::get('/admin/system/backups/{filename}', [AdminBackupController::class, 'download']);
        Route::delete('/admin/system/backups/{filename}', [AdminBackupController::class, 'destroy']);
        Route::post('/admin/system/backups/{filename}/restore', [AdminBackupController::class, 'restore']);
        Route::post('/admin/system/restore-upload', [AdminBackupController::class, 'restoreUpload']);
        Route::post('/admin/system/restore-zip', [AdminBackupController::class, 'restoreZip']);
        Route::get('/admin/system/db-status', [AdminBackupController::class, 'dbStatus']);
        Route::get('/admin/system/download-sql', [AdminBackupController::class, 'downloadSql'])
            ->middleware('throttle:30,60');
        Route::post('/admin/system/wipe', [AdminBackupController::class, 'wipe'])
            ->middleware('throttle:3,60');
        Route::get('/admin/system/full-export', [AdminBackupController::class, 'fullExport'])
            ->middleware('throttle:5,60');

        Route::post('/admin/services/assign-to-technician', [ServiceController::class, 'assignToTechnician']);

        Route::get('/admin/invoices/available-services', [AdminInvoiceController::class, 'availableServices']);
        Route::get('/admin/invoices', [AdminInvoiceController::class, 'index']);
        Route::post('/admin/invoices', [AdminInvoiceController::class, 'store']);
        Route::get('/admin/invoices/{invoice}/pdf', [AdminInvoiceController::class, 'pdf']);
        Route::post('/admin/invoices/{invoice}/send-email', [AdminInvoiceController::class, 'sendEmailToCompany'])
            ->middleware('throttle:20,1');
        Route::patch('/admin/invoices/{invoice}/status', [AdminInvoiceController::class, 'updateStatus']);
        Route::post('/admin/invoices/{invoice}/payments', [AdminInvoiceController::class, 'storePayment']);
        Route::delete('/admin/invoices/{invoice}/payments/{payment}', [AdminInvoiceController::class, 'destroyPayment']);
        Route::post('/admin/invoices/{invoice}/public-access-token', [AdminInvoiceController::class, 'regeneratePublicAccess']);
        Route::get('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'show']);
        Route::put('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'update']);
        Route::delete('/admin/invoices/{invoice}', [AdminInvoiceController::class, 'destroy']);
    });

    Route::get('/empleados', [UserController::class, 'empleadosActivos'])->middleware('role:admin,super_admin');
    Route::get('/admin/empleados/{user}/historial', [EmployeeHistorialController::class, 'forUser'])
        ->middleware('role:admin,super_admin');
    Route::get('/admin/empleados/{user}/technician-pending-services', [AdminEmpleadoTechnicianPaymentController::class, 'pendingServices'])
        ->middleware('role:admin,super_admin');
    Route::post('/admin/empleados/{user}/technician-pay', [AdminEmpleadoTechnicianPaymentController::class, 'batchPay'])
        ->middleware('role:admin,super_admin');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->middleware('role:admin,super_admin');

    Route::get('/empleado/historial', [EmployeeHistorialController::class, 'forSelf'])->middleware('role:empleado');
    Route::get('/empleado/dashboard', [EmpleadoDashboardController::class, 'index'])->middleware('role:empleado');
    Route::get('/empleado/perfil', [EmpleadoPerfilController::class, 'show'])->middleware('role:empleado');
    Route::put('/empleado/perfil', [EmpleadoPerfilController::class, 'update'])->middleware('role:empleado');
    Route::post('/empleado/correo-solicitud', [EmpleadoCorreoSolicitudController::class, 'store'])->middleware('role:empleado');
    Route::delete('/empleado/correo-solicitud', [EmpleadoCorreoSolicitudController::class, 'destroy'])->middleware('role:empleado');
    Route::get('/empleado/notifications/unread-count', [EmpleadoPanelNotificationController::class, 'unreadCount'])->middleware('role:empleado');
    Route::post('/empleado/notifications/stream-ticket', [EmpleadoPanelNotificationController::class, 'streamTicket'])->middleware('role:empleado');
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
    Route::patch('/services/{service}/technician-paid', [ServiceController::class, 'patchTechnicianPaid']);
    Route::post('/services/{service}/complete-assignment', [ServiceController::class, 'completeAssignment']);
    Route::post('/services/{service}/reject-assignment', [ServiceController::class, 'rejectAssignment']);
});
