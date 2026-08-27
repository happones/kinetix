<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\TestCase;

/**
 * The memo primitive behind every per-request authorization verdict Kinetix
 * caches — super-admin, team owner, the billable's plan, the resolved billable
 * and entitlement verdicts. Its contract has to be exact: a wrong answer here
 * is a wrong authorization decision everywhere.
 */
class MemoTest extends TestCase
{
    protected function tearDown(): void
    {
        Memo::flush();

        parent::tearDown();
    }

    public function test_a_resolver_runs_once_per_subject_and_key(): void
    {
        $subject = new \stdClass;
        $calls   = 0;

        $resolve = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('value', Memo::remember('store', $subject, 'key', $resolve));
        }

        $this->assertSame(1, $calls);
    }

    public function test_null_is_a_cacheable_value(): void
    {
        $subject = new \stdClass;
        $calls   = 0;

        $resolve = function () use (&$calls): ?string {
            $calls++;

            return null;
        };

        // "No plan resolves" is a real answer, not a cache miss — re-running
        // the resolver for it would defeat the memo on exactly the path that
        // needs it most (a billing-less app).
        $this->assertNull(Memo::remember('store', $subject, 'key', $resolve));
        $this->assertNull(Memo::remember('store', $subject, 'key', $resolve));

        $this->assertSame(1, $calls);
    }

    public function test_distinct_subjects_never_share_a_value(): void
    {
        $jane = new \stdClass;
        $john = new \stdClass;

        $this->assertTrue(Memo::remember('store', $jane, 'key', static fn (): bool => true));
        $this->assertFalse(Memo::remember('store', $john, 'key', static fn (): bool => false));

        // The whole point: one user's verdict must never answer for another.
        $this->assertTrue(Memo::remember('store', $jane, 'key', static fn (): bool => false));
    }

    public function test_keys_separate_contexts_of_the_same_subject(): void
    {
        $user = new \stdClass;

        // The shape used for team-scoped verdicts: same user, different team.
        $this->assertTrue(Memo::remember('store', $user, 'team-1', static fn (): bool => true));
        $this->assertFalse(Memo::remember('store', $user, 'team-2', static fn (): bool => false));

        $this->assertTrue(Memo::remember('store', $user, 'team-1', static fn (): bool => false));
        $this->assertFalse(Memo::remember('store', $user, 'team-2', static fn (): bool => true));
    }

    public function test_stores_are_independent(): void
    {
        $subject = new \stdClass;

        Memo::remember('a', $subject, 'key', static fn (): string => 'from-a');
        Memo::remember('b', $subject, 'key', static fn (): string => 'from-b');

        $this->assertSame('from-a', Memo::remember('a', $subject, 'key', static fn (): string => 'x'));
        $this->assertSame('from-b', Memo::remember('b', $subject, 'key', static fn (): string => 'x'));
    }

    public function test_flushing_one_key_leaves_the_rest_intact(): void
    {
        $user = new \stdClass;

        Memo::remember('store', $user, 'a', static fn (): string => 'first-a');
        Memo::remember('store', $user, 'b', static fn (): string => 'first-b');

        Memo::flush('store', $user, 'a');

        $this->assertSame('second-a', Memo::remember('store', $user, 'a', static fn (): string => 'second-a'));
        $this->assertSame('first-b', Memo::remember('store', $user, 'b', static fn (): string => 'second-b'));
    }

    public function test_flushing_a_subject_clears_all_its_keys_only(): void
    {
        $jane = new \stdClass;
        $john = new \stdClass;

        Memo::remember('store', $jane, 'a', static fn (): string => 'jane-a');
        Memo::remember('store', $jane, 'b', static fn (): string => 'jane-b');
        Memo::remember('store', $john, 'a', static fn (): string => 'john-a');

        Memo::flush('store', $jane);

        $this->assertSame('fresh', Memo::remember('store', $jane, 'a', static fn (): string => 'fresh'));
        $this->assertSame('fresh', Memo::remember('store', $jane, 'b', static fn (): string => 'fresh'));
        $this->assertSame('john-a', Memo::remember('store', $john, 'a', static fn (): string => 'fresh'));
    }

    public function test_flushing_a_store_clears_it_and_leaves_others(): void
    {
        $subject = new \stdClass;

        Memo::remember('a', $subject, 'key', static fn (): string => 'from-a');
        Memo::remember('b', $subject, 'key', static fn (): string => 'from-b');

        Memo::flush('a');

        $this->assertSame('fresh', Memo::remember('a', $subject, 'key', static fn (): string => 'fresh'));
        $this->assertSame('from-b', Memo::remember('b', $subject, 'key', static fn (): string => 'fresh'));
    }

    public function test_flushing_everything_clears_every_store(): void
    {
        $subject = new \stdClass;

        Memo::remember('a', $subject, 'key', static fn (): string => 'from-a');
        Memo::remember('b', $subject, 'key', static fn (): string => 'from-b');

        // What the service provider calls at the start of every request/job.
        Memo::flush();

        $this->assertSame('fresh', Memo::remember('a', $subject, 'key', static fn (): string => 'fresh'));
        $this->assertSame('fresh', Memo::remember('b', $subject, 'key', static fn (): string => 'fresh'));
    }

    public function test_flushing_an_unknown_store_or_subject_is_a_no_op(): void
    {
        $known   = new \stdClass;
        $unknown = new \stdClass;

        Memo::remember('store', $known, 'key', static fn (): string => 'kept');

        Memo::flush('never-used');
        Memo::flush('store', $unknown);
        Memo::flush('store', $unknown, 'key');

        $this->assertSame('kept', Memo::remember('store', $known, 'key', static fn (): string => 'fresh'));
    }

    public function test_entries_are_released_when_the_subject_is_collected(): void
    {
        $subject = new \stdClass;
        Memo::remember('store', $subject, 'key', static fn (): string => 'value');

        // The reason this is a WeakMap and not a static array keyed by id:
        // entries die with the subject, so nothing leaks between requests in a
        // long-running process. A NEW object must resolve afresh even when the
        // old one occupied the same identity.
        unset($subject);

        $replacement = new \stdClass;

        $this->assertSame('fresh', Memo::remember('store', $replacement, 'key', static fn (): string => 'fresh'));
    }
}
