<?php

namespace App\Models;

use App\Enums\ContentFlagStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentFlag extends Model
{
    protected $fillable = [
        'flaggable_type', 'flaggable_id', 'reported_by', 'reason', 'note',
        'status', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentFlagStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function flaggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
