<?php

namespace App\Models;

use App\Enums\ModerationActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModerationAction extends Model
{
    protected $fillable = [
        'moderatable_type', 'moderatable_id', 'moderator_id', 'action',
        'reason', 'content_flag_id', 'previous_status', 'resulting_status',
    ];

    protected function casts(): array
    {
        return [
            'action' => ModerationActionType::class,
        ];
    }

    public function moderatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function contentFlag(): BelongsTo
    {
        return $this->belongsTo(ContentFlag::class);
    }
}
