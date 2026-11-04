<?php

namespace App\Models;

use App\Enums\ConflictOfInterestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConflictOfInterestRecord extends Model
{
    protected $fillable = [
        'report_id', 'user_id', 'declared_by', 'status', 'relationship', 'description',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConflictOfInterestStatus::class,
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }
}
