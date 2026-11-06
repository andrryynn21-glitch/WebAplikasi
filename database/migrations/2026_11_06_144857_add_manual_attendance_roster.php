<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('nim', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['class_id', 'user_id']);
            // Legacy snapshots may share a NIM; new entries are checked under the class lock.
            $table->index(['class_id', 'nim']);
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('student_id')->nullable()->constrained('attendance_students')->restrictOnDelete();
            $table->unique(['session_id', 'student_id']);
        });
        Schema::table('attendance_courses', fn (Blueprint $table) => $table->unsignedTinyInteger('semester')->nullable());
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->unsignedTinyInteger('semester')->nullable());

        $timestamp = now();
        DB::table('attendance_records')->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.session_id')
            ->join('attendance_courses', 'attendance_courses.id', '=', 'attendance_sessions.course_id')
            ->select('attendance_records.id', 'attendance_records.user_id', 'attendance_records.name', 'attendance_records.nim', 'attendance_courses.class_id')
            ->chunkById(500, function ($records) use ($timestamp): void {
                foreach ($records as $record) {
                    $identity = ['class_id' => $record->class_id, 'user_id' => $record->user_id];
                    $studentId = DB::table('attendance_students')->where($identity)->value('id');
                    if ($studentId === null) {
                        $studentId = DB::table('attendance_students')->insertGetId([
                            ...$identity, 'name' => $record->name, 'nim' => $record->nim, 'is_active' => true,
                            'created_at' => $timestamp, 'updated_at' => $timestamp,
                        ]);
                    } else {
                        DB::table('attendance_students')->where('id', $studentId)->update(['name' => $record->name, 'nim' => $record->nim]);
                    }
                    // Preserve every historical snapshot and timestamp; only add its roster identity.
                    DB::table('attendance_records')->where('id', $record->id)->update(['student_id' => $studentId]);
                }
            }, 'attendance_records.id', 'id');

        DB::table('attendance_courses')->join('classes', 'classes.id', '=', 'attendance_courses.class_id')
            ->select('attendance_courses.id', 'classes.semester')->chunkById(500, function ($courses): void {
                foreach ($courses as $course) {
                    DB::table('attendance_courses')->where('id', $course->id)->update(['semester' => $course->semester]);
                    DB::table('attendance_sessions')->where('course_id', $course->id)->update(['semester' => $course->semester]);
                }
            }, 'attendance_courses.id', 'id');

        Schema::table('attendance_records', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable(false)->change());
        Schema::table('attendance_courses', fn (Blueprint $table) => $table->unsignedTinyInteger('semester')->nullable(false)->change());
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->unsignedTinyInteger('semester')->nullable(false)->change());
    }

    public function down(): void
    {
        if (DB::table('attendance_students')->whereNull('user_id')->exists() || DB::table('attendance_records')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Rollback ditolak: daftar mahasiswa manual tidak dapat diubah kembali menjadi akun. Gunakan perbaikan maju agar data tidak hilang.');
        }
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'student_id']);
            $table->dropConstrainedForeignId('student_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->dropColumn('semester'));
        Schema::table('attendance_courses', fn (Blueprint $table) => $table->dropColumn('semester'));
        Schema::dropIfExists('attendance_students');
    }
};
