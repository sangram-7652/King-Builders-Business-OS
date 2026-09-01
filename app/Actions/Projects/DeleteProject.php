<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Soft-deletes a project (and cascades a soft-delete to its blocks so the
 * hierarchy stays consistent). Refuses if downstream business data references
 * the project — that check lives in GuardsAgainstDestructiveDelete and grows as
 * M4+ modules land; it is a no-op today.
 */
class DeleteProject
{
    use RunsInTransaction;

    public function handle(Project $project): void
    {
        if ($project->hasBusinessDependents()) {
            throw new DomainException(
                'This project has dependent records ('.implode(', ', $project->blockingDependents()).
                ') and cannot be deleted. Archive it instead.'
            );
        }

        $this->transaction(function () use ($project): void {
            $project->blocks()->get()->each->delete(); // soft delete blocks
            $project->delete();

            Log::info('project.deleted', [
                'project_id' => $project->id,
                'code' => $project->code,
                'by' => auth()->id(),
            ]);
        });
    }
}
