<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SecurityEventCategory;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Report;
use App\Services\PrivateStorageService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Semua file privat diunduh lewat sini. Tidak ada URL publik.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly PrivateStorageService $storage,
        private readonly SecurityEventService $security,
    ) {}

    public function download(Request $request, Attachment $attachment): StreamedResponse|JsonResponse
    {
        if (! $request->user()->can('download', $attachment)) {
            $report = $attachment->attachable;

            $event = $this->security->recordDenial(
                SecurityEventCategory::PrivateFileAccessAttempt,
                $request->user(),
                $request,
                $attachment,
                'Unduhan file privat tanpa wewenang.',
                $report instanceof Report ? $report->class_id : null,
            );

            return $this->fail(
                'Anda tidak berwenang mengunduh file ini.',
                'ATTACHMENT_ACCESS_DENIED',
                403,
                null,
                [],
                $event,
            );
        }

        return $this->storage->download($attachment->path, $attachment->original_name, $attachment->mime);
    }
}
