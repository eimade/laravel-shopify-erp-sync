<?php

namespace App\Integrations\Shopify;

/**
 * Gestion du rate limit "cost-based" de l'Admin API GraphQL.
 *
 * Shopify attribue un seau de points (ex. 1000) qui se remplit à restoreRate points/s.
 * Chaque réponse renvoie extensions.cost.throttleStatus ; on mémorise l'état et on
 * attend juste ce qu'il faut avant la requête suivante si le seau est trop bas.
 */
final class CostThrottle
{
    private ?float $available = null;

    private float $restoreRate = 50.0;

    /** @var \Closure(float): void */
    private \Closure $sleeper;

    /**
     * @param  (\Closure(float): void)|null  $sleeper  fonction d'attente (injectable pour les tests)
     */
    public function __construct(private readonly int $minimumBuffer = 100, ?\Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * Nombre de secondes à attendre avant d'envoyer une requête du coût estimé.
     */
    public function delayFor(int $estimatedCost): float
    {
        if ($this->available === null) {
            return 0.0;
        }
        $needed = $estimatedCost + $this->minimumBuffer;
        if ($this->available >= $needed) {
            return 0.0;
        }

        return round(($needed - $this->available) / max(1.0, $this->restoreRate), 2);
    }

    public function waitFor(int $estimatedCost): float
    {
        $delay = $this->delayFor($estimatedCost);
        if ($delay > 0) {
            ($this->sleeper)($delay);
            $this->available = ($this->available ?? 0) + $delay * $this->restoreRate;
        }

        return $delay;
    }

    /**
     * Met à jour l'état depuis le bloc "extensions" d'une réponse GraphQL.
     *
     * @param  array<string, mixed>|null  $extensions
     */
    public function record(?array $extensions): void
    {
        $status = $extensions['cost']['throttleStatus'] ?? null;
        if (! is_array($status)) {
            return;
        }
        $this->available = (float) ($status['currentlyAvailable'] ?? 0);
        $this->restoreRate = (float) ($status['restoreRate'] ?? $this->restoreRate);
    }

    /** Attente en cas d'erreur THROTTLED : temps de reconstitution de la requête demandée. */
    public function backoffAfterThrottled(int $requestedCost): float
    {
        $this->available ??= 0.0;
        $delay = max(1.0, round(max(0, $requestedCost - $this->available) / max(1.0, $this->restoreRate), 2));
        ($this->sleeper)($delay);
        $this->available += $delay * $this->restoreRate;

        return $delay;
    }

    public function currentlyAvailable(): ?float
    {
        return $this->available;
    }
}
