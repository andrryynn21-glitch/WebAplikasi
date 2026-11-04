<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('lecturer_name', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['class_id', 'code']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('attendance_courses')->restrictOnDelete();
            $table->unsignedSmallInteger('meeting_number');
            $table->date('held_on');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('course_code', 32);
            $table->string('course_name', 160);
            $table->string('lecturer_name', 160);
            $table->string('topic')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'meeting_number']);
            $table->index(['course_id', 'status', 'held_on']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('attendance_sessions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('nim', 32)->nullable();
            $table->string('status', 16)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'user_id']);
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('attendance_sessions')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['session_id', 'id']);
        });

        // Upgrade existing installations without resyncing unrelated role permissions.
        DB::table('permissions')->insertOrIgnore([
            'key' => 'attendance.manage', 'name' => 'Mengelola absensi kelas',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('key', 'class_leader')->value('id');
        if ($roleId !== null) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => DB::table('permissions')->where('key', 'attendance.manage')->value('id'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('attendance_courses');
        DB::table('permissions')->where('key', 'attendance.manage')->delete();
    }
};
