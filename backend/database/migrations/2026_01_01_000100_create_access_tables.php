<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40)->unique();
            $t->string('label', 80);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('label', 120);
            $t->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('email')->nullable()->change();
            $t->string('username', 60)->unique()->after('name');
            $t->foreignId('role_id')->after('username')->constrained('roles');
            $t->string('pin')->nullable()->after('password');
            $t->boolean('is_active')->default(true)->after('pin');
            $t->softDeletes();
        });

        Schema::create('terminals', function (Blueprint $t) {
            $t->id();
            $t->string('device_id', 64)->unique();
            $t->string('code', 8)->unique();
            $t->string('name', 80)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('sequences', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->unsignedBigInteger('value')->default(0);
            $t->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100);
            $t->foreignId('user_id')->constrained();
            $t->string('route', 160);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->longText('response_body')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'key']);
            $t->index('created_at');
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('terminal_id')->nullable()->constrained();
            $t->string('action', 80);
            $t->string('entity_type', 60)->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('reason')->nullable();
            $t->foreignId('authorized_by')->nullable()->constrained('users');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id']);
            $t->index(['action', 'created_at']);
            $t->index('created_at');
        });

        Schema::create('staff_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('terminal_id')->nullable()->constrained();
            $t->string('activity', 60);
            $t->string('description')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
            $t->index(['activity', 'created_at']);
        });

        Schema::create('backup_logs', function (Blueprint $t) {
            $t->id();
            $t->string('status', 20);
            $t->string('trigger', 20)->default('schedule');
            $t->string('file_name')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('checksum', 64)->nullable();
            $t->text('error')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
        Schema::dropIfExists('staff_activities');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('terminals');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn(['username', 'pin', 'is_active', 'deleted_at']);
        });
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
