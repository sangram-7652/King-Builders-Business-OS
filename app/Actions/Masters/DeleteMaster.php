<?php

declare(strict_types=1);

namespace App\Actions\Masters;

use App\Exceptions\DomainException;
use App\Models\Masters\MasterModel;
use Illuminate\Support\Facades\Log;

/**
 * Soft-deletes a master row. Refuses when the row is:
 *  - a seeded system row (`is_system`), or
 *  - referenced by business / transactional data (`isReferenced()`).
 *
 * Referenced masters must stay historically resolvable — deactivate instead.
 */
class DeleteMaster
{
    public function handle(MasterModel $model): void
    {
        if ($model->isSystem()) {
            throw new DomainException('This is a system-defined value and cannot be deleted. Deactivate it instead.');
        }

        if ($model->isReferenced()) {
            throw new DomainException('This record is in use elsewhere and cannot be deleted. Deactivate it instead.');
        }

        $model->delete(); // soft delete

        Log::info('master.deleted', [
            'model' => $model::class,
            'id' => $model->getKey(),
            'by' => auth()->id(),
        ]);
    }
}
