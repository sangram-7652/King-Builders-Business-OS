<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Archive / un-archive a project. This is the `is_active` flag — orthogonal to
 * the lifecycle `status` (a Completed project can still be de-listed, etc.).
 */
class ToggleProjectActive
{
    public function handle(Project $project): Project
    {
        $project->is_active = ! $project->is_active;
        $project->save();

        Log::info($project->is_active ? 'project.activated' : 'project.archived', [
            'project_id' => $project->id,
            'by' => auth()->id(),
        ]);

        return $project;
    }
}
