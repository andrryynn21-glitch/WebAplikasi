<?php

namespace App\Models;

use App\Enums\AssignmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAssignment extends Model
{
    protected $fillable = [
        'report_id', 'assignee_id', 'assigned_by', 'assignment_type',
        'previous_assignee_id', 'reason', 'assigned_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentType::class,
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
