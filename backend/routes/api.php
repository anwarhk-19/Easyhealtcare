<?php

use App\Http\Controllers\Api as C;
use App\Http\Controllers\Api\PatientApp\PatientAppController as PatientApp;
use App\Http\Controllers\Api\Platform\PlatformController as Platform;
use App\Models\Speciality;
use Illuminate\Support\Facades\Route;

/*
| Staff API (v1). Every route under `tenant` runs with an organisation context; permissions use `can:` gates.
*/
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('auth/register-clinic', [C\AuthController::class, 'registerClinic']);
        Route::post('auth/login', [C\AuthController::class, 'login']);
        Route::post('auth/two-factor/challenge', [C\AuthController::class, 'twoFactorChallenge']);
        Route::post('auth/forgot-password', [C\AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [C\AuthController::class, 'resetPassword'])->name('password.reset');
        Route::post('invitations/{token}/accept', [C\AuthController::class, 'acceptInvitation']);
    });

    Route::middleware(['auth:sanctum', 'tenant', 'org.active'])->group(function () {
        // Session & account
        Route::get('auth/me', [C\AuthController::class, 'me']);
        Route::post('auth/logout', [C\AuthController::class, 'logout']);
        Route::post('auth/switch-organization', [C\AuthController::class, 'switchOrganization']);
        Route::get('auth/sessions', [C\AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [C\AuthController::class, 'revokeSession']);
        Route::post('auth/two-factor', [C\AuthController::class, 'enableTwoFactor']);
        Route::post('auth/two-factor/confirm', [C\AuthController::class, 'confirmTwoFactor']);
        Route::delete('auth/two-factor', [C\AuthController::class, 'disableTwoFactor']);

        // Organisation & subscription
        Route::get('organization', [C\OrganizationController::class, 'show']);
        Route::patch('organization', [C\OrganizationController::class, 'update']);
        Route::post('organization/logo', [C\OrganizationController::class, 'uploadLogo']);
        Route::get('organization/settings', [C\OrganizationController::class, 'settings']);
        Route::patch('organization/settings', [C\OrganizationController::class, 'updateSettings']);
        Route::put('organization/specialities', [C\OrganizationController::class, 'updateSpecialities']);
        Route::patch('organization/modules/{key}', [C\OrganizationController::class, 'toggleModule']);
        Route::get('subscription', [C\OrganizationController::class, 'subscription']);
        Route::post('subscription/change-plan', [C\OrganizationController::class, 'changePlan']);

        // Master data
        Route::apiResource('branches', C\BranchController::class);
        Route::get('branches/{id}/operating-hours', [C\BranchController::class, 'hours']);
        Route::put('branches/{id}/operating-hours', [C\BranchController::class, 'setHours']);
        Route::apiResource('departments', C\DepartmentController::class);
        Route::apiResource('holidays', C\HolidayController::class);
        Route::apiResource('tax-rates', C\TaxRateController::class);
        Route::apiResource('services', C\ServiceController::class);
        Route::put('services/{id}/branch-prices', [C\ServiceController::class, 'setBranchPrices']);
        Route::get('specialities', fn () => response()->json(['data' => Speciality::where('is_active', true)->orderBy('name')->get()]));

        // Staff & roles
        Route::get('staff', [C\StaffController::class, 'index']);
        Route::patch('staff/{id}', [C\StaffController::class, 'update']);
        Route::get('staff/invitations', [C\StaffController::class, 'invitations']);
        Route::post('staff/invitations', [C\StaffController::class, 'invite']);
        Route::delete('staff/invitations/{id}', [C\StaffController::class, 'revokeInvitation']);
        Route::get('permissions', [C\RoleController::class, 'permissions']);
        Route::apiResource('roles', C\RoleController::class)->except('show');
        Route::middleware('feature:hr')->group(function () {
            Route::get('attendance', [C\AttendanceController::class, 'index']);
            Route::post('attendance/clock-in', [C\AttendanceController::class, 'clockIn']);
            Route::post('attendance/clock-out', [C\AttendanceController::class, 'clockOut']);
            Route::get('staff-leaves', [C\AttendanceController::class, 'leaves']);
            Route::post('staff-leaves', [C\AttendanceController::class, 'requestLeave']);
            Route::post('staff-leaves/{id}/decide', [C\AttendanceController::class, 'decideLeave']);
        });

        // Doctors
        Route::get('doctors', [C\DoctorController::class, 'index']);
        Route::post('doctors', [C\DoctorController::class, 'store']);
        Route::get('doctors/{id}', [C\DoctorController::class, 'show']);
        Route::patch('doctors/{id}', [C\DoctorController::class, 'update']);
        Route::post('doctors/{id}/signature', [C\DoctorController::class, 'uploadSignature']);
        Route::put('doctors/{id}/schedules', [C\DoctorController::class, 'setSchedules']);
        Route::put('doctors/{id}/fees', [C\DoctorController::class, 'setFees']);
        Route::get('doctors/{id}/leaves', [C\DoctorController::class, 'leaves']);
        Route::post('doctors/{id}/leaves', [C\DoctorController::class, 'addLeave']);
        Route::delete('doctors/{id}/leaves/{leaveId}', [C\DoctorController::class, 'deleteLeave']);
        Route::get('doctors/{id}/leaves/{leaveId}/affected-appointments', [C\DoctorController::class, 'affectedAppointments']);
        Route::get('doctors/{id}/availability', [C\DoctorController::class, 'availability']);
        Route::get('doctors/{id}/stats', [C\DoctorController::class, 'stats']);

        // Patients
        Route::middleware('can:patients.view')->group(function () {
            Route::get('patients', [C\PatientController::class, 'index']);
            Route::get('patients/duplicates-check', [C\PatientController::class, 'duplicatesCheck']);
            Route::get('patients/{id}', [C\PatientController::class, 'show']);
            Route::get('patients/{id}/timeline', [C\PatientController::class, 'timeline']);
            Route::get('patients/{id}/clinical-summary', [C\PatientController::class, 'clinicalSummary']);
            Route::get('patients/{id}/consultations', [C\PatientController::class, 'consultations']);
            Route::get('patients/{id}/vitals', [C\PatientController::class, 'vitals']);
            Route::get('patients/{id}/appointments', [C\PatientController::class, 'appointments']);
            Route::get('patients/{id}/consents', [C\PatientController::class, 'consents']);
            Route::get('patients/{id}/balance', [C\PatientController::class, 'balance'])->middleware('can:invoices.view');
            Route::get('patients/{id}/export', [C\PatientController::class, 'export']);
            Route::get('patients/{id}/documents', [C\PatientController::class, 'documents'])->middleware('can:patients.documents.view');
            Route::get('documents/{documentId}/download', [C\PatientController::class, 'downloadDocument'])->middleware('can:patients.documents.view');
        });
        Route::post('patients', [C\PatientController::class, 'store'])->middleware('can:patients.create');
        Route::patch('patients/{id}', [C\PatientController::class, 'update'])->middleware('can:patients.update');
        Route::post('patients/{id}/merge', [C\PatientController::class, 'merge'])->middleware('can:patients.merge');
        Route::post('patients/{id}/{kind}', [C\PatientController::class, 'addHistory'])->whereIn('kind', ['allergies', 'conditions', 'medications'])->middleware('can:patients.update');
        Route::delete('patients/{id}/{kind}/{itemId}', [C\PatientController::class, 'removeHistory'])->whereIn('kind', ['allergies', 'conditions', 'medications'])->middleware('can:patients.update');
        Route::post('patients/{id}/documents', [C\PatientController::class, 'uploadDocument'])->middleware('can:patients.documents.upload');
        Route::post('patients/{id}/consents', [C\PatientController::class, 'recordConsent'])->middleware('can:patients.consents.manage');

        // Appointments & waitlist
        Route::get('appointments', [C\AppointmentController::class, 'index'])->middleware('can:appointments.view');
        Route::get('appointments/calendar', [C\AppointmentController::class, 'calendar'])->middleware('can:appointments.view');
        Route::get('appointments/{id}', [C\AppointmentController::class, 'show'])->middleware('can:appointments.view');
        Route::middleware('can:appointments.create')->group(function () {
            Route::post('appointments', [C\AppointmentController::class, 'store']);
            Route::post('appointments/walk-in', [C\AppointmentController::class, 'walkIn']);
        });
        Route::middleware('can:appointments.update')->group(function () {
            Route::patch('appointments/{id}', [C\AppointmentController::class, 'update']);
            Route::post('appointments/{id}/confirm', [C\AppointmentController::class, 'confirm']);
            Route::post('appointments/{id}/reschedule', [C\AppointmentController::class, 'reschedule']);
            Route::post('appointments/{id}/no-show', [C\AppointmentController::class, 'noShow']);
        });
        Route::post('appointments/{id}/check-in', [C\AppointmentController::class, 'checkIn'])->middleware('can:queue.manage');
        Route::post('appointments/{id}/cancel', [C\AppointmentController::class, 'cancel'])->middleware('can:appointments.cancel');
        Route::post('appointments/{appointmentId}/vitals', [C\ConsultationController::class, 'appointmentVitals']);
        Route::get('waitlist', [C\AppointmentController::class, 'waitlist'])->middleware('can:appointments.view');
        Route::post('waitlist', [C\AppointmentController::class, 'addToWaitlist'])->middleware('can:appointments.create');
        Route::delete('waitlist/{id}', [C\AppointmentController::class, 'removeFromWaitlist'])->middleware('can:appointments.update');

        // Queue
        Route::get('queue', [C\QueueController::class, 'index'])->middleware('can:queue.view');
        Route::get('queue/my', [C\QueueController::class, 'my'])->middleware('can:queue.view');
        Route::middleware('can:queue.call')->group(function () {
            Route::post('queue/call-next', [C\QueueController::class, 'callNext']);
            Route::post('queue/{id}/call', [C\QueueController::class, 'call']);
            Route::post('queue/{id}/start', [C\QueueController::class, 'start']);
            Route::post('queue/{id}/complete', [C\QueueController::class, 'complete']);
        });
        Route::middleware('can:queue.manage')->group(function () {
            Route::post('queue/{id}/skip', [C\QueueController::class, 'skip']);
            Route::post('queue/{id}/requeue', [C\QueueController::class, 'requeue']);
            Route::post('queue/{id}/transfer', [C\QueueController::class, 'transfer']);
            Route::post('queue/{id}/priority', [C\QueueController::class, 'priority']);
        });
        Route::apiResource('queue-displays', C\QueueDisplayController::class);

        // Consultations & prescriptions
        Route::get('consultation-templates', [C\ConsultationTemplateController::class, 'index']);
        Route::get('consultation-templates/{id}', [C\ConsultationTemplateController::class, 'show']);
        Route::post('consultation-templates', [C\ConsultationTemplateController::class, 'store']);
        Route::put('consultation-templates/{id}', [C\ConsultationTemplateController::class, 'update']);
        Route::post('consultation-templates/{id}/clone', [C\ConsultationTemplateController::class, 'clone']);
        Route::get('icd10', [C\ConsultationController::class, 'icd10']);
        Route::middleware('can:consultations.view')->group(function () {
            Route::get('consultations', [C\ConsultationController::class, 'index']);
            Route::get('consultations/{id}', [C\ConsultationController::class, 'show']);
            Route::get('consultations/{id}/prescription', [C\PrescriptionController::class, 'show']);
        });
        Route::middleware('can:consultations.write')->group(function () {
            Route::post('consultations', [C\ConsultationController::class, 'store']);
            Route::patch('consultations/{id}', [C\ConsultationController::class, 'update']);
            Route::put('consultations/{id}/sections/{key}', [C\ConsultationController::class, 'saveSection']);
            Route::put('consultations/{id}/diagnoses', [C\ConsultationController::class, 'diagnoses']);
            Route::post('consultations/{id}/sign', [C\ConsultationController::class, 'sign']);
            Route::post('consultations/{id}/addenda', [C\ConsultationController::class, 'addendum']);
        });
        Route::post('consultations/{id}/vitals', [C\ConsultationController::class, 'vitals']);
        Route::middleware('can:prescriptions.write')->group(function () {
            Route::put('consultations/{id}/prescription', [C\PrescriptionController::class, 'save']);
            Route::post('consultations/{id}/prescription/copy-from/{prescriptionId}', [C\PrescriptionController::class, 'copyFrom']);
            Route::post('prescriptions/check-allergies', [C\PrescriptionController::class, 'checkAllergies']);
        });
        Route::get('prescriptions/{id}/pdf', [C\PrescriptionController::class, 'pdf']);
        Route::post('prescriptions/{id}/share', [C\PrescriptionController::class, 'share']);
        Route::apiResource('medicines', C\MedicineController::class);
        Route::apiResource('rx-templates', C\RxTemplateController::class);

        // Billing
        Route::middleware('can:invoices.view')->group(function () {
            Route::get('invoices', [C\InvoiceController::class, 'index']);
            Route::get('invoices/{id}', [C\InvoiceController::class, 'show']);
            Route::get('invoices/{id}/pdf', [C\InvoiceController::class, 'pdf']);
            Route::get('payments', [C\PaymentController::class, 'index']);
            Route::get('payments/{id}/receipt', [C\PaymentController::class, 'receipt']);
            Route::get('refunds', [C\PaymentController::class, 'refunds']);
            Route::get('collections/daily', [C\PaymentController::class, 'dailyCollection']);
        });
        Route::middleware('can:invoices.create')->group(function () {
            Route::post('invoices', [C\InvoiceController::class, 'store']);
            Route::post('invoices/from-appointment/{appointmentId}', [C\InvoiceController::class, 'fromAppointment']);
            Route::put('invoices/{id}/items', [C\InvoiceController::class, 'setItems']);
            Route::post('invoices/{id}/issue', [C\InvoiceController::class, 'issue']);
            Route::post('invoices/{id}/share', [C\InvoiceController::class, 'share']);
        });
        Route::post('invoices/{id}/void', [C\InvoiceController::class, 'void']);
        Route::post('invoices/{id}/discount-decision', [C\InvoiceController::class, 'decideDiscount']);
        Route::post('payments', [C\PaymentController::class, 'store'])->middleware('can:payments.collect');
        Route::post('payments/{id}/refunds', [C\PaymentController::class, 'requestRefund']);
        Route::post('refunds/{id}/decide', [C\PaymentController::class, 'decideRefund']);
        Route::post('refunds/{id}/process', [C\PaymentController::class, 'processRefund']);
        Route::post('cash-closings', [C\PaymentController::class, 'closeCash'])->middleware('can:payments.collect');

        // Lab (plan feature)
        Route::middleware('feature:lab')->group(function () {
            Route::apiResource('lab-tests', C\LabTestController::class);
            Route::get('lab-orders', [C\LabController::class, 'index'])->middleware('can:lab.view');
            Route::get('lab-orders/{id}', [C\LabController::class, 'show'])->middleware('can:lab.view');
            Route::get('lab-orders/{id}/pdf', [C\LabController::class, 'pdf'])->middleware('can:lab.view');
            Route::post('lab-orders', [C\LabController::class, 'store'])->middleware('can:lab.order');
            Route::middleware('can:lab.results.enter')->group(function () {
                Route::post('lab-orders/{id}/items/{itemId}/collect-sample', [C\LabController::class, 'collectSample']);
                Route::put('lab-orders/{id}/items/{itemId}/results', [C\LabController::class, 'results']);
                Route::post('lab-orders/{id}/report', [C\LabController::class, 'uploadReport']);
                Route::post('lab-orders/{id}/cancel', [C\LabController::class, 'cancel']);
            });
            Route::post('lab-orders/{id}/release', [C\LabController::class, 'release'])->middleware('can:lab.results.release');
            Route::post('lab-orders/{id}/share', [C\LabController::class, 'share'])->middleware('can:lab.results.release');
        });

        // Inventory (optional plan feature)
        Route::middleware('feature:inventory')->prefix('inventory')->group(function () {
            Route::apiResource('categories', C\InventoryCategoryController::class);
            Route::apiResource('items', C\InventoryItemController::class);
            Route::apiResource('suppliers', C\SupplierController::class);
            Route::get('stock', [C\InventoryController::class, 'stock']);
            Route::get('batches', [C\InventoryController::class, 'batches']);
            Route::get('expiring', [C\InventoryController::class, 'expiring']);
            Route::get('goods-receipts', [C\InventoryController::class, 'receipts']);
            Route::post('goods-receipts', [C\InventoryController::class, 'receive']);
            Route::post('dispense', [C\InventoryController::class, 'dispense']);
            Route::post('adjustments', [C\InventoryController::class, 'adjust']);
            Route::post('transfers', [C\InventoryController::class, 'transfer']);
            Route::get('movements', [C\InventoryController::class, 'movements']);
        });

        // Speciality modules (plan feature)
        Route::middleware('feature:specialities')->group(function () {
            Route::get('patients/{id}/dental-chart', [C\SpecialityController::class, 'dentalChart']);
            Route::post('patients/{id}/dental-chart', [C\SpecialityController::class, 'recordTooth']);
            Route::get('patients/{id}/treatment-plans', [C\SpecialityController::class, 'treatmentPlans']);
            Route::post('patients/{id}/treatment-plans', [C\SpecialityController::class, 'createPlan']);
            Route::post('treatment-plans/{planId}/items/{itemId}/complete', [C\SpecialityController::class, 'completeItem']);
            Route::get('treatment-plans/{planId}/progress', [C\SpecialityController::class, 'planProgress']);
            Route::get('consultations/{id}/eye-exam', [C\SpecialityController::class, 'eyeExam']);
            Route::put('consultations/{id}/eye-exam', [C\SpecialityController::class, 'saveEyeExam']);
        });

        // Notifications
        Route::get('notification-messages', [C\NotificationController::class, 'index']);
        Route::post('notification-messages/{id}/retry', [C\NotificationController::class, 'retry']);
        Route::apiResource('notification-templates', C\NotificationTemplateController::class);

        // Reports & audit
        Route::get('dashboard', [C\ReportController::class, 'dashboard']);
        Route::get('reports/{report}', [C\ReportController::class, 'show'])->whereIn('report', ['appointments', 'collections', 'outstanding', 'doctors', 'services', 'gst']);
        Route::get('reports/{report}/export', [C\ReportController::class, 'export'])->whereIn('report', ['appointments', 'collections', 'outstanding', 'doctors', 'services', 'gst'])->middleware('throttle:exports');
        Route::get('audit-logs', [C\ReportController::class, 'audit']);

        // AI (opt-in)
        Route::middleware(['feature:ai', 'can:ai.use'])->group(function () {
            Route::post('ai/run', [C\AiController::class, 'run']);
            Route::post('ai/requests/{id}/accept', [C\AiController::class, 'accept']);
            Route::get('ai/requests', [C\AiController::class, 'history']);
        });
    });
});

