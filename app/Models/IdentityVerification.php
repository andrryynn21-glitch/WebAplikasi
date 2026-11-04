<?php

namespace App\Models;

use App\Enums\IdentityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat pengajuan verifikasi identitas. File KTM disimpan di disk privat
 * dan hanya dapat diunduh melalui endpoint ber-otorisasi.
 */
class IdentityVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'nim', 'full_name', 'ktm_path', 'ktm_original_name',
        'ktm_mime', 'ktm_size', 'ktm_checksum', 'status',
        'reviewed_by', 'reviewed_at', 'rejection_reason', 'submitted_at',
    ];

    protected $hidden = ['ktm_path', 'ktm_checksum', 'before_snapshot', 'review_note', 'previous_approved_id'];

    protected function casts(): array
    {
        return [
            'status' => IdentityStatus::class,
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'ktm_size' => 'integer',
            'retains_verified' => 'boolean',
            'previous_approved_id' => 'integer',
            'before_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function recordedApprovalIdFor(User $user): ?int
    {
        return static::query()->where('user_id', $user->id)
            ->where('status', IdentityStatus::Verified->value)
            ->whereNotNull('retains_verified')
            ->orderByDesc('id')->value('id');
    }
}
