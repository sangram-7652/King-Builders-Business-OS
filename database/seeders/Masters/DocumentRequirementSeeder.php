<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\DocumentRequirement;
use App\Models\Masters\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Seeds a GLOBAL (project_id = null) checklist requirement for every document
 * type flagged `default_required`. Projects can override these through the
 * `document_requirements` table without any code change.
 */
class DocumentRequirementSeeder extends Seeder
{
    public function run(): void
    {
        DocumentType::query()->get()->each(function (DocumentType $type, int $i): void {
            DocumentRequirement::updateOrCreate(
                [
                    'project_id' => null,
                    'document_type_id' => $type->id,
                    'applies_to' => $type->applies_to->value,
                ],
                [
                    'required' => $type->default_required,
                    'sequence' => $type->sort_order,
                    'is_active' => true,
                ],
            );
        });
    }
}
