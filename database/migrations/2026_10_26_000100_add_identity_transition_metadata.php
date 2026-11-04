<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            // Null marks legacy records; no historical approval is inferred or backfilled.
            $table->boolean('retains_verified')->nullable();
            $table->foreignId('previous_approved_id')->nullable()
                ->constrained('identity_verifications')->restrictOnDelete();
            $table->json('before_snapshot')->nullable();
            $table->text('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->dropForeign(['previous_approved_id']);
            $table->dropColumn(['retains_verified', 'previous_approved_id', 'before_snapshot', 'review_note']);
        });
    }
};
