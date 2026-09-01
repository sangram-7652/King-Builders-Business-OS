<?php

declare(strict_types=1);

namespace App\Masters\Resources;

use App\Enums\Masters\MasterGroup;
use App\Masters\Field;
use App\Masters\MasterResource;
use App\Models\Masters\DocumentType;
use Illuminate\Validation\Rule;

class DocumentTypeResource extends MasterResource
{
    public function model(): string
    {
        return DocumentType::class;
    }

    public function slug(): string
    {
        return 'document-types';
    }

    public function singularLabel(): string
    {
        return 'Document type';
    }

    public function pluralLabel(): string
    {
        return 'Document types';
    }

    public function group(): MasterGroup
    {
        return MasterGroup::Documents;
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->required()->help('e.g. Aadhaar, PAN, Booking Agreement.'),
            Field::text('code', 'Code'),
            Field::text('description', 'Description'),
            Field::sortOrder(),
            Field::toggle('is_active', 'Active'),
        ];
    }

    public function rules(?int $id, array $state = []): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('document_types', 'name')->ignore($id)->withoutTrashed()],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('document_types', 'code')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
