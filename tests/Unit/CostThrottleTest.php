<?php

namespace Tests\Unit;

use App\Integrations\Shopify\CostThrottle;
use PHPUnit\Framework\TestCase;

class CostThrottleTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private function throttle(int $buffer = 100): CostThrottle
    {
        return new CostThrottle($buffer, function (float $s) {
            $this->sleeps[] = $s;
        });
    }

    private function throttleStatus(float $available, float $restoreRate = 50): array
    {
        return ['cost' => ['throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => $available, 'restoreRate' => $restoreRate]]];
    }

    public function test_no_wait_before_first_response(): void
    {
        $throttle = $this->throttle();

        $this->assertSame(0.0, $throttle->waitFor(500));
        $this->assertSame([], $this->sleeps);
    }

    public function test_no_wait_when_bucket_has_enough_points(): void
    {
        $throttle = $this->throttle();
        $throttle->record($this->throttleStatus(900));

        $this->assertSame(0.0, $throttle->delayFor(102));
    }

    public function test_waits_just_enough_to_refill_the_bucket(): void
    {
        $throttle = $this->throttle();
        $throttle->record($this->throttleStatus(50, 50));

        // besoin : 102 + 100 de marge = 202 ; manque 152 points à 50 pts/s => 3,04 s
        $this->assertSame(3.04, $throttle->waitFor(102));
        $this->assertSame([3.04], $this->sleeps);
        $this->assertEqualsWithDelta(202, $throttle->currentlyAvailable(), 0.01);
    }

    public function test_backoff_after_throttled_error(): void
    {
        $throttle = $this->throttle();
        $throttle->record($this->throttleStatus(10, 100));

        $delay = $throttle->backoffAfterThrottled(310);

        $this->assertSame(3.0, $delay);
        $this->assertSame([3.0], $this->sleeps);
    }

    public function test_ignores_responses_without_cost_information(): void
    {
        $throttle = $this->throttle();
        $throttle->record(null);
        $throttle->record(['foo' => 'bar']);

        $this->assertNull($throttle->currentlyAvailable());
    }
}
