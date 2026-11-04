<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Riwayat status append-only. Baris yang sudah dibuat tidak boleh
 * diubah atau dihapus; koreksi dilakukan dengan transisi status baru.
 */
class ReportStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id', 'from_status', 'to_status', 'actor_id', 'reason', 'source',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ReportStatus::class,
            'to_status' => ReportStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Riwayat status bersifat append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Riwayat status bersifat append-only.');
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
