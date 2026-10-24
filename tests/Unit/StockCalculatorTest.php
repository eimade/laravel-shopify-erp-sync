<?php

namespace Tests\Unit;

use App\Domain\StockCalculator;
use PHPUnit\Framework\TestCase;

class StockCalculatorTest extends TestCase
{
    private StockCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new StockCalculator;
    }

    public function test_available_sums_locations_minus_reserved_and_safety_stock(): void
    {
        $available = $this->calculator->available([
            ['on_hand' => 12, 'reserved' => 1, 'safety_stock' => 2],
            ['on_hand' => 3, 'reserved' => 0, 'safety_stock' => 1],
        ]);

        $this->assertSame(11, $available);
    }

    public function test_a_location_never_contributes_negative_stock(): void
    {
        $available = $this->calculator->available([
            ['on_hand' => 1, 'safety_stock' => 5],
            ['on_hand' => 4],
        ]);

        $this->assertSame(4, $available);
    }

    public function test_no_location_means_zero(): void
    {
        $this->assertSame(0, $this->calculator->available([]));
    }

    public function test_allocation_starts_with_best_stocked_location(): void
    {
        $allocation = $this->calculator->allocate(['boutique-paris' => 3, 'entrepot-lyon' => 10], 5);

        $this->assertSame(['entrepot-lyon' => 5], $allocation);
    }

    public function test_allocation_spreads_over_several_locations(): void
    {
        $allocation = $this->calculator->allocate(['boutique-paris' => 3, 'entrepot-lyon' => 4], 6);

        $this->assertSame(['entrepot-lyon' => 4, 'boutique-paris' => 2], $allocation);
    }

    public function test_oversell_is_charged_to_first_location(): void
    {
        $allocation = $this->calculator->allocate(['a' => 1, 'b' => 2], 5);

        $this->assertSame(['b' => 4, 'a' => 1], $allocation);
        $this->assertSame(5, array_sum($allocation));
    }
}
