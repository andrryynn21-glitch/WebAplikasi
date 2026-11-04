<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // contoh: 2026-1
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'starts_on']);
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->string('code', 64);           // contoh: IF-23-A
            $table->string('name');               // contoh: Informatika 2023 Kelas A
            $table->string('program_study', 128);
            $table->unsignedTinyInteger('semester')->default(1);
            $table->boolean('is_active')->default(true);

            // Kode undangan default per kelas. Kode bisa berputar
            // (class_invitation_codes) tanpa mengubah baris ini.
            $table->string('join_code', 32)->nullable()->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_term_id', 'code']);
            $table->index(['program_study', 'semester']);
        });

        Schema::create('class_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();

            $table->string('status', 24)->default('pending')->index();
            $table->string('nim', 32)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            // Satu baris per (mahasiswa, kelas). Riwayat keputusan tercatat
            // pada kolom status + audit log, bukan baris ganda.
            $table->unique(['user_id', 'class_id']);
            $table->index(['class_id', 'status']);
        });

        Schema::create('lecturer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 32)->default('coordinator'); // coordinator / assistant
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'role', 'ends_at']);
            $table->index(['lecturer_id', 'ends_at']);
        });

        Schema::create('class_administrator_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();  // null = masih aktif
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            // Kunci unik parsial: satu kelas hanya boleh punya satu ketua
            // aktif. MySQL/SQLite belum mendukung partial index secara portabel,
            // jadi baris aktif menulis class_id ke active_class_id sedangkan
            // baris non-aktif menulis NULL (NULL tidak ikut unique).
            $table->unsignedBigInteger('active_class_id')->nullable();
            $table->unique('active_class_id');

            $table->index(['user_id', 'ends_at']);
            $table->index(['class_id', 'ends_at']);
        });

        Schema::create('class_invitation_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->unsignedSmallInteger('max_uses')->nullable();
            $table->unsignedSmallInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nim', 32);
            $table->string('full_name');
            $table->string('ktm_path');                 // path privat di disk "private"
            $table->string('ktm_original_name')->nullable();
            $table->string('ktm_mime', 100)->nullable();
            $table->unsignedInteger('ktm_size')->nullable();
            $table->string('ktm_checksum', 64)->nullable();

            $table->string('status', 24)->default('pending_verification')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('class_invitation_codes');
        Schema::dropIfExists('class_administrator_assignments');
        Schema::dropIfExists('lecturer_assignments');
        Schema::dropIfExists('class_memberships');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('academic_terms');
    }
};
