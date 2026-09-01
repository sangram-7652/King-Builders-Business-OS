<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Exceptions\DomainException;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * The only place a project's status changes. Enforces the transition map in
 * App\Enums\ProjectStatus — reopening a CLOSED project is not possible here.
 */
class ChangeProjectStatus
{
    public function handle(Project $project, ProjectStatus $target): Project
    {
        $current = $project->status;

        if ($current === $target) {
            return $project;
        }

        if (! $current->canTransitionTo($target)) {
            throw new DomainException(
                "A {$current->label()} project cannot move to {$target->label()}."
            );
        }

        $project->status = $target;
        $project->save();

        Log::info('project.status_changed', [
            'project_id' => $project->id,
            'from' => $current->value,
            'to' => $target->value,
            'by' => auth()->id(),
        ]);

        return $project;
    }
}
