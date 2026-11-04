<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Class" adalah kata kunci PHP, sehingga model bernama ClassRoom
 * dengan tabel `classes`.
 */
#[Fillable(['academic_term_id', 'code', 'name', 'program_study', 'semester', 'is_active', 'join_code', 'created_by'])]
class ClassRoom extends Model
{
    protected $table = 'classes';

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClassMembership::class, 'class_id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', 'active');
    }

    public function lecturerAssignments(): HasMany
    {
        return $this->hasMany(LecturerAssignment::class, 'class_id');
    }

    public function administratorAssignments(): HasMany
    {
        return $this->hasMany(ClassAdministratorAssignment::class, 'class_id');
    }

    public function invitationCodes(): HasMany
    {
        return $this->hasMany(ClassInvitationCode::class, 'class_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'class_id');
    }

    public function aspirations(): HasMany
    {
        return $this->hasMany(Aspiration::class, 'class_id');
    }

    public function currentLeader(): ?User
    {
        $leader = $this->administratorAssignments()
            ->where('starts_at', '<=', now())
            ->whereNull('ends_at')
            ->first()?->user;

        return $leader?->isClassLeaderOf($this->id) ? $leader : null;
    }
}
