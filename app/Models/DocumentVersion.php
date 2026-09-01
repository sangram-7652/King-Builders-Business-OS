<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable file version of a {@see Document} (M9). `path` is on the private
 * `documents` disk — never exposed as a URL, only streamed by
 * App\Http\Controllers\DocumentDownloadController after authorization.
 */
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id', 'version', 'disk', 'path', 'original_filename',
        'mime_type', 'size_bytes', 'checksum', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'version' => 'integer',
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)."\u{00a0}{$unit}";
            }
            $bytes /= 1024;
        }

        return round($bytes, 1)."\u{00a0}TB";
    }
}
