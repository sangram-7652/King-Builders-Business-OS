<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Exceptions\DomainException;
use App\Models\Masters\City;
use App\Models\Project;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Create or update a Project from already-validated data.
 *
 * Status transitions are NOT handled here — status only ever changes through
 * ChangeProjectStatus so the transition rules stay centralised. On create the
 * status is fixed to PLANNING.
 */
class SaveProject
{
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Project $project = null): Project
    {
        return $this->transaction(function () use ($data, $project): Project {
            $this->assertCityBelongsToState($data['state_id'] ?? null, $data['city_id'] ?? null);

            $creating = $project === null;
            $project ??= new Project;

            // Always collected, by both the create and edit forms.
            $project->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?: null,
                'address' => $data['address'] ?: null,
                'state_id' => $data['state_id'] ?: null,
                'city_id' => $data['city_id'] ?: null,
                'pincode' => $data['pincode'] ?: null,
                'launch_date' => $data['launch_date'] ?: null,
            ]);

            // Only the edit form collects these — the create form no longer
            // does, so these keys are simply absent from $data on create and
            // must not be assumed present.
            foreach (['code', 'latitude', 'longitude', 'contact_name', 'contact_phone', 'contact_email', 'logo_path', 'cover_image_path'] as $optional) {
                if (array_key_exists($optional, $data)) {
                    $project->{$optional} = $data[$optional] !== '' ? $data[$optional] : null;
                }
            }

            if ($creating) {
                $project->status = ProjectStatus::Planning;
                $project->is_active = true;
                $project->slug = Project::generateSlug($data['name']);

                if (! $project->code) {
                    $project->code = Project::generateCode($data['name']);
                }
            }

            $project->save();

            Log::info($creating ? 'project.created' : 'project.updated', [
                'project_id' => $project->id,
                'code' => $project->code,
                'by' => auth()->id(),
            ]);

            return $project->refresh();
        });
    }

    private function assertCityBelongsToState(mixed $stateId, mixed $cityId): void
    {
        if (! $cityId) {
            return;
        }

        if (! $stateId) {
            throw new DomainException('Select a state before choosing a city.');
        }

        $belongs = City::query()->whereKey($cityId)->where('state_id', $stateId)->exists();

        if (! $belongs) {
            throw new DomainException('The selected city does not belong to the selected state.');
        }
    }
}
