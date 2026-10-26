<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Job;

use SilverShop\OrderWorkflow\Service\ScheduledTransitionService;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Recurring sweep that fires any due {@link ScheduledTransitionService} rules, then re-queues itself
 * `scan_interval_mins` later (one scan scheduled at a time). Mirrors the suite's other scan jobs
 * (e.g. {@link \SilverShop\AbandonedCart\Job\ScanAbandonedCartsJob}).
 *
 * Kick it off once with `ScheduledTransitionsTask --schedule`; it stays dormant until then. Visible and inspectable in
 * QueuedJobAdmin.
 */
class ScheduledTransitionsJob extends AbstractQueuedJob
{
    use Configurable;

    /**
     * Minutes between sweeps (the job re-queues itself this far in the future).
     */
    private static int $scan_interval_mins = 60;

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Fire scheduled order transitions');
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $result = ScheduledTransitionService::create()->sweep(true);

        if ($result['dormant']) {
            $this->addMessage(
                'Dormant: silvershop/core Order::setStatus() funnel (#928) not present — no transitions fired.'
            );
        } else {
            $parts = [];
            foreach ($result['rules'] as $name => $counts) {
                $parts[] = sprintf('%s %d/%d', $name, $counts['transitioned'], $counts['due']);
            }
            $this->addMessage(sprintf(
                'Fired %d transition(s)%s.',
                (int) $result['transitioned'],
                $parts === [] ? ' (no rules configured)' : ' [' . implode(', ', $parts) . ']'
            ));
        }

        $this->isComplete = true;

        // Re-arm the recurring sweep.
        self::scheduleNext();
    }

    /**
     * Schedule the next sweep `scan_interval_mins` from now, unless one is already queued/running.
     */
    public static function scheduleNext(): void
    {
        if (self::isScheduled()) {
            return;
        }

        $mins = max(1, (int) static::config()->get('scan_interval_mins'));
        $startAfter = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + $mins * 60);

        QueuedJobService::singleton()->queueJob(new ScheduledTransitionsJob(), $startAfter);
    }

    /**
     * Whether a sweep job is already queued or running (so duplicates never pile up).
     */
    public static function isScheduled(): bool
    {
        return QueuedJobDescriptor::get()
            ->filter([
                'Implementation' => self::class,
                'JobStatus' => [
                    QueuedJob::STATUS_NEW,
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                    QueuedJob::STATUS_PAUSED,
                ],
            ])
            ->exists();
    }
}
