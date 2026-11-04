<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi, audit log, dan security event.
 *
 * Catatan privasi: kolom payload pada notifications tidak pernah boleh
 * berisi isi pengaduan privat. Hanya kode referensi, status, dan judul aman.
 * Audit log menyimpan metadata perubahan, bukan salinan konten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 48)->index();
            $table->string('title', 200);
            $table->string('message', 500)->nullable();
            $table->json('data')->nullable();          // referensi id/ref, bukan isi rahasia
            $table->string('severity', 16)->default('info')->index();
            $table->string('action_url', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32)->nullable();
            $table->string('action', 48)->index();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->json('changes')->nullable();       // metadata (status lama/baru), bukan konten
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['class_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 48)->index();
            $table->string('severity', 16)->index();
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_ref', 120)->nullable(); // public_ref / id, bukan isi
            $table->string('denial_reason', 255)->nullable();
            $table->string('route', 160)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['category', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('role_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('from_role', 32)->nullable();
            $table->string('to_role', 32);
            $table->string('reason', 500);
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('rate_limit_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('cooldown_until')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_cooldowns');
        Schema::dropIfExists('role_change_requests');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
    }
};
