<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aspirations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 24)->default('published')->index();
            $table->unsignedInteger('supports_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('last_activity_at')->nullable();

            // Pengaduan privat TIDAK boleh dihubungkan ke aspirasi publik.
            // Referensi opsional ini hanya untuk aspirasi lanjutan yang
            // dibuat secara eksplisit oleh staff sebagai topik publik.
            $table->foreignId('derived_from_report_id')->nullable()->constrained('reports')->nullOnDelete();

            $table->timestamps();

            $table->index(['class_id', 'status', 'is_pinned']);
            $table->index(['class_id', 'created_at']);
            $table->index(['author_id', 'created_at']);
        });

        Schema::create('aspiration_supports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aspiration_id')->constrained('aspirations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Satu mahasiswa hanya punya satu upvote aktif per aspirasi.
            // Batal upvote = DELETE baris, bukan kolom is_active, sehingga
            // unique constraint tetap menjamin integritas pada request bersamaan.
            $table->unique(['aspiration_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('aspiration_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aspiration_id')->constrained('aspirations')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('aspiration_comments')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 24)->default('published')->index();
            $table->timestamps();

            $table->index(['aspiration_id', 'status', 'created_at']);
        });

        Schema::create('content_flags', function (Blueprint $table) {
            $table->id();
            $table->morphs('flaggable');
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 64);
            $table->string('note', 500)->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Satu pengguna hanya dapat melaporkan konten yang sama sekali.
            $table->unique(['flaggable_type', 'flaggable_id', 'reported_by']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->morphs('moderatable');
            $table->foreignId('moderator_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('reason', 500);
            $table->foreignId('content_flag_id')->nullable()->constrained('content_flags')->nullOnDelete();
            $table->string('previous_status', 32)->nullable();
            $table->string('resulting_status', 32)->nullable();
            $table->timestamps();

            $table->index(['moderator_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('content_flags');
        Schema::dropIfExists('aspiration_comments');
        Schema::dropIfExists('aspiration_supports');
        Schema::dropIfExists('aspirations');
    }
};
