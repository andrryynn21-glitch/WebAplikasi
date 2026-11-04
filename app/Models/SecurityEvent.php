<?php

namespace App\Models;

use App\Enums\SecurityEventCategory;
use App\Enums\SecuritySeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Kejadian keamanan. Append-only.
 * Tidak pernah menyimpan password, token, header Authorization, ataupun
 * isi pengaduan lengkap di kolom context.
 */
class SecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'category', 'severity', 'resource_type', 'resource_ref',
        'denial_reason', 'route', 'method', 'ip_address', 'user_agent',
        'class_id', 'context',
    ];

    protected function casts(): array
    {
        return [
            'category' => SecurityEventCategory::class,
            'severity' => SecuritySeverity::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Security event bersifat append-only.'));
        static::deleting(fn () => throw new LogicException('Security event bersifat append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }
}
