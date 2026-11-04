<?php

namespace App\Models;

use App\Enums\AspirationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Aspiration extends Model
{
    protected $fillable = ['class_id', 'author_id', 'title', 'body'];

    protected function casts(): array
    {
        return [
            'status' => AspirationStatus::class,
            'supports_count' => 'integer',
            'comments_count' => 'integer',
            'is_pinned' => 'boolean',
            'last_activity_at' => 'datetime',
        ];
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function supports(): HasMany
    {
        return $this->hasMany(AspirationSupport::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AspirationComment::class);
    }

    public function contentFlags(): MorphMany
    {
        return $this->morphMany(ContentFlag::class, 'flaggable');
    }

    public function moderationActions(): MorphMany
    {
        return $this->morphMany(ModerationAction::class, 'moderatable');
    }

    public function isVisible(): bool
    {
        return $this->status?->isVisible() ?? false;
    }

    public function acceptsComments(): bool
    {
        return $this->status === AspirationStatus::Published;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AspirationStatus::Published->value,
            AspirationStatus::Locked->value,
        ]);
    }

    public function scopeForClass(Builder $query, int $classId): Builder
    {
        return $query->where('class_id', $classId);
    }
}
