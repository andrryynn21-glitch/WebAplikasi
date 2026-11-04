<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Upvote aspirasi. Unique (aspiration_id, user_id) di database menjamin
 * satu upvote aktif per pengguna, termasuk saat request bersamaan.
 * Membatalkan upvote menghapus baris ini.
 */
class AspirationSupport extends Model
{
    protected $fillable = ['aspiration_id', 'user_id'];

    public function aspiration(): BelongsTo
    {
        return $this->belongsTo(Aspiration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
