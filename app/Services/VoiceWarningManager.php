<?php

namespace App\Services;

use App\Enums\SecurityEventCategory;
use App\Models\SecurityEvent;

/**
 * Voice Warning Manager.
 *
 * Suara hanyalah respons presentasi untuk akses ditolak / kejadian
 * mencurigakan. Service ini tidak pernah memutuskan akses, tidak pernah
 * membaca isi laporan, dan tidak pernah gagal keras: file yang belum
 * tersedia menghasilkan placeholder eksplisit.
 *
 * Kontrak response untuk frontend ada di response envelope:
 *   meta.voice_warning = {
 *     play: bool, sound: string|null, placeholder: bool,
 *     category: string, cooldown_seconds: int
 *   }
 */
class VoiceWarningManager
{
    /**
     * Susun instruksi audio dari security event (boleh null).
     */
    public function forEvent(?SecurityEvent $event): array
    {
        if (! config('security.voice_warnings.enabled', true) || $event === null) {
            return $this->silent();
        }

        $category = $event->category;

        if (! $category->triggersVoiceWarning()) {
            return $this->silent();
        }

        $soundKey = $category === SecurityEventCategory::RepeatedDenials
            ? 'repeated_denials'
            : 'access_denied';

        $definition = config('security.voice_warnings.sounds.'.$soundKey, []);
        $fileExists = ! empty($definition['file']) && is_file($definition['file']);

        return [
            'play' => true,
            'sound' => $fileExists ? basename($definition['file']) : null,
            'placeholder' => ! $fileExists,
            'category' => $category->value,
            'cooldown_seconds' => (int) config('security.voice_warnings.cooldown_seconds', 30),
        ];
    }

    /**
     * Katalog suara untuk endpoint publik /api/v1/security/voice-warnings.
     */
    public function catalog(): array
    {
        $sounds = [];

        foreach (config('security.voice_warnings.sounds', []) as $key => $definition) {
            $available = ! empty($definition['file']) && is_file($definition['file']);
            $sounds[] = [
                'key' => $key,
                'available' => $available,
                'file' => $available ? basename($definition['file']) : null,
                'placeholder' => $definition['placeholder'] ?? null,
            ];
        }

        return [
            'enabled' => (bool) config('security.voice_warnings.enabled', true),
            'cooldown_seconds' => (int) config('security.voice_warnings.cooldown_seconds', 30),
            'sounds' => $sounds,
        ];
    }

    private function silent(): array
    {
        return [
            'play' => false,
            'sound' => null,
            'placeholder' => false,
            'category' => null,
            'cooldown_seconds' => (int) config('security.voice_warnings.cooldown_seconds', 30),
        ];
    }
}
