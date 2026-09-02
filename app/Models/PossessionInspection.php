<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InspectionStatus;
use Database\Factories\PossessionInspectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site inspection result (M10). The latest row per case is the current
 * outcome; a FAILED / REINSPECTION_REQUIRED latest inspection blocks handover.
 *
 * @property InspectionStatus $status
 */
class PossessionInspection extends Model
{
    /** @use HasFactory<PossessionInspectionFactory> */
    use HasFactory;

    protected $fillable = [
        'possession_case_id', 'inspected_by', 'inspection_date', 'status',
        'remarks', 'report_document_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'possession_case_id' => 'integer',
            'inspected_by' => 'integer',
            'inspection_date' => 'date',
            'status' => InspectionStatus::class,
            'report_document_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PossessionCase, $this> */
    public function possessionCase(): BelongsTo
    {
        return $this->belongsTo(PossessionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function reportDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'report_document_id');
    }
}
