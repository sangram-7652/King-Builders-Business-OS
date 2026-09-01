<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentVersion> */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version' => fn (array $a) => (DocumentVersion::where('document_id', $a['document_id'])->max('version') ?? 0) + 1,
            'disk' => 'documents',
            'path' => 'booking/1/'.fake()->uuid().'.pdf',
            'original_filename' => 'scan.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', fake()->uuid()),
            'uploaded_at' => now(),
        ];
    }
}
