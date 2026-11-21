<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Tests;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Job\ScheduledTransitionsJob;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * The recurring sweep keeps itself scheduled: exactly one next run is queued, also when the job re-arms itself
 * from inside its own (still running) process().
 */
class ScheduledTransitionsJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Order::singleton()->hasMethod('setStatus')) {
            $this->markTestSkipped('Requires the silvershop/core Order::setStatus() funnel (core 6.3+).');
        }
        DBDatetime::set_mock_now('2026-11-21 10:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function statuses(): array
    {
        return QueuedJobDescriptor::get()
            ->filter('Implementation', ScheduledTransitionsJob::class)
            ->sort('ID')
            ->column('JobStatus');
    }

    public function testScheduleNextQueuesOneJob(): void
    {
        ScheduledTransitionsJob::scheduleNext();
        ScheduledTransitionsJob::scheduleNext();

        $this->assertSame([QueuedJob::STATUS_NEW], $this->statuses(), 'no duplicate queued sweeps');
        $this->assertTrue(ScheduledTransitionsJob::isScheduled());
    }

    public function testRunningSweepQueuesTheNextOne(): void
    {
        // The descriptor of the sweep that is running right now.
        $running = QueuedJobDescriptor::create([
            'Implementation' => ScheduledTransitionsJob::class,
            'JobTitle' => 'Fire scheduled order transitions',
            'JobStatus' => QueuedJob::STATUS_RUN,
            'Signature' => 'running-sweep',
        ]);
        $running->write();

        (new ScheduledTransitionsJob())->process();

        $this->assertSame([QueuedJob::STATUS_RUN, QueuedJob::STATUS_NEW], $this->statuses(), 'the next sweep is queued');

        $next = QueuedJobDescriptor::get()
            ->filter(['Implementation' => ScheduledTransitionsJob::class, 'JobStatus' => QueuedJob::STATUS_NEW])
            ->first();
        $this->assertSame('2026-11-21 11:00:00', (string) $next?->StartAfter, 'one scan_interval_mins (60) later');
    }

    public function testManualArmingDoesNotStackOnARunningSweep(): void
    {
        QueuedJobDescriptor::create([
            'Implementation' => ScheduledTransitionsJob::class,
            'JobStatus' => QueuedJob::STATUS_RUN,
            'Signature' => 'running-sweep',
        ])->write();

        ScheduledTransitionsJob::scheduleNext();

        $this->assertSame([QueuedJob::STATUS_RUN], $this->statuses(), 'a running sweep will re-arm itself');
    }
}
