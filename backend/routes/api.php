<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Field Operations API  —  /api/v1
|--------------------------------------------------------------------------
| Layers of defence on every protected route:
|   1. auth:sanctum + active   (valid, non-expired token of an active, unlocked user)
|   2. permission:*            (coarse RBAC gate)
|   3. Policy / AccessScope    (row-level: hidden rows behave exactly like missing rows)
*/
Route::prefix('v1')->group(function () {
    // ---- public ----------------------------------------------------------------------------------
    Route::get('health', [V1\SystemController::class, 'health']);
    Route::post('auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [V1\AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('auth/reset-password', [V1\AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

    // ---- authenticated ----------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::get('auth/me', [V1\AuthController::class, 'me']);
        Route::post('auth/refresh', [V1\AuthController::class, 'refresh']);
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);
        Route::post('auth/change-password', [V1\AuthController::class, 'changePassword']);

        Route::get('lookups/enums', [V1\LookupController::class, 'enums']);
        Route::get('lookups/{entity}', [V1\LookupController::class, 'options']);

        // notifications (own only)
        Route::middleware('permission:notifications.view')->group(function () {
            Route::get('notifications', [V1\NotificationController::class, 'index']);
            Route::get('notifications/unread-count', [V1\NotificationController::class, 'unreadCount']);
            Route::post('notifications/read-all', [V1\NotificationController::class, 'readAll']);
            Route::post('notifications/{id}/read', [V1\NotificationController::class, 'read']);
        });

        Route::get('dashboard', V1\DashboardController::class)->middleware('permission:dashboard.view,portal.view');

        // ---- client portal ----
        Route::prefix('portal')->middleware('permission:portal.view')->group(function () {
            Route::get('summary', [V1\PortalController::class, 'summary']);
            Route::get('campaigns', [V1\PortalController::class, 'campaigns']);
            Route::get('visits', [V1\PortalController::class, 'visits']);
            Route::get('visits/{id}', [V1\PortalController::class, 'visit']);
            Route::get('reports/{type}', [V1\PortalController::class, 'report']);
            Route::get('reports/{type}/export', [V1\PortalController::class, 'exportReport'])->middleware('throttle:exports');
        });

        // ---- administration ----
        Route::middleware('permission:users.view')->group(function () {
            Route::get('users', [V1\UserController::class, 'index']);
            Route::get('users/{id}', [V1\UserController::class, 'show']);
        });
        Route::post('users', [V1\UserController::class, 'store'])->middleware('permission:users.create');
        Route::patch('users/{id}', [V1\UserController::class, 'update'])->middleware('permission:users.edit');
        Route::put('users/{id}', [V1\UserController::class, 'update'])->middleware('permission:users.edit');
        Route::post('users/{id}/disable', [V1\UserController::class, 'disable'])->middleware('permission:users.disable');
        Route::post('users/{id}/enable', [V1\UserController::class, 'enable'])->middleware('permission:users.disable');
        Route::post('users/{id}/send-reset-link', [V1\UserController::class, 'sendResetLink'])->middleware('permission:users.edit');
        Route::delete('users/{id}', [V1\UserController::class, 'destroy'])->middleware('permission:users.disable');
        Route::get('roles', [V1\RoleController::class, 'index'])->middleware('permission:roles.view,users.view');

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('audit-logs', [V1\AuditLogController::class, 'index']);
            Route::get('audit-logs/{id}', [V1\AuditLogController::class, 'show']);
        });
        Route::middleware('permission:system.manage')->group(function () {
            Route::get('security-events', [V1\AuditLogController::class, 'securityEvents']);
            Route::get('system/health', [V1\SystemController::class, 'details']);
            Route::get('system/settings', [V1\SystemController::class, 'settings']);
            Route::put('system/settings', [V1\SystemController::class, 'updateSettings']);
            Route::get('system/gps-rules', [V1\SystemController::class, 'gpsRules']);
            Route::put('system/gps-rules', [V1\SystemController::class, 'updateGpsRules']);
        });

        // ---- clients ----
        Route::middleware('permission:clients.view')->group(function () {
            Route::get('clients', [V1\ClientController::class, 'index']);
            Route::get('clients/{id}', [V1\ClientController::class, 'show']);
            Route::get('clients/{client}/contacts', [V1\ClientContactController::class, 'index']);
            Route::get('clients/{client}/documents', [V1\ClientDocumentController::class, 'index']);
            Route::get('clients/{client}/documents/{id}/download', [V1\ClientDocumentController::class, 'download']);
        });
        Route::post('clients', [V1\ClientController::class, 'store'])->middleware('permission:clients.create');
        Route::middleware('permission:clients.edit')->group(function () {
            Route::patch('clients/{id}', [V1\ClientController::class, 'update']);
            Route::put('clients/{id}', [V1\ClientController::class, 'update']);
            Route::post('clients/{client}/contacts', [V1\ClientContactController::class, 'store']);
            Route::patch('clients/{client}/contacts/{id}', [V1\ClientContactController::class, 'update']);
            Route::delete('clients/{client}/contacts/{id}', [V1\ClientContactController::class, 'destroy']);
            Route::post('clients/{client}/documents', [V1\ClientDocumentController::class, 'store'])->middleware('throttle:uploads');
            Route::delete('clients/{client}/documents/{id}', [V1\ClientDocumentController::class, 'destroy']);
        });
        Route::delete('clients/{id}', [V1\ClientController::class, 'destroy'])->middleware('permission:clients.delete');

        // ---- campaigns ----
        Route::middleware('permission:campaigns.view')->group(function () {
            Route::get('campaigns', [V1\CampaignController::class, 'index']);
            Route::get('campaigns/{id}', [V1\CampaignController::class, 'show']);
            Route::get('campaigns/{id}/links/{relation}', [V1\CampaignLinkController::class, 'show']);
        });
        Route::post('campaigns', [V1\CampaignController::class, 'store'])->middleware('permission:campaigns.create');
        Route::middleware('permission:campaigns.edit')->group(function () {
            Route::patch('campaigns/{id}', [V1\CampaignController::class, 'update']);
            Route::put('campaigns/{id}', [V1\CampaignController::class, 'update']);
            Route::post('campaigns/{id}/transition', [V1\CampaignController::class, 'transition']);
            Route::delete('campaigns/{id}', [V1\CampaignController::class, 'destroy']);
        });
        Route::put('campaigns/{id}/links/{relation}', [V1\CampaignLinkController::class, 'sync'])->middleware('permission:campaigns.assign');

        Route::middleware('permission:campaigns.view')->get('kpis', [V1\CampaignKpiController::class, 'index']);
        Route::middleware('permission:campaigns.edit')->group(function () {
            Route::post('kpis', [V1\CampaignKpiController::class, 'store']);
            Route::patch('kpis/{id}', [V1\CampaignKpiController::class, 'update']);
            Route::delete('kpis/{id}', [V1\CampaignKpiController::class, 'destroy']);
        });

        // ---- activities ----
        Route::middleware('permission:activities.view')->group(function () {
            Route::get('activities', [V1\ActivityController::class, 'index']);
            Route::get('activities/{id}', [V1\ActivityController::class, 'show']);
        });
        Route::post('activities', [V1\ActivityController::class, 'store'])->middleware('permission:activities.create');
        Route::middleware('permission:activities.edit')->group(function () {
            Route::patch('activities/{id}', [V1\ActivityController::class, 'update']);
            Route::delete('activities/{id}', [V1\ActivityController::class, 'destroy']);
        });

        // ---- teams / territories / routes / locations ----
        foreach ([
            ['teams', V1\TeamController::class, 'teams.view', 'teams.manage'],
            ['territories', V1\TerritoryController::class, 'territories.view', 'territories.manage'],
            ['routes', V1\RouteController::class, 'territories.view', 'territories.manage'],
            ['locations', V1\LocationController::class, 'locations.view', 'locations.manage'],
            ['brands', V1\BrandController::class, 'products.view', 'products.manage'],
            ['product-categories', V1\ProductCategoryController::class, 'products.view', 'products.manage'],
            ['products', V1\ProductController::class, 'products.view', 'products.manage'],
        ] as [$path, $controller, $view, $manage]) {
            Route::middleware("permission:$view")->group(function () use ($path, $controller) {
                Route::get($path, [$controller, 'index']);
                Route::get("$path/{id}", [$controller, 'show']);
            });
            Route::middleware("permission:$manage")->group(function () use ($path, $controller) {
                Route::post($path, [$controller, 'store']);
                Route::patch("$path/{id}", [$controller, 'update']);
                Route::put("$path/{id}", [$controller, 'update']);
                Route::delete("$path/{id}", [$controller, 'destroy']);
            });
        }

        // ---- forms ----
        Route::middleware('permission:forms.view')->group(function () {
            Route::get('forms', [V1\FormController::class, 'index']);
            Route::get('forms/{id}', [V1\FormController::class, 'show']);
        });
        Route::middleware('permission:forms.manage')->group(function () {
            Route::post('forms', [V1\FormController::class, 'store']);
            Route::patch('forms/{id}', [V1\FormController::class, 'update']);
            Route::put('forms/{id}/structure', [V1\FormController::class, 'saveStructure']);
            Route::post('forms/{id}/publish', [V1\FormController::class, 'publish']);
            Route::post('forms/{id}/archive', [V1\FormController::class, 'archive']);
            Route::post('forms/{id}/new-version', [V1\FormController::class, 'newVersion']);
            Route::post('forms/{id}/duplicate', [V1\FormController::class, 'duplicate']);
            Route::delete('forms/{id}', [V1\FormController::class, 'destroy']);
        });

        // ---- assignments ----
        Route::middleware('permission:assignments.view')->group(function () {
            Route::get('assignments', [V1\AssignmentController::class, 'index']);
            Route::get('assignments/agents', [V1\AssignmentController::class, 'agents'])->middleware('permission:assignments.create');
            Route::get('assignments/{id}', [V1\AssignmentController::class, 'show']);
        });
        Route::middleware('permission:assignments.create')->group(function () {
            Route::post('assignments', [V1\AssignmentController::class, 'store']);
            Route::post('assignments/bulk', [V1\AssignmentController::class, 'bulk']);
        });
        Route::middleware('permission:assignments.edit')->group(function () {
            Route::patch('assignments/{id}', [V1\AssignmentController::class, 'update']);
            Route::post('assignments/{id}/cancel', [V1\AssignmentController::class, 'cancel']);
            Route::post('assignments/{id}/reassign', [V1\AssignmentController::class, 'reassign']);
        });
        Route::post('assignments/{id}/accept', [V1\AssignmentController::class, 'accept'])->middleware('permission:assignments.respond');

        // ---- attendance ----
        Route::middleware('permission:attendance.view')->group(function () {
            Route::get('attendance', [V1\AttendanceController::class, 'index']);
            Route::get('attendance/today', [V1\AttendanceController::class, 'today']);
            Route::get('attendance/{id}', [V1\AttendanceController::class, 'show']);
        });
        Route::middleware('permission:attendance.create')->group(function () {
            Route::post('attendance/clock-in', [V1\AttendanceController::class, 'clockIn']);
            Route::post('attendance/clock-out', [V1\AttendanceController::class, 'clockOut']);
        });

        // ---- visits ----
        Route::middleware('permission:visits.view')->group(function () {
            Route::get('visits', [V1\VisitController::class, 'index']);
            Route::get('visits/{id}', [V1\VisitController::class, 'show']);
            Route::get('visits/{visit}/sales', [V1\VisitDataController::class, 'sales']);
            Route::get('visits/{visit}/osa', [V1\VisitDataController::class, 'osa']);
            Route::get('visits/{visit}/merchandising', [V1\VisitDataController::class, 'merchandising']);
        });
        Route::post('visits', [V1\VisitController::class, 'store'])->middleware('permission:visits.create');
        Route::middleware('permission:visits.edit')->group(function () {
            Route::post('visits/{id}/check-in', [V1\VisitController::class, 'checkIn']);
            Route::post('visits/{id}/check-out', [V1\VisitController::class, 'checkOut']);
            Route::put('visits/{id}/responses', [V1\VisitController::class, 'responses']);
            Route::post('visits/{visit}/sales', [V1\VisitDataController::class, 'storeSale']);
            Route::put('visits/{visit}/osa', [V1\VisitDataController::class, 'saveOsa']);
            Route::post('visits/{visit}/merchandising', [V1\VisitDataController::class, 'storeMerchandising']);
        });
        Route::post('visits/{id}/submit', [V1\VisitController::class, 'submit'])->middleware('permission:visits.submit');
        Route::post('visits/{id}/review', [V1\VisitController::class, 'review'])->middleware('permission:visits.review');
        Route::post('visits/{id}/approve', [V1\VisitController::class, 'approve'])->middleware('permission:visits.approve');
        Route::post('visits/{id}/reject', [V1\VisitController::class, 'reject'])->middleware('permission:visits.reject');

        // read-only operational data listings
        Route::get('sales', [V1\SaleController::class, 'index'])->middleware('permission:sales.view');
        Route::get('osa', [V1\OsaController::class, 'index'])->middleware('permission:osa.view');
        Route::get('merchandising', [V1\MerchandisingController::class, 'index'])->middleware('permission:merchandising.view');

        // ---- evidence ----
        Route::middleware('permission:evidence.view')->group(function () {
            Route::get('photos', [V1\PhotoController::class, 'index']);
            Route::get('photos/{id}', [V1\PhotoController::class, 'show']);
        });
        // Client users may open ACCEPTED photos of APPROVED visits of their own client (AccessScope::forPhoto).
        Route::get('photos/{id}/file', [V1\PhotoController::class, 'file'])->middleware('permission:evidence.view,portal.view');
        Route::post('visits/{visit}/photos', [V1\PhotoController::class, 'store'])->middleware(['permission:evidence.upload', 'throttle:uploads']);
        Route::post('photos/{id}/review', [V1\PhotoController::class, 'review'])->middleware('permission:evidence.review');
        Route::delete('photos/{id}', [V1\PhotoController::class, 'destroy'])->middleware('permission:evidence.upload');

        // ---- issues ----
        Route::middleware('permission:issues.view')->group(function () {
            Route::get('issues', [V1\IssueController::class, 'index']);
            Route::get('issues/{id}', [V1\IssueController::class, 'show']);
            Route::post('issues/{id}/comments', [V1\IssueController::class, 'comment']);
        });
        Route::post('issues', [V1\IssueController::class, 'store'])->middleware('permission:issues.create');
        Route::patch('issues/{id}', [V1\IssueController::class, 'update'])->middleware('permission:issues.create,issues.assign,issues.resolve');
        Route::post('issues/{id}/assign', [V1\IssueController::class, 'assign'])->middleware('permission:issues.assign');
        Route::post('issues/{id}/status', [V1\IssueController::class, 'transition'])->middleware('permission:issues.resolve');

        // ---- reports ----
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports', [V1\ReportController::class, 'types']);
            Route::get('reports/{type}', [V1\ReportController::class, 'show']);
        });
        Route::get('reports/{type}/export', [V1\ReportController::class, 'export'])->middleware(['permission:reports.export', 'throttle:exports']);
    });
});
