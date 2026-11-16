<?php

namespace App\Support\Rbac;

/**
 * Single source of truth for permissions and system roles (docs/02-roles-and-permissions.md).
 * Seeded into the database; the permission-matrix test asserts against this file.
 */
final class PermissionCatalog
{
    public const PERMISSIONS = [
        'organization' => ['organization.view', 'organization.update', 'subscription.view', 'subscription.manage'],
        'branches' => ['branches.view', 'branches.manage', 'schedules.manage', 'holidays.manage'],
        'services' => ['services.view', 'services.manage'],
        'staff' => ['staff.view', 'staff.manage', 'roles.manage', 'attendance.manage', 'attendance.self'],
        'doctors' => ['doctors.view', 'doctors.manage', 'doctors.manage_own'],
        'patients' => ['patients.view', 'patients.create', 'patients.update', 'patients.merge', 'patients.view_clinical', 'patients.view_clinical_summary', 'patients.documents.upload', 'patients.documents.view', 'patients.consents.manage'],
        'appointments' => ['appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel'],
        'queue' => ['queue.view', 'queue.manage', 'queue.call'],
        'consultations' => ['consultations.view', 'consultations.write', 'vitals.record'],
        'prescriptions' => ['prescriptions.write', 'prescriptions.print', 'prescriptions.share'],
        'billing' => ['invoices.view', 'invoices.create', 'invoices.void', 'discounts.apply', 'discounts.approve', 'payments.collect', 'refunds.request', 'refunds.approve'],
        'lab' => ['lab.view', 'lab.order', 'lab.worklist', 'lab.results.enter', 'lab.results.release', 'lab.catalogue.manage'],
        'inventory' => ['inventory.view', 'inventory.manage', 'inventory.purchase', 'inventory.adjust', 'inventory.dispense'],
        'notifications' => ['notifications.view', 'notifications.manage'],
        'reports' => ['reports.financial', 'reports.operational', 'reports.export'],
        'audit' => ['audit.view'],
        'ai' => ['ai.manage', 'ai.use'],
        'speciality' => ['speciality.write'],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(self::PERMISSIONS));
    }

    /** @return array<string, array{name:string, permissions: array<string>}> */
    public static function roles(): array
    {
        $all = self::all();
        $clinicalWrite = ['consultations.view', 'consultations.write', 'vitals.record', 'prescriptions.write', 'prescriptions.print', 'prescriptions.share', 'patients.view_clinical', 'speciality.write'];
        $owner = array_values(array_diff($all, $clinicalWrite)); // owners are not clinicians unless they also hold the doctor role
        $owner[] = 'patients.view_clinical'; // still gated by org setting admins_can_view_clinical
        $owner[] = 'consultations.view';
        $owner[] = 'prescriptions.print';
        $owner[] = 'prescriptions.share';

        return [
            'clinic_owner' => ['name' => 'Clinic Owner', 'permissions' => $owner],
            'clinic_admin' => ['name' => 'Clinic Admin', 'permissions' => array_values(array_diff($owner, ['subscription.manage', 'ai.manage']))],
            'branch_manager' => ['name' => 'Branch Manager', 'permissions' => [
                'organization.view', 'branches.view', 'schedules.manage', 'holidays.manage', 'services.view',
                'staff.view', 'staff.manage', 'attendance.manage', 'attendance.self', 'doctors.view', 'doctors.manage',
                'patients.view', 'patients.create', 'patients.update', 'patients.merge', 'patients.documents.upload', 'patients.documents.view', 'patients.consents.manage',
                'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'queue.view', 'queue.manage',
                'prescriptions.print', 'prescriptions.share', 'invoices.view', 'invoices.create', 'discounts.apply', 'discounts.approve', 'payments.collect', 'refunds.request',
                'lab.view', 'inventory.view', 'inventory.manage', 'inventory.purchase', 'inventory.adjust', 'inventory.dispense',
                'notifications.view', 'reports.financial', 'reports.operational', 'reports.export', 'audit.view', 'ai.use',
            ]],
            'doctor' => ['name' => 'Doctor', 'permissions' => [
                'organization.view', 'branches.view', 'services.view', 'doctors.view', 'doctors.manage_own', 'attendance.self',
                'patients.view', 'patients.create', 'patients.update', 'patients.view_clinical', 'patients.documents.upload', 'patients.documents.view',
                'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'queue.view', 'queue.call',
                'consultations.view', 'consultations.write', 'vitals.record', 'prescriptions.write', 'prescriptions.print', 'prescriptions.share', 'speciality.write',
                'discounts.apply', 'lab.view', 'lab.order', 'lab.results.release', 'reports.operational', 'reports.export', 'ai.use',
            ]],
            'receptionist' => ['name' => 'Receptionist', 'permissions' => [
                'organization.view', 'branches.view', 'services.view', 'doctors.view', 'attendance.self',
                'patients.view', 'patients.create', 'patients.update', 'patients.documents.upload', 'patients.documents.view', 'patients.consents.manage',
                'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'queue.view', 'queue.manage', 'queue.call',
                'prescriptions.print', 'prescriptions.share', 'invoices.view', 'invoices.create', 'discounts.apply', 'payments.collect', 'refunds.request',
                'lab.view', 'lab.order', 'inventory.dispense', 'notifications.view', 'ai.use',
            ]],
            'nurse' => ['name' => 'Nurse', 'permissions' => [
                'branches.view', 'doctors.view', 'attendance.self', 'patients.view', 'patients.create', 'patients.update', 'patients.view_clinical_summary',
                'patients.documents.upload', 'patients.documents.view', 'appointments.view', 'appointments.create', 'queue.view', 'queue.manage', 'queue.call',
                'vitals.record', 'lab.view', 'lab.worklist', 'lab.results.enter', 'inventory.view', 'inventory.dispense',
            ]],
            'lab_technician' => ['name' => 'Lab Technician', 'permissions' => [
                'branches.view', 'attendance.self', 'patients.view', 'patients.documents.upload', 'patients.documents.view',
                'lab.view', 'lab.worklist', 'lab.results.enter', 'lab.results.release', 'lab.catalogue.manage', 'reports.operational',
            ]],
            'accountant' => ['name' => 'Accountant', 'permissions' => [
                'organization.view', 'subscription.view', 'branches.view', 'services.view', 'attendance.self', 'patients.view',
                'invoices.view', 'invoices.create', 'invoices.void', 'discounts.apply', 'discounts.approve', 'payments.collect', 'refunds.request', 'refunds.approve',
                'inventory.view', 'inventory.purchase', 'inventory.adjust', 'reports.financial', 'reports.export', 'ai.use',
            ]],
        ];
    }
}
