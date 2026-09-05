<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Http\Controllers\Reports\ReportExportController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for a report export (M11.5). `updated_at` is disabled;
 * written only by {@see ReportExportController}.
 *
 * `filters` stores the resolved filter query string (ids + enum values) only —
 * never customer or payment data.
 *
 * @property ReportType $report_type
 * @property ExportFormat $format
 * @property array<string, mixed> $filters
 */
class ReportExport extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'report_type', 'format', 'filters', 'row_count', 'created_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'report_type' => ReportType::class,
            'format' => ExportFormat::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
