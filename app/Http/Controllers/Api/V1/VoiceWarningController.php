<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\VoiceWarningManager;
use Illuminate\Http\JsonResponse;

/**
 * Katalog Voice Warning Manager untuk frontend. Tidak memutar suara
 * sendiri; hanya menyatakan file mana yang tersedia dan placeholder.
 */
class VoiceWarningController extends Controller
{
    public function __construct(
        private readonly VoiceWarningManager $manager,
    ) {}

    public function index(): JsonResponse
    {
        return $this->respond($this->manager->catalog(), 'Katalog peringatan suara.');
    }
}
