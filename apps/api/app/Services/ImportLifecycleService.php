<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Events\ImportPriceObservationsCompleted;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;

final class ImportLifecycleService
{
    // Internal primitive. HTTP callers must authorize role and entitlement first.
    public function transition(Organization $organization, string $runId, ImportStatus $next): ImportRun
    {
        return DB::transaction(function () use ($organization, $runId, $next): ImportRun {
            $run = ImportRun::query()->where('organization_id', $organization->id)
                ->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof ImportRun) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }
            if (! $run->status->canTransitionTo($next)) {
                throw new ApiProblem('invalid_import_transition', 'The import cannot enter that state.', 409);
            }
            $run->status = $next;
            if ($next === ImportStatus::Processing) {
                $run->started_at = now();
            }
            if ($next->isTerminal()) {
                $run->finished_at = now();
            }
            $run->save();

            if (in_array($next, [ImportStatus::Completed, ImportStatus::PartiallyCompleted], true)) {
                DB::afterCommit(fn (): mixed => event(new ImportPriceObservationsCompleted(
                    $run->organization_id,
                    $run->restaurant_location_id,
                    $run->id,
                )));
            }

            return $run;
        });
    }
}
