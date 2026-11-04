<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isi sensitif sebuah pengaduan. Tabel terpisah supaya daftar laporan,
 * statistik, dashboard, dan ekspor tidak pernah menyentuhnya.
 */
class ReportPrivateDetail extends Model
{
    protected $fillable = ['report_id', 'description', 'involved_parties', 'desired_outcome', 'reporter_notes'];

    protected function casts(): array
    {
        return [
            'involved_parties' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
