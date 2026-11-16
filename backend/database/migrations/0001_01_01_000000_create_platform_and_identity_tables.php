<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('price_monthly')->default(0); // paise
            $t->unsignedBigInteger('price_annual')->default(0);
            $t->string('currency', 3)->default('INR');
            $t->unsignedSmallInteger('trial_days')->default(14);
            $t->json('limits')->nullable();   // max_doctors, max_branches, max_staff, whatsapp_quota
            $t->json('features')->nullable(); // ["inventory","lab","speciality.dental",...]
            $t->boolean('is_public')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('specialities', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('module_key')->nullable(); // speciality module providing widgets/tables
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('organizations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('legal_name')->nullable();
            $t->string('slug')->unique();
            $t->string('email')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('gstin', 15)->nullable();
            $t->string('registration_no')->nullable();
            $t->json('address')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('country', 2)->default('IN');
            $t->string('timezone')->default('Asia/Kolkata');
            $t->string('currency', 3)->default('INR');
            $t->string('status', 32)->default('active')->index(); // pending|active|suspended|closed
            $t->timestamp('approved_at')->nullable();
            $t->string('suspended_reason')->nullable();
            $t->json('settings')->nullable();
            $t->json('enabled_modules')->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('plan_id')->constrained();
            $t->string('status', 32); // trialing|active|past_due|grace|suspended|cancelled
            $t->string('billing_cycle', 16)->default('monthly');
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable()->index();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('gateway')->nullable();
            $t->string('gateway_subscription_id')->nullable();
            $t->json('overrides')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
        });

        Schema::create('subscription_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('subscription_id')->constrained();
            $t->string('number')->unique();
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('tax')->default(0);
            $t->unsignedBigInteger('total');
            $t->string('status', 32)->default('due'); // due|paid|void
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_reference')->nullable();
            $t->timestamps();
        });

        Schema::create('global_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('email')->nullable()->unique();
            $t->string('mobile', 20)->nullable()->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('mobile_verified_at')->nullable();
            $t->string('password');
            $t->text('two_factor_secret')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->string('platform_role', 32)->nullable(); // super_admin|support|billing (platform plane)
            $t->string('status', 32)->default('active');
            $t->ulid('current_organization_id')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('locale', 8)->default('en');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignUlid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->ulidMorphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name')->unique();
            $t->string('module', 64)->index();
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained(); // null = system template
            $t->string('key', 64);
            $t->string('name');
            $t->string('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['organization_id', 'key']);
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('memberships', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('user_id')->constrained();
            $t->string('status', 32)->default('active'); // invited|active|disabled
            $t->boolean('all_branches')->default(false);
            $t->string('employee_code')->nullable();
            $t->string('designation')->nullable();
            $t->ulid('department_id')->nullable();
            $t->timestamp('joined_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });

        Schema::create('membership_role', function (Blueprint $t) {
            $t->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['membership_id', 'role_id']);
        });

        Schema::create('staff_invitations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->json('role_keys');
            $t->json('branch_ids')->nullable();
            $t->string('token_hash', 64)->index();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->foreignUlid('invited_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable();
            $t->ulid('branch_id')->nullable();
            $t->ulid('user_id')->nullable();
            $t->ulid('impersonator_id')->nullable();
            $t->string('event', 64);
            $t->string('auditable_type')->nullable();
            $t->ulid('auditable_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['organization_id', 'created_at']);
            $t->index(['auditable_type', 'auditable_id']);
            $t->index(['organization_id', 'user_id', 'created_at']);
        });

        Schema::create('impersonation_sessions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('platform_user_id')->constrained('users');
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('user_id')->constrained('users');
            $t->string('reason');
            $t->string('ticket_ref')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['impersonation_sessions', 'audit_logs', 'staff_invitations', 'membership_role', 'memberships', 'permission_role', 'roles', 'permissions', 'personal_access_tokens', 'sessions', 'password_reset_tokens', 'users', 'global_settings', 'subscription_invoices', 'subscriptions', 'organizations', 'specialities', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
