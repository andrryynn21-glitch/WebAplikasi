<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\NotificationType;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\AspirationSupport;
use App\Models\ClassMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Aspirasi kelas: pembuatan, komentar, upvote, pembatalan upvote.
 *
 * - Upvote dijaga unique (aspiration_id, user_id) di database. Request
 *   bersamaan yang mencoba upvote ganda akan memicu pelanggaran unique
 *   dan diperlakukan sebagai idempotent (tidak menambah hitungan).
 * - Isi disimpan sebagai teks biasa; escaping dilakukan saat output.
 *   strip_tags juga diterapkan sebagai pertahanan berlapis.
 * - Aspirasi TIDAK PERNAH memuat atau mereferensikan isi pengaduan privat.
 */
class AspirationService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function create(User $author, array $data): Aspiration
    {
        Gate::forUser($author)->authorize('create', Aspiration::class);
        $classId = $this->assertClassAccess($author);

        $data = Validator::make([
            'title' => $this->sanitizeInline($data['title']),
            'body' => $this->sanitizeBody($data['body']),
        ], [
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($author, $data, $classId) {
            $aspiration = Aspiration::create([
                'class_id' => $classId,
                'author_id' => $author->id,
                'title' => $data['title'],
                'body' => $data['body'],
            ]);
            $aspiration->forceFill(['last_activity_at' => now()])->save();

            return $aspiration->refresh();
        });
    }

    public function comment(Aspiration $aspiration, User $author, string $body, ?int $parentId = null): AspirationComment
    {
        Gate::forUser($author)->authorize('comment', $aspiration);
        $classId = $this->assertClassAccess($author);

        if ($aspiration->class_id !== $classId) {
            throw ValidationException::withMessages([
                'aspiration' => 'Aspirasi berada di luar kelas Anda.',
            ]);
        }

        if (! $aspiration->acceptsComments()) {
            throw ValidationException::withMessages([
                'aspiration' => 'Komentar pada aspirasi ini sedang ditutup.',
            ]);
        }

        $body = Validator::make(['body' => $this->sanitizeBody($body)], [
            'body' => ['required', 'string', 'min:1', 'max:3000'],
        ])->validate()['body'];

        return DB::transaction(function () use ($aspiration, $author, $body, $parentId) {
            $aspiration = Aspiration::query()->lockForUpdate()->findOrFail($aspiration->id);
            Gate::forUser($author)->authorize('comment', $aspiration);

            if ($parentId !== null) {
                $parent = AspirationComment::query()->where('aspiration_id', $aspiration->id)
                    ->lockForUpdate()->find($parentId);

                if ($parent === null || ! $parent->isVisible()) {
                    throw ValidationException::withMessages(['parent_id' => 'Komentar induk tidak ditemukan.']);
                }
            }

            $comment = AspirationComment::create([
                'aspiration_id' => $aspiration->id,
                'author_id' => $author->id,
                'parent_id' => $parentId,
                'body' => $body,
            ]);

            Aspiration::withoutTimestamps(fn () => $aspiration->increment('comments_count'));
            $aspiration->forceFill(['last_activity_at' => now()])->save();

            if ($aspiration->author_id !== $author->id) {
                $this->notifications->send(
                    $aspiration->author,
                    NotificationType::AspirationComment,
                    'Komentar baru pada aspirasi Anda',
                    'Ada komentar baru pada aspirasi kelas.',
                    ['aspiration_id' => $aspiration->id, 'class_id' => $aspiration->class_id],
                );
            }

            return $comment;
        });
    }

    /**
     * Upvote idempotent. Mengembalikan true bila upvote baru dibuat,
     * false bila pengguna sudah pernah upvote.
     */
    public function support(Aspiration $aspiration, User $user): bool
    {
        Gate::forUser($user)->authorize('support', $aspiration);
        $classId = $this->assertClassAccess($user);

        if ($aspiration->class_id !== $classId) {
            throw ValidationException::withMessages([
                'aspiration' => 'Aspirasi berada di luar kelas Anda.',
            ]);
        }

        if (! $aspiration->isVisible()) {
            throw ValidationException::withMessages([
                'aspiration' => 'Aspirasi tidak tersedia.',
            ]);
        }

        try {
            DB::transaction(function () use ($aspiration, $user) {
                $aspiration = Aspiration::query()->lockForUpdate()->findOrFail($aspiration->id);
                Gate::forUser($user)->authorize('support', $aspiration);
                AspirationSupport::create([
                    'aspiration_id' => $aspiration->id,
                    'user_id' => $user->id,
                ]);

                Aspiration::withoutTimestamps(fn () => $aspiration->increment('supports_count'));
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            // Request bersamaan: upvote sudah tercatat. Tidak ada perubahan.
            return false;
        }
    }

    public function unsupport(Aspiration $aspiration, User $user): bool
    {
        Gate::forUser($user)->authorize('support', $aspiration);
        $classId = $this->assertClassAccess($user);

        if ($aspiration->class_id !== $classId) {
            throw ValidationException::withMessages([
                'aspiration' => 'Aspirasi berada di luar kelas Anda.',
            ]);
        }

        return DB::transaction(function () use ($aspiration, $user) {
            $aspiration = Aspiration::query()->lockForUpdate()->findOrFail($aspiration->id);
            Gate::forUser($user)->authorize('support', $aspiration);

            $deleted = AspirationSupport::query()
                ->where('aspiration_id', $aspiration->id)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted > 0) {
                Aspiration::withoutTimestamps(
                    fn () => $aspiration->decrement('supports_count', $deleted)
                );
            }

            return $deleted > 0;
        });
    }

    /**
     * Akses aspirasi mensyaratkan keanggotaan aktif di kelas dan identitas
     * terverifikasi (fitur kelas).
     */
    private function assertClassAccess(User $user): int
    {
        if (! $user->canUseClassFeatures()) {
            throw ValidationException::withMessages([
                'class' => 'Fitur kelas hanya tersedia untuk mahasiswa terverifikasi dengan keanggotaan kelas aktif.',
            ]);
        }

        $membership = ClassMembership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'class' => 'Anda tidak memiliki keanggotaan kelas aktif.',
            ]);
        }

        return $membership->class_id;
    }

    /**
     * Hapus tag/atribut untuk teks sebaris (judul).
     */
    private function sanitizeInline(string $value): string
    {
        return trim(strip_tags($value));
    }

    /**
     * Teks isi disimpan apa adanya setelah tag HTML dibuang. Frontend tetap
     * wajib melakukan output escaping.
     */
    private function sanitizeBody(string $value): string
    {
        return trim(strip_tags($value));
    }
}
