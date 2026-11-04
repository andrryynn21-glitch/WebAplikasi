<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AspirationComment extends Model
{
    protected $fillable = ['aspiration_id', 'author_id', 'parent_id', 'body'];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
        ];
    }

    public function aspiration(): BelongsTo
    {
        return $this->belongsTo(Aspiration::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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
        return $this->status === CommentStatus::Published;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', CommentStatus::Published->value);
    }
}
