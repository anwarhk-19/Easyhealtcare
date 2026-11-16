<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('name');
            $t->string('code', 16);
            $t->json('address')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('timezone')->default('Asia/Kolkata');
            $t->string('gstin', 15)->nullable();
            $t->string('state_code', 4)->nullable();
            $t->string('invoice_prefix', 16)->nullable();
            $t->string('letterhead_path')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'code']);
            $t->unique(['organization_id', 'id']);
        });

        Schema::create('branch_membership', function (Blueprint $t) {
            $t->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $t->primary(['membership_id', 'branch_id']);
        });

        Schema::create('organization_speciality', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('speciality_id')->constrained();
            $t->primary(['organization_id', 'speciality_id']);
        });

        Schema::create('departments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('name');
            $t->foreignUlid('speciality_id')->nullable()->constrained();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'name']);
        });

        Schema::create('operating_hours', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->unsignedTinyInteger('weekday'); // 0=Sun
            $t->time('opens_at');
            $t->time('closes_at');
            $t->timestamps();
            $t->index(['branch_id', 'weekday']);
        });

        Schema::create('holidays', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->nullable()->constrained();
            $t->date('date');
            $t->string('name');
            $t->timestamps();
            $t->index(['organization_id', 'date']);
        });

        Schema::create('tax_rates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('name');
            $t->decimal('rate', 5, 2)->default(0);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        Schema::create('services', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->string('type', 32)->default('procedure'); // consultation|procedure|diagnostic|package|other
            $t->foreignUlid('department_id')->nullable()->constrained();
            $t->foreignUlid('speciality_id')->nullable()->constrained();
            $t->unsignedBigInteger('price')->default(0);
            $t->foreignUlid('tax_rate_id')->nullable()->constrained();
            $t->string('hsn_sac', 16)->nullable();
            $t->unsignedSmallInteger('duration_min')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('service_branch_prices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('branch_id')->constrained();
            $t->unsignedBigInteger('price');
            $t->timestamps();
            $t->unique(['service_id', 'branch_id']);
        });

        Schema::create('sequences', function (Blueprint $t) {
            $t->id();
            $t->ulid('organization_id');
            $t->string('scope', 64)->default(''); // branch id / doctor id / ''
            $t->string('key', 32);
            $t->string('period', 16)->default('');
            $t->unsignedBigInteger('next_value')->default(1);
            $t->timestamps();
            $t->unique(['organization_id', 'scope', 'key', 'period']);
        });

        Schema::create('doctor_profiles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->string('title', 16)->default('Dr.');
            $t->json('qualifications')->nullable();
            $t->string('registration_council')->nullable();
            $t->string('registration_number')->nullable();
            $t->unsignedSmallInteger('experience_years')->nullable();
            $t->text('bio')->nullable();
            $t->string('signature_path')->nullable();
            $t->unsignedSmallInteger('consultation_duration_min')->default(15);
            $t->boolean('is_bookable_online')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
            $t->unique(['organization_id', 'id']);
        });

        Schema::create('doctor_speciality', function (Blueprint $t) {
            $t->foreignUlid('doctor_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('speciality_id')->constrained();
            $t->boolean('is_primary')->default(false);
            $t->primary(['doctor_profile_id', 'speciality_id']);
        });

        Schema::create('branch_doctor_profile', function (Blueprint $t) {
            $t->foreignUlid('doctor_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $t->primary(['doctor_profile_id', 'branch_id']);
        });

        Schema::create('doctor_fees', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('doctor_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('branch_id')->nullable()->constrained();
            $t->string('fee_type', 16); // new|follow_up|emergency|online
            $t->unsignedBigInteger('amount');
            $t->unsignedSmallInteger('follow_up_window_days')->default(7);
            $t->timestamps();
            $t->unique(['doctor_profile_id', 'branch_id', 'fee_type']);
        });

        Schema::create('doctor_schedules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('doctor_profile_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedSmallInteger('slot_minutes')->default(15);
            $t->unsignedSmallInteger('max_patients')->nullable();
            $t->boolean('allow_walk_in')->default(true);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
            $t->index(['doctor_profile_id', 'branch_id', 'weekday']);
        });

        Schema::create('doctor_leaves', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('doctor_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('branch_id')->nullable()->constrained();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('reason')->nullable();
            $t->string('status', 16)->default('approved');
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['doctor_profile_id', 'starts_at', 'ends_at']);
        });

        Schema::create('attendance_records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('branch_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->date('date');
            $t->dateTime('clock_in')->nullable();
            $t->dateTime('clock_out')->nullable();
            $t->string('shift')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'user_id', 'date']);
        });

        Schema::create('staff_leaves', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->date('from_date');
            $t->date('to_date');
            $t->string('reason')->nullable();
            $t->string('status', 16)->default('pending'); // pending|approved|rejected
            $t->foreignUlid('decided_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['staff_leaves', 'attendance_records', 'doctor_leaves', 'doctor_schedules', 'doctor_fees', 'branch_doctor_profile', 'doctor_speciality', 'doctor_profiles', 'sequences', 'service_branch_prices', 'services', 'tax_rates', 'holidays', 'operating_hours', 'departments', 'organization_speciality', 'branch_membership', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
