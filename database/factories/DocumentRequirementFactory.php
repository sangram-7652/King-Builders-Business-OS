<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentScope;
use App\Models\DocumentRequirement;
use App\Models\Masters\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentRequirement> */
class DocumentRequirementFactory extends Factory
{
    protected $model = DocumentRequirement::class;

    public function definition(): array
    {
        return [
            'project_id' => null,
            'document_type_id' => DocumentType::factory(),
            'applies_to' => DocumentScope::Booking->value,
            'required' => true,
            'sequence' => 0,
            'is_active' => true,
        ];
    }
}
