<?php

declare(strict_types=1);

namespace App\Actions\Blocks;

use App\Exceptions\DomainException;
use App\Models\Block;
use Illuminate\Support\Facades\Log;

/**
 * Soft-deletes a block. Refuses if downstream business data (plots / bookings —
 * M4+) references it. No-op guard today; the extension point is in
 * GuardsAgainstDestructiveDelete.
 */
class DeleteBlock
{
    public function handle(Block $block): void
    {
        if ($block->hasBusinessDependents()) {
            throw new DomainException(
                'This block has dependent records ('.implode(', ', $block->blockingDependents()).
                ') and cannot be deleted. Deactivate it instead.'
            );
        }

        $block->delete();

        Log::info('block.deleted', [
            'project_id' => $block->project_id,
            'block_id' => $block->id,
            'by' => auth()->id(),
        ]);
    }
}
