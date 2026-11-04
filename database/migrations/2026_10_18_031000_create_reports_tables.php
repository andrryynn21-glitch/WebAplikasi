<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema pengaduan.
 *
 * Keputusan penting: isi pengaduan (deskripsi, pihak yang terlibat,
 * harapan pelapor) TIDAK disimpan di tabel reports, melainkan di
 * report_private_details yang hanya dimuat lewat jalur berwenang.
 * Dengan begitu query daftar/dashboard/statistik tidak mungkin
 * membocorkan isi laporan secara tidak sengaja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('requires_human_review')->default(false);
            $table->string('auto_route_role', 32)->nullable(); // role tujuan routing otomatis
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 24)->unique();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('report_categories')->restrictOnDelete();

            $table->string('title', 200);
            $table->string('status', 32)->default('submitted')->index();
            $table->string('sensitivity', 16)->default('routine')->index();
            $table->string('routing_mode', 16)->default('manual')->index();

            $table->foreignId('current_handler_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Ringkasan hasil wajib diisi saat status menjadi resolved;
            // alasan wajib saat rejected. Keduanya bisa dibaca pelapor.
            $table->text('resolution_summary')->nullable();
            $table->string('rejection_reason', 1000)->nullable();

            $table->timestamps();

            $table->index(['class_id', 'status']);
            $table->index(['reporter_id', 'created_at']);
            $table->index(['class_id', 'category_id', 'status']);
            $table->index(['current_handler_id', 'status']);
        });

        Schema::create('report_private_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained('reports')->cascadeOnDelete();
            $table->longText('description');
            $table->json('involved_parties')->nullable();  // nama/peran pihak yang disinggung
            $table->text('desired_outcome')->nullable();
            $table->text('reporter_notes')->nullable();     // catatan internal pelapor
            $table->timestamps();
        });

        Schema::create('report_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->string('source', 32)->default('api'); // api / system / routing
            $table->timestamp('created_at')->useCurrent();

            $table->index(['report_id', 'created_at']);
            $table->index(['to_status', 'created_at']);
        });

        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignment_type', 24)->default('primary');
            $table->foreignId('previous_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'assignment_type', 'revoked_at']);
            $table->index(['assignee_id', 'revoked_at']);
        });

        Schema::create('report_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 32)->index();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->foreignId('reply_to_id')->nullable()->constrained('report_updates')->nullOnDelete();
            $table->timestamps();

            $table->index(['report_id', 'is_internal', 'created_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('type', 32);
            $table->string('path');                    // selalu di disk privat
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['uploaded_by', 'created_at']);
        });

        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');

            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();

            // Snapshot role saat banding diajukan, untuk keperluan audit.
            $table->string('report_status_at_submission', 32)->nullable();

            $table->timestamps();

            // Satu banding aktif per laporan. Banding lama tetap tersimpan
            // sebagai riwayat dan tidak menghalangi banding baru setelah diputuskan.
            $table->index(['report_id', 'status']);
            $table->index(['reviewer_id', 'status']);
        });

        Schema::create('conflict_of_interest_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // pihak yang berkonflik
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('declared')->index();
            $table->string('relationship', 64)->nullable(); // dosen_terlapor / keluarga / rekan / lain
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_of_interest_records');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('report_updates');
        Schema::dropIfExists('report_assignments');
        Schema::dropIfExists('report_status_histories');
        Schema::dropIfExists('report_private_details');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('report_categories');
    }
};
