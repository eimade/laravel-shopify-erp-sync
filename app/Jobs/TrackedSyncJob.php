<?php

namespace App\Jobs;

use App\Domain\Enums\SyncStatus;
use App\Domain\Enums\SyncType;
use App\Exceptions\NonRetryableSyncException;
use App\Integrations\Shopify\ShopifyException;
use App\Models\SyncRun;
use App\Notifications\SyncJobDeadLettered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Socle des jobs de synchronisation :
 * - journalisation dans sync_runs (statut, tentatives, payload, résultat, erreur, durée) ;
 * - retries avec backoff exponentiel (10 s, 30 s, 90 s, 270 s) ;
 * - dead-letter : après épuisement des tentatives (ou erreur non rejouable), statut "dead",
 *   le job reste dans failed_jobs et l'équipe est alertée.
 */
abstract class TrackedSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $syncRunId = 0;

    abstract public static function type(): SyncType;

    /**
     * @param  array<string, mixed>  $payload
     */
    abstract public static function fromPayload(array $payload): static;

    /** @return array<string, mixed> */
    abstract public function payload(): array;

    abstract public function subject(): ?string;

    /**
     * Traitement métier. Les dépendances sont injectées par le conteneur.
     *
     * @return array<string, mixed> résultat stocké dans le journal
     */
    abstract protected function execute(): array;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90, 270];
    }

    public function handle(): void
    {
        $run = $this->run();
        $run?->update([
            'status' => SyncStatus::Running,
            'attempts' => $this->attempts(),
            'started_at' => now(),
            'error' => null,
        ]);
        $start = hrtime(true);

        try {
            $result = $this->execute();
        } catch (Throwable $e) {
            $run?->update([
                'status' => SyncStatus::Failed,
                'error' => $this->describe($e),
                'duration_ms' => $this->elapsedMs($start),
                'finished_at' => now(),
            ]);

            if (! $this->isRetryable($e)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $run?->update([
            'status' => SyncStatus::Succeeded,
            'result' => $result,
            'duration_ms' => $this->elapsedMs($start),
            'finished_at' => now(),
        ]);
    }

    /**
     * Appelé par Laravel quand le job part en dead-letter (tentatives épuisées ou fail()).
     */
    public function failed(?Throwable $e): void
    {
        $run = $this->run();
        if ($run === null) {
            return;
        }

        $run->update([
            'status' => SyncStatus::Dead,
            'error' => $e ? $this->describe($e) : $run->error,
            'finished_at' => now(),
        ]);

        Log::critical('SyncHub : job en dead-letter', ['sync_run_id' => $run->id, 'type' => $run->type->value, 'subject' => $run->subject]);

        $to = config('synchub.alert_email');
        if (is_string($to) && $to !== '') {
            Notification::route('mail', $to)->notify(new SyncJobDeadLettered($run));
        }
    }

    protected function isRetryable(Throwable $e): bool
    {
        if ($e instanceof NonRetryableSyncException) {
            return false;
        }
        if ($e instanceof ShopifyException) {
            return $e->retryable;
        }

        return true;
    }

    private function run(): ?SyncRun
    {
        return $this->syncRunId > 0 ? SyncRun::find($this->syncRunId) : null;
    }

    private function describe(Throwable $e): string
    {
        return class_basename($e).' : '.$e->getMessage();
    }

    private function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
