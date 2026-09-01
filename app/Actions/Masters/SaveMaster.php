<?php

declare(strict_types=1);

namespace App\Actions\Masters;

use App\Masters\MasterResource;
use App\Models\Masters\MasterModel;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Create or update one master row from already-validated form state.
 */
class SaveMaster
{
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $state  validated form state
     */
    public function handle(MasterResource $resource, array $state, ?MasterModel $model = null): MasterModel
    {
        return $this->transaction(function () use ($resource, $state, $model): MasterModel {
            $model ??= $resource->newModel();
            $wasNew = ! $model->exists;

            $model->fill($resource->toAttributes($state));
            $model->save();

            Log::info($wasNew ? 'master.created' : 'master.updated', [
                'resource' => $resource->slug(),
                'id' => $model->getKey(),
                'by' => auth()->id(),
            ]);

            return $model;
        });
    }
}
