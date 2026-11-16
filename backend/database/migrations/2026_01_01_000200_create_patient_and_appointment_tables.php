<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('mobile', 20)->unique();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamp('mobile_verified_at')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        Schema::create('patients', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('patient_code', 32);
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('full_name')->index();
            $t->string('gender', 16)->nullable(); // male|female|other|unknown
            $t->date('date_of_birth')->nullable();
            $t->unsignedSmallInteger('age_years_at_registration')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('alt_mobile', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('blood_group', 4)->nullable();
            $t->json('address')->nullable();
            $t->string('city')->nullable();
            $t->string('pincode', 10)->nullable();
            $t->text('abha_number')->nullable(); // encrypted
            $t->json('emergency_contact')->nullable();
            $t->ulid('guardian_patient_id')->nullable();
            $t->foreignUlid('registered_branch_id')->nullable()->constrained('branches');
            $t->string('preferred_language', 8)->nullable();
            $t->text('notes')->nullable();
            $t->ulid('merged_into_id')->nullable();
            $t->foreignUlid('patient_account_id')->nullable()->constrained();
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'patient_code']);
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'mobile']);
            $t->index(['organization_id', 'first_name', 'last_name']);
        });

        Schema::create('patient_allergies', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->string('allergen');
            $t->string('allergen_type', 16)->default('drug');
            $t->string('reaction')->nullable();
            $t->string('severity', 16)->default('moderate');
            $t->timestamps();
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('patient_conditions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->string('type', 16)->default('chronic'); // chronic|past_illness|surgery|family|social
            $t->string('name');
            $t->string('icd10_code', 16)->nullable();
            $t->string('status', 16)->default('active');
            $t->date('since_date')->nullable();
            $t->timestamps();
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('patient_medications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->string('name');
            $t->string('dosage')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('patient_documents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('branch_id')->nullable()->constrained();
            $t->string('category', 32)->default('other');
            $t->string('title');
            $t->string('file_path');
            $t->string('mime', 128);
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->string('scan_status', 16)->default('pending');
            $t->boolean('is_sensitive')->default(false);
            $t->ulid('consultation_id')->nullable();
            $t->ulid('lab_order_id')->nullable();
            $t->foreignUlid('uploaded_by')->nullable()->constrained('users');
            $t->timestamp('shared_with_patient_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'patient_id', 'category']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('consents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->string('purpose', 32);
            $t->string('status', 16)->default('granted');
            $t->string('version', 16)->default('1');
            $t->string('method', 32)->default('in_person');
            $t->timestamp('granted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignUlid('recorded_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['patient_id', 'purpose']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('patient_account_links', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('patient_account_id')->constrained();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('patient_id');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'patient_id']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('otp_codes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('identifier', 64)->index();
            $t->string('purpose', 32);
            $t->string('code_hash');
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('appointments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->ulid('branch_id');
            $t->ulid('patient_id');
            $t->ulid('doctor_profile_id');
            $t->foreignUlid('speciality_id')->nullable()->constrained();
            $t->foreignUlid('service_id')->nullable()->constrained();
            $t->string('type', 16)->default('scheduled'); // scheduled|walk_in|follow_up|online
            $t->string('source', 16)->default('reception');
            $t->dateTime('scheduled_start');
            $t->dateTime('scheduled_end');
            $t->string('status', 32)->default('booked');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamp('consult_started_at')->nullable();
            $t->timestamp('consult_ended_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->ulid('rescheduled_from_id')->nullable();
            $t->text('notes')->nullable();
            // Maintained by the model: non-null only while the slot is actively held -> unique => no double booking.
            $t->string('slot_lock', 80)->nullable()->unique();
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['organization_id', 'branch_id', 'scheduled_start']);
            $t->index(['doctor_profile_id', 'scheduled_start']);
            $t->index(['organization_id', 'patient_id', 'scheduled_start']);
            $t->index(['organization_id', 'status', 'scheduled_start']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
            $t->foreign(['organization_id', 'doctor_profile_id'])->references(['organization_id', 'id'])->on('doctor_profiles');
            $t->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches');
        });

        Schema::create('appointment_status_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('appointment_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->ulid('changed_by')->nullable();
            $t->string('reason')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('waitlist_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->ulid('patient_id');
            $t->foreignUlid('doctor_profile_id')->nullable()->constrained();
            $t->date('preferred_date');
            $t->string('notes')->nullable();
            $t->string('status', 16)->default('waiting');
            $t->foreignUlid('appointment_id')->nullable()->constrained();
            $t->timestamps();
            $t->index(['branch_id', 'preferred_date', 'status']);
            $t->foreign(['organization_id', 'patient_id'])->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('queue_tokens', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('doctor_profile_id')->constrained();
            $t->foreignUlid('appointment_id')->unique()->constrained();
            $t->date('queue_date');
            $t->unsignedInteger('token_number');
            $t->string('token_label', 16);
            $t->string('priority', 16)->default('normal'); // normal|priority|emergency
            $t->string('status', 16)->default('waiting'); // waiting|called|in_consultation|skipped|completed|transferred
            $t->timestamp('called_at')->nullable();
            $t->unsignedTinyInteger('called_count')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'branch_id', 'doctor_profile_id', 'queue_date', 'token_number'], 'queue_tokens_unique_number');
            $t->index(['doctor_profile_id', 'queue_date', 'status']);
        });

        Schema::create('queue_displays', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->string('name');
            $t->string('access_token', 64)->unique();
            $t->json('doctor_ids')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['queue_displays', 'queue_tokens', 'waitlist_entries', 'appointment_status_history', 'appointments', 'otp_codes', 'patient_account_links', 'consents', 'patient_documents', 'patient_medications', 'patient_conditions', 'patient_allergies', 'patients', 'patient_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
