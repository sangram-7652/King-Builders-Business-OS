<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'documentable_type' => (new Booking)->getMorphClass(),
            'documentable_id' => Booking::factory()->confirmed(),
            'document_type_id' => fn () => DocumentType::query()->where('code', 'REGISTRY_DOC')->value('id')
                ?? DocumentType::factory()->create(['code' => 'REGISTRY_DOC', 'name' => 'Registry Document'])->id,
            'title' => 'Document',
            'status' => DocumentStatus::Pending->value,
        ];
    }

    public function forDocumentable($model): static
    {
        return $this->state(fn () => [
            'documentable_type' => $model->getMorphClass(),
            'documentable_id' => $model->getKey(),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Verified->value, 'verified_at' => now()]);
    }

    public function uploaded(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Uploaded->value]);
    }
}