/*
| Platform super-admin API.
*/
Route::prefix('platform/v1')->group(function () {
    Route::post('auth/login', [Platform::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', 'platform'])->group(function () {
        Route::get('organizations', [Platform::class, 'organizations']);
        Route::get('organizations/{id}', [Platform::class, 'organization']);
        Route::post('organizations/{id}/{action}', [Platform::class, 'setStatus'])->whereIn('action', ['approve', 'suspend', 'reactivate']);
        Route::patch('organizations/{id}/subscription', [Platform::class, 'updateSubscription']);
        Route::get('plans', [Platform::class, 'plans']);
        Route::post('plans', [Platform::class, 'savePlan']);
        Route::patch('plans/{id}', [Platform::class, 'savePlan']);
        Route::get('specialities', [Platform::class, 'specialities']);
        Route::post('specialities', [Platform::class, 'saveSpeciality']);
        Route::patch('specialities/{id}', [Platform::class, 'saveSpeciality']);
        Route::post('consultation-templates', [Platform::class, 'saveTemplate']);
        Route::get('metrics', [Platform::class, 'metrics']);
        Route::get('audit-logs', [Platform::class, 'audit']);
        Route::get('settings', [Platform::class, 'settings']);
        Route::put('settings', [Platform::class, 'saveSettings']);
        Route::post('impersonations', [Platform::class, 'impersonate']);
        Route::delete('impersonations/{id}', [Platform::class, 'endImpersonation']);
    });
});

