<?php

declare(strict_types=1);

namespace App\Actions\Blocks;

use App\Models\Block;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Create or update a Block within a Project from already-validated data.
 * Uniqueness of (project_id, code) is enforced by validation + a DB constraint.
 */
class SaveBlock
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Project $project, array $data, ?Block $block = null): Block
    {
        $creating = $block === null;
        $block ??= new Block(['project_id' => $project->id]);

        $block->fill([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?: null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $block->project()->associate($project);
        $block->save();

        Log::info($creating ? 'block.created' : 'block.updated', [
            'project_id' => $project->id,
            'block_id' => $block->id,
            'code' => $block->code,
            'by' => auth()->id(),
        ]);

        return $block;
    }
}
