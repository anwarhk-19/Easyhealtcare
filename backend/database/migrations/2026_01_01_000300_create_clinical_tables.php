<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_templates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained(); // null = platform template
            $t->foreignUlid('speciality_id')->nullable()->constrained();
            $t->string('key', 64);
            $t->string('name');
            $t->unsignedInteger('version')->default(1);
            $t->json('definition');
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->ulid('parent_template_id')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'key', 'version']);
        });

        Schema::create('icd10_codes', function (Blueprint $t) {
            $t->string('code', 16)->primary();
            $t->string('description');
            $t->string('chapter', 64)->nullable();
            $t->index('description');
        });

        Schema::create('consultations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->ulid('patient_id');
            $t->ulid('doctor_profile_id');
            $t->foreignUlid('appointment_id')->nullable()->unique()->constrained();
            $t->foreignUlid('template_id')->nullable()->constrained('consultation_templates');
            $t->unsignedInteger('template_version')->nullable();
            $t->string('status', 16)->default('draft'); // draft|signed
            $t->text('chief_complaint')->nullable();
            $t->text('notes')->nullable(); // encrypted
            $t->text('treatment_plan')->nullable();
            $t->date('follow_up_date')->nullable();
            $t->string('follow_up_notes')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->foreignUlid('signed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['organization_id', 'patient_id', 'created_at']);
            $t->index(['doctor_profile_id', 'created_at']);
            $t->index(['organization_id', 'follow_up_date']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
            $t->foreign(['organization_id', 'doctor_profile_id'])->references(['organization_id', 'id'])->on('doctor_profiles');
        });

        Schema::create('consultation_sections', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('consultation_id')->constrained()->cascadeOnDelete();
            $t->string('section_key', 64);
            $t->json('data');
            $t->timestamps();
            $t->unique(['consultation_id', 'section_key']);
        });

        Schema::create('vitals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('consultation_id')->nullable()->constrained();
            $t->foreignUlid('appointment_id')->nullable()->constrained();
            $t->decimal('height_cm', 5, 1)->nullable();
            $t->decimal('weight_kg', 5, 1)->nullable();
            $t->decimal('bmi', 4, 1)->nullable();
            $t->decimal('temperature_c', 4, 1)->nullable();
            $t->unsignedSmallInteger('pulse_bpm')->nullable();
            $t->unsignedSmallInteger('resp_rate')->nullable();
            $t->unsignedSmallInteger('bp_systolic')->nullable();
            $t->unsignedSmallInteger('bp_diastolic')->nullable();
            $t->unsignedTinyInteger('spo2')->nullable();
            $t->unsignedSmallInteger('blood_sugar')->nullable();
            $t->unsignedTinyInteger('pain_score')->nullable();
            $t->foreignUlid('recorded_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['patient_id', 'created_at']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('consultation_diagnoses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('consultation_id')->constrained()->cascadeOnDelete();
            $t->string('icd10_code', 16)->nullable();
            $t->string('description');
            $t->string('type', 16)->default('provisional');
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
            $t->index(['organization_id', 'icd10_code']);
        });

        Schema::create('consultation_addenda', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('consultation_id')->constrained();
            $t->text('content'); // encrypted
            $t->foreignUlid('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('medicines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained(); // null = platform master
            $t->string('generic_name');
            $t->string('brand_name')->nullable();
            $t->string('form', 32)->nullable();
            $t->string('strength', 64)->nullable();
            $t->string('manufacturer')->nullable();
            $t->string('schedule', 8)->nullable();
            $t->string('drug_class')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['organization_id', 'brand_name']);
            $t->index('generic_name');
        });

        Schema::create('prescriptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('consultation_id')->unique()->constrained();
            $t->ulid('patient_id');
            $t->ulid('doctor_profile_id');
            $t->string('status', 16)->default('draft');
            $t->text('advice')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('shared_with_patient_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'patient_id', 'created_at']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('prescription_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('prescription_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('medicine_id')->nullable()->constrained();
            $t->string('medicine_name');
            $t->string('strength', 64)->nullable();
            $t->string('form', 32)->nullable();
            $t->string('dose', 32)->nullable();
            $t->string('frequency', 32);
            $t->unsignedSmallInteger('duration_value')->nullable();
            $t->string('duration_unit', 8)->default('days');
            $t->string('route', 32)->nullable();
            $t->string('timing', 32)->default('after_food');
            $t->string('instructions')->nullable();
            $t->unsignedSmallInteger('quantity')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('rx_templates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('doctor_profile_id')->nullable()->constrained();
            $t->string('name');
            $t->json('items');
            $t->text('advice')->nullable();
            $t->timestamps();
        });

        Schema::create('share_links', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->string('shareable_type');
            $t->ulid('shareable_id');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->unsignedSmallInteger('max_views')->default(20);
            $t->unsignedSmallInteger('view_count')->default(0);
            $t->timestamp('revoked_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        // Speciality module tables (registered by modules, never referenced by core)
        Schema::create('dental_tooth_records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('consultation_id')->nullable()->constrained();
            $t->unsignedTinyInteger('tooth_number'); // FDI notation
            $t->string('surfaces', 16)->nullable();
            $t->string('condition', 32);
            $t->string('procedure')->nullable();
            $t->string('status', 16)->default('existing'); // existing|planned|done
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->index(['patient_id', 'tooth_number']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('treatment_plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('doctor_profile_id')->constrained();
            $t->string('module', 32); // dental|physiotherapy|dermatology
            $t->string('name');
            $t->string('condition')->nullable();
            $t->unsignedSmallInteger('total_sessions')->nullable();
            $t->unsignedBigInteger('estimated_total')->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('treatment_plan_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('treatment_plan_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sitting_no')->default(1);
            $t->unsignedTinyInteger('tooth_number')->nullable();
            $t->foreignUlid('service_id')->nullable()->constrained();
            $t->string('description');
            $t->unsignedBigInteger('estimated_cost')->default(0);
            $t->string('status', 16)->default('planned');
            $t->json('session_data')->nullable(); // physio: modalities, exercises, pain_before/after
            $t->foreignUlid('completed_consultation_id')->nullable()->constrained('consultations');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('eye_examinations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('consultation_id')->unique()->constrained();
            $t->string('va_re_unaided', 8)->nullable();
            $t->string('va_le_unaided', 8)->nullable();
            $t->string('va_re_aided', 8)->nullable();
            $t->string('va_le_aided', 8)->nullable();
            $t->unsignedTinyInteger('iop_re')->nullable();
            $t->unsignedTinyInteger('iop_le')->nullable();
            $t->json('refraction')->nullable(); // {re:{sph,cyl,axis,add}, le:{...}}
            $t->json('findings')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['eye_examinations', 'treatment_plan_items', 'treatment_plans', 'dental_tooth_records', 'share_links', 'rx_templates', 'prescription_items', 'prescriptions', 'medicines', 'consultation_addenda', 'consultation_diagnoses', 'vitals', 'consultation_sections', 'consultations', 'icd10_codes', 'consultation_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
