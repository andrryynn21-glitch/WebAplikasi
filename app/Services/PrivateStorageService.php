<?php

namespace App\Services;

use App\Enums\AttachmentType;
use App\Enums\IdentityStatus;
use App\Models\Attachment;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Penyimpanan file privat (KTM, lampiran pengaduan).
 *
 * - Semua file ditulis ke disk "private" (storage/app/private, tidak
 *   terhubung ke public/storage).
 * - Nama file diacak; nama asli hanya disimpan sebagai metadata.
 * - Pengunduhan WAJIB melalui endpoint yang memeriksa policy attachable.
 */
class PrivateStorageService
{
    public function storeIdentityDocument(User $user, UploadedFile $file): IdentityVerification
    {
        $this->assertAllowed($file, AttachmentType::IdentityDocument);
        $checksum = $this->checksum($file);

        $path = $file->storeAs(
            'identity/'.$user->id,
            Str::random(40).'.'.$file->extension(),
            AttachmentType::IdentityDocument->disk(),
        );
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Penyimpanan berkas privat gagal.');
        }

        try {
            return IdentityVerification::create([
                'user_id' => $user->id,
                'nim' => (string) $user->nim,
                'full_name' => $user->name,
                'ktm_path' => $path,
                'ktm_original_name' => $this->safeFilename($file->getClientOriginalName()),
                'ktm_mime' => $file->getMimeType(),
                'ktm_size' => $file->getSize(),
                'ktm_checksum' => $checksum,
                'status' => IdentityStatus::PendingVerification,
                'submitted_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->delete($path);

            throw $exception;
        }
    }

    public function storeAttachment(UploadedFile $file, Model $attachable, User $uploader, AttachmentType $type): Attachment
    {
        $this->assertAllowed($file, $type);
        $checksum = $this->checksum($file);

        $path = $file->storeAs(
            'attachments/'.now()->format('Y/m'),
            Str::random(40).'.'.$file->extension(),
            $type->disk(),
        );
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Penyimpanan berkas privat gagal.');
        }

        try {
            return Attachment::create([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'type' => $type->value,
                'path' => $path,
                'original_name' => $this->safeFilename($file->getClientOriginalName()),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'checksum' => $checksum,
                'uploaded_by' => $uploader->id,
            ]);
        } catch (Throwable $exception) {
            $this->delete($path);

            throw $exception;
        }
    }

    /**
     * Stream file privat. Pemanggil wajib sudah memeriksa otorisasi.
     */
    public function download(string $path, string $downloadName, string $mime): StreamedResponse
    {
        $disk = Storage::disk('private');
        $this->assertRelativePath($path);
        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));
        abort_unless($root !== false && $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            && is_file($resolved), 404, 'File tidak ditemukan.');

        return $disk->download($path, $this->safeFilename($downloadName), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->assertRelativePath($path);
            Storage::disk('private')->delete($path);
        }
    }

    private function assertAllowed(UploadedFile $file, AttachmentType $type): void
    {
        if (! $file->isValid() || $file->getSize() < 1) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak valid atau kosong.']);
        }
        $maxKb = min($type->maxSizeKb(), (int) config('security.uploads.'.$this->configKey($type).'_max_kb', $type->maxSizeKb()));

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => "Ukuran file melebihi batas {$maxKb} KB.",
            ]);
        }

        if (! in_array($file->getMimeType(), $type->allowedMimeTypes(), true)) {
            throw ValidationException::withMessages([
                'file' => 'Tipe file tidak diizinkan. Gunakan: '.implode(', ', $type->allowedMimeTypes()).'.',
            ]);
        }

        if (in_array($file->getMimeType(), ['image/jpeg', 'image/png'], true)) {
            $dimensions = @getimagesize($file->getRealPath());
            if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1
                || $dimensions[0] * $dimensions[1] > 25000000
                || ! function_exists('imagecreatefromstring')) {
                throw ValidationException::withMessages(['file' => 'Struktur atau dimensi gambar tidak dapat diproses dengan aman.']);
            }
            $image = @imagecreatefromstring($file->getContent());
            if ($image === false) {
                throw ValidationException::withMessages(['file' => 'Isi gambar rusak atau tidak valid.']);
            }
            unset($image);
        }

        if ($file->getMimeType() === 'application/pdf') {
            $content = $file->getContent();
            if (! str_starts_with($content, '%PDF-') || ! str_contains(substr($content, -2048), '%%EOF')) {
                throw ValidationException::withMessages(['file' => 'Struktur dasar PDF tidak valid.']);
            }
        }
    }

    private function checksum(UploadedFile $file): string
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            throw new RuntimeException('Checksum berkas tidak dapat dihitung.');
        }

        return $checksum;
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $name)));

        return mb_strcut($name ?: 'berkas', 0, 200, 'UTF-8');
    }

    private function assertRelativePath(string $path): void
    {
        abort_if($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) || in_array('..', explode('/', $path), true), 404);
    }

    private function configKey(AttachmentType $type): string
    {
        return match ($type) {
            AttachmentType::IdentityDocument => 'identity',
            AttachmentType::ReportAttachment => 'report',
            AttachmentType::AspirationImage => 'aspiration',
        };
    }
}
