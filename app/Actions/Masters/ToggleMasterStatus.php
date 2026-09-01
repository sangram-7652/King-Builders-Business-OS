<?php

declare(strict_types=1);

namespace App\Actions\Masters;

use App\Models\Masters\MasterModel;
use Illuminate\Support\Facades\Log;

class ToggleMasterStatus
{
    public function handle(MasterModel $model): MasterModel
    {
        $model->is_active = ! $model->is_active;
        $model->save();

        Log::info('master.status_changed', [
            'model' => $model::class,
            'id' => $model->getKey(),
            'is_active' => $model->is_active,
            'by' => auth()->id(),
        ]);

        return $model;
    }
}
