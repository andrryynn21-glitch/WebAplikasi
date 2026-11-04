<?php

namespace App\Models;

use App\Enums\ReportCategoryCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'is_sensitive', 'requires_human_review', 'auto_route_role',
    ];

    protected function casts(): array
    {
        return [
            'code' => ReportCategoryCode::class,
            'is_sensitive' => 'boolean',
            'requires_human_review' => 'boolean',
        ];
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'category_id');
    }
}
