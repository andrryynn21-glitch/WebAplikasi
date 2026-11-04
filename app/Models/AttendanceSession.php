<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'meeting_number', 'held_on', 'starts_at', 'ends_at', 'course_code', 'course_name', 'lecturer_name', 'topic', 'notes'])]
class AttendanceSession extends Model
{
    protected function casts(): array
    {
        return ['held_on' => 'date', 'finalized_at' => 'datetime', 'meeting_number' => 'integer', 'version' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(AttendanceCourse::class, 'course_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
