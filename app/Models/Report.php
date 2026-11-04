<?php

namespace App\Models;

use App\Enums\ReportSensitivity;
use App\Enums\ReportStatus;
use App\Enums\RoutingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Laporan pengaduan. Baris ini hanya memuat metadata + status.
 * Isi laporan berada di ReportPrivateDetail.
 *
 * Kolom yang tidak fillable (status, current_handler_id, routing_mode,
 * resolved_at, dst.) hanya boleh diubah oleh ReportWorkflowService /
 * ReportRoutingService agar transisi tercatat dan tidak dapat dipalsukan
 * dari payload frontend.
 */
class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id', 'academic_term_id', 'reporter_id', 'category_id', 'title',
        'sensitivity', 'resolution_summary', 'rejection_reason',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_ref';
    }

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'sensitivity' => ReportSensitivity::class,
            'routing_mode' => RoutingMode::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report) {
            $report->public_ref ??= static::generatePublicRef();
        });

        // Jangan pernah menghapus pengaduan: riwayat penanganan wajib utuh.
        static::deleting(function () {
            throw new \LogicException('Pengaduan tidak dapat dihapus. Gunakan alur status.');
        });
    }

    /**
     * Referensi publik non-sequential agar tidak membocorkan volume laporan.
     */
    public static function generatePublicRef(): string
    {
        do {
            $ref = 'PGD-'.strtoupper(Str::random(10));
        } while (static::query()->where('public_ref', $ref)->exists());

        return $ref;
    }

    // ----------------------------------------------------------------- relasi

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class, 'category_id');
    }

    public function currentHandler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_handler_id');
    }

    /**
     * Isi laporan. Query yang memuat relasi ini WAJIB melalui pemeriksaan
     * otorisasi (ReportPolicy::viewPrivateDetails).
     */
    public function privateDetails(): HasOne
    {
        return $this->hasOne(ReportPrivateDetail::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReportStatusHistory::class)->latest('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReportAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('revoked_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ReportUpdate::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    public function openAppeal(): HasOne
    {
        return $this->hasOne(Appeal::class)->whereIn('status', ['pending', 'under_review']);
    }

    public function conflictsOfInterest(): HasMany
    {
        return $this->hasMany(ConflictOfInterestRecord::class);
    }

    // ------------------------------------------------------------------ bantuan

    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? true;
    }

    /**
     * Pengguna adalah pelapor laporan ini.
     */
    public function isReportedBy(User $user): bool
    {
        return $this->reporter_id === $user->id;
    }

    /**
     * Pengguna memiliki penugasan aktif pada laporan ini.
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->activeAssignments()
            ->where('assignee_id', $user->id)
            ->exists();
    }

    /**
     * Pengguna punya konflik kepentingan yang belum dibersihkan.
     */
    public function hasConflictFor(User $user): bool
    {
        return $this->conflictsOfInterest()
            ->where('user_id', $user->id)
            ->whereIn('status', ['declared', 'confirmed'])
            ->exists();
    }

    public function scopeForClass(Builder $query, int $classId): Builder
    {
        return $query->where('class_id', $classId);
    }

    /** @param array<int, int> $classIds */
    public function scopeForClasses(Builder $query, array $classIds): Builder
    {
        return $query->whereIn('class_id', $classIds);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        // Keep the PHP subject-matching rule in ReportPolicy authoritative,
        // including before pagination and aggregates. Never load report bodies.
        $classIds = $user->hasAnyPermission(['reports.view.class', 'reports.view.assigned', 'reports.view.private'])
            ? $user->accessibleClassIds() : [];
        $visible = static::query()->select(['id', 'reporter_id', 'class_id'])
            ->where(fn ($q) => $q->where('reporter_id', $user->id)
                ->orWhereIn('class_id', $classIds))
            ->with('privateDetails:id,report_id,involved_parties')
            ->lazyById(200)
            ->filter(fn (self $report) => $user->can('view', $report))
            ->pluck('id')->all();

        return $query->whereIn($query->qualifyColumn('id'), $visible);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            ReportStatus::Resolved->value,
            ReportStatus::Rejected->value,
        ]);
    }
}