/*
| Patient app API.
*/
Route::prefix('patient/v1')->group(function () {
    Route::middleware('throttle:otp')->group(function () {
        Route::post('auth/otp/request', [PatientApp::class, 'requestOtp']);
        Route::post('auth/otp/verify', [PatientApp::class, 'verifyOtp']);
    });
    Route::get('clinics', [PatientApp::class, 'clinics'])->middleware('throttle:public');
    Route::get('clinics/{slug}', [PatientApp::class, 'clinic'])->middleware('throttle:public');
    Route::get('clinics/{slug}/doctors/{doctorId}/availability', [PatientApp::class, 'availability'])->middleware('throttle:public');
    Route::middleware(['auth:sanctum', 'patient'])->group(function () {
        Route::get('me', [PatientApp::class, 'me']);
        Route::patch('me', [PatientApp::class, 'updateMe']);
        Route::post('clinics/{slug}/link', [PatientApp::class, 'link']);
        Route::delete('clinics/{slug}/link', [PatientApp::class, 'unlink']);
        Route::post('clinics/{slug}/consents', [PatientApp::class, 'setConsent']);
        Route::get('appointments', [PatientApp::class, 'appointments']);
        Route::post('appointments', [PatientApp::class, 'book']);
        Route::post('appointments/{id}/cancel', [PatientApp::class, 'cancelAppointment']);
        Route::get('records', [PatientApp::class, 'records']);
        Route::get('records/prescriptions/{id}/pdf', [PatientApp::class, 'prescriptionPdf']);
        Route::get('records/receipts/{id}/pdf', [PatientApp::class, 'receiptPdf']);
        Route::get('consents', [PatientApp::class, 'consents']);
        Route::delete('account', [PatientApp::class, 'deleteAccount']);
    });
});

/*
| Public endpoints (token-keyed, rate-limited).
*/
Route::prefix('public/v1')->middleware('throttle:public')->group(function () {
    Route::get('display/{token}', [C\PublicController::class, 'display']);
    Route::get('s/{token}', [C\PublicController::class, 'share']);
    Route::get('webhooks/whatsapp', [C\NotificationController::class, 'verifyWhatsapp']);
    Route::post('webhooks/whatsapp', [C\NotificationController::class, 'whatsappWebhook']);
});
