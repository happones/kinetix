<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\CarbonImmutable;
use Happones\Kinetix\Support\Period;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class PeriodOrder extends Model
{
    protected $table = 'orders';
}

class PeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-06-27 14:30:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_range_resolves_relative_keys(): void
    {
        [$start, $end] = Period::range('7d');
        $this->assertSame('2026-06-21 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-27 23:59:59', $end->format('Y-m-d H:i:s'));

        [$start] = Period::range('30d');
        $this->assertSame('2026-05-29', $start->format('Y-m-d'));

        [$start, $end] = Period::range('today');
        $this->assertSame('2026-06-27 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-27', $end->format('Y-m-d'));

        [$start] = Period::range('month');
        $this->assertSame('2026-06-01', $start->format('Y-m-d'));

        [$start] = Period::range('year');
        $this->assertSame('2026-01-01', $start->format('Y-m-d'));
    }

    public function test_all_and_unknown_keys_have_no_bounds(): void
    {
        $this->assertSame([null, null], Period::range('all'));
        $this->assertSame([null, null], Period::range('bogus'));
    }

    public function test_custom_range_uses_from_and_to(): void
    {
        [$start, $end] = Period::range('custom', '2026-01-10', '2026-02-20');
        $this->assertSame('2026-01-10', $start->format('Y-m-d'));
        $this->assertSame('2026-02-20', $end->format('Y-m-d'));
    }

    public function test_from_request_reads_the_period_param(): void
    {
        $request       = Request::create('/dashboard', 'GET', ['period' => '7d']);
        [$start, $end] = Period::fromRequest($request);

        $this->assertSame('2026-06-21', $start->format('Y-m-d'));
        $this->assertNotNull($end);
    }

    public function test_scope_applies_bounds_only_when_present(): void
    {
        $bounded = Period::scope(PeriodOrder::query(), 'created_at', '7d');
        $this->assertCount(2, $bounded->getQuery()->getBindings());

        $unbounded = Period::scope(PeriodOrder::query(), 'created_at', 'all');
        $this->assertCount(0, $unbounded->getQuery()->getBindings());
    }
}
