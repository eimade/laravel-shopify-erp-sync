<?php

namespace App\Services;

use App\Domain\Enums\SyncStatus;
use App\Jobs\TrackedSyncJob;
use App\Models\SyncRun;
use InvalidArgumentException;

/**
 * Point d'entrée unique pour lancer un job de synchronisation :
 * crée la ligne de journal (SyncRun) puis met le job en file.
 */
class SyncDispatcher
{
    public function dispatch(TrackedSyncJob $job, ?SyncRun $replayOf = null): SyncRun
    {
        $type = $job::type();

        $run = SyncRun::create([
            'type' => $type,
            'direction' => $type->direction(),
            'subject' => $job->subject(),
            'status' => SyncStatus::Queued,
            'job_class' => $job::class,
            'payload' => $job->payload(),
            'replayed_from_id' => $replayOf?->id,
        ]);

        $job->syncRunId = $run->id;
        dispatch($job);

        return $run->refresh();
    }

    /**
     * Rejoue un job en échec / en dead-letter avec le même payload.
     */
    public function replay(SyncRun $run): SyncRun
    {
        if (! $run->status->isReplayable()) {
            throw new InvalidArgumentException("Seuls les jobs en échec ou en dead-letter peuvent être rejoués (statut actuel : {$run->status->label()}).");
        }

        $class = $run->job_class;
        if (! is_subclass_of($class, TrackedSyncJob::class)) {
            throw new InvalidArgumentException("Classe de job non rejouable : {$class}");
        }

        return $this->dispatch($class::fromPayload($run->payload ?? []), $run);
    }
}
