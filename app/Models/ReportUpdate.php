<?php

namespace App\Models;

use App\Enums\ReportUpdateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportUpdate extends Model
{
    protected $fillable = ['report_id', 'author_id', 'type', 'body', 'is_internal', 'reply_to_id'];

    protected function casts(): array
    {
        return [
            'type' => ReportUpdateType::class,
            'is_internal' => 'boolean',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
