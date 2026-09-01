<?php

declare(strict_types=1);

namespace App\Actions\Blocks;

use App\Models\Block;
use Illuminate\Support\Facades\Log;

class ToggleBlockActive
{
    public function handle(Block $block): Block
    {
        $block->is_active = ! $block->is_active;
        $block->save();

        Log::info($block->is_active ? 'block.activated' : 'block.deactivated', [
            'block_id' => $block->id,
            'by' => auth()->id(),
        ]);

        return $block;
    }
}
