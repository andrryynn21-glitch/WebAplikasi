<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Penugasan ketua kelas. Kolom active_class_id adalah kunci unik parsial:
 * baris aktif menulis class_id, baris non-aktif menulis NULL. Dengan begitu
 * database sendiri menolak dua ketua aktif pada satu kelas.
 */
class ClassAdministratorAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'class_id', 'assigned_by', 'starts_at', 'ends_at', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $assignment) {
            $assignment->active_class_id = $assignment->ends_at === null
                ? $assignment->class_id
                : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function revoke(string $reason, ?int $by = null): void
    {
        if ($this->ends_at !== null) {
            throw new LogicException('Penugasan ketua kelas sudah dicabut.');
        }

        $this->forceFill([
            'ends_at' => now(),
            'revocation_reason' => $reason,
        ])->save();
    }
}
