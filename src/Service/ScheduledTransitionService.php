<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Service;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Model\OrderTransitionLog;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Time-based ("scheduled" / "deferred") transitions: move an order on automatically once it has sat in a given status
 * for a configured duration — auto-cancel an order left Unpaid for N days, auto-complete a Sent order, dunning, etc.
 *
 * The transition is fired through core's {@link Order::setStatus()} funnel (silvershop/core #928), exactly like a
 * manual change, so the whole lifecycle lights up for free: the audit entry (core's OrderTransition on 6.3+, else the
 * module's {@link OrderTransitionLog}), status
 * notifications (silvershop-order-notifications) and ledger posting all happen without this module knowing about them.
 *
 * Opt-in: no rules are configured by default. The recurring {@link \SilverShop\OrderWorkflow\Job\ScheduledTransitionsJob}
 * (or the {@link \SilverShop\OrderWorkflow\Task\ScheduledTransitionsTask} from cron) calls {@link sweep()}.
 *
 * Dormant until the funnel ships: without core's setStatus() the sweep fires nothing (there is no safe way to light up
 * the log/notifications/ledger without it), mirroring the module's advisory-until-#928 posture.
 */
class ScheduledTransitionService
{
    use Injectable;
    use Configurable;

    /**
     * Named rules. Empty by default — the feature is entirely opt-in. Each rule:
     *   from:       the status an order must currently be in            (required, a place)
     *   to:         the status to move it to                            (required, a place)
     *   after:      how long it must have been in `from` before firing  (required, a strtotime duration, e.g. '+14 days')
     *   transition: the transition name/intent recorded on the log      (optional, defaults to the rule key)
     *
     * The (from → to) pair should be a real edge in OrderWorkflowService::$transitions so that strict mode permits it;
     * in advisory mode any pair fires (and is logged as off-graph).
     *
     * @var array<string,array{from:string,to:string,after:string,transition?:string}>
     */
    private static array $scheduled_transitions = [];

    /**
     * Upper bound on orders transitioned per rule per sweep, so one run can never block the queue indefinitely. The
     * most-overdue orders go first; the remainder are picked up on the next sweep.
     */
    private static int $batch_size = 100;

    /**
     * Whether the module is dormant because core's setStatus() funnel (#928) is not present. When dormant, {@link sweep()}
     * never fires a transition — firing a raw status write would skip the log, notifications and ledger that are the
     * whole point.
     */
    public function isDormant(): bool
    {
        return !Order::singleton()->hasMethod('setStatus');
    }

    /**
     * The validated, normalised rule set (invalid entries dropped). Keys are rule names.
     *
     * @return array<string,array{from:string,to:string,after:string,transition:string}>
     */
    public function rules(): array
    {
        $places = OrderWorkflowService::singleton()->getPlaces();

        $valid = [];
        foreach ((array) static::config()->get('scheduled_transitions') as $name => $def) {
            if (!is_array($def)) {
                continue;
            }

            $from = (string) ($def['from'] ?? '');
            $to = (string) ($def['to'] ?? '');
            $after = (string) ($def['after'] ?? '');

            if (!in_array($from, $places, true) || !in_array($to, $places, true) || $from === $to) {
                continue;
            }
            if ($this->durationSeconds($after) <= 0) {
                continue;
            }

            $valid[(string) $name] = [
                'from' => $from,
                'to' => $to,
                'after' => $after,
                'transition' => (string) ($def['transition'] ?? $name),
            ];
        }

        return $valid;
    }

    /**
     * Orders currently sitting in `$rule['from']` that entered it longer than `$rule['after']` ago, most-overdue first,
     * capped at `batch_size`.
     *
     * @param array{from:string,to:string,after:string,transition:string} $rule
     * @return array<int,Order>
     */
    public function dueOrders(array $rule): array
    {
        $from = $rule['from'];
        $threshold = $this->thresholdFor($rule['after']);
        $thresholdTs = (int) strtotime($threshold);
        $batch = max(1, (int) static::config()->get('batch_size'));

        // An order cannot have entered `from` before it existed, so Created <= threshold is a cheap necessary
        // pre-filter; we then refine each candidate against the true "entered `from`" time. Oldest first guarantees the
        // most-overdue are handled first and the rest progress on later sweeps.
        $candidates = Order::get()
            ->filter(['Status' => $from])
            ->filter('Created:LessThanOrEqual', $threshold)
            ->sort('Created', 'ASC')
            ->limit($batch * 5);

        $due = [];
        foreach ($candidates as $order) {
            if ((int) strtotime($this->enteredFromAt($order, $from)) <= $thresholdTs) {
                $due[] = $order;
                if (count($due) >= $batch) {
                    break;
                }
            }
        }

        return $due;
    }

    /**
     * Run every configured rule once: fire the due transitions (unless $apply is false, a dry run) and report counts.
     *
     * @return array{dormant:bool,transitioned:int,rules:array<string,array{due:int,transitioned:int}>}
     */
    public function sweep(bool $apply = true): array
    {
        $dormant = $this->isDormant();
        $out = ['dormant' => $dormant, 'transitioned' => 0, 'rules' => []];

        foreach ($this->rules() as $name => $rule) {
            $due = $this->dueOrders($rule);
            $done = 0;

            if ($apply && !$dormant) {
                foreach ($due as $order) {
                    // isDormant() has already confirmed the funnel is present. Routing through setStatus() (not a raw
                    // setField) is what fires the log + notifications + ledger. phpstan can't see the method because CI
                    // resolves the pre-#928 core tag — see the scoped ignore in phpstan.neon.
                    $order->setStatus($rule['to'], $rule['transition']);
                    $order->write();

                    if ((string) $order->Status === $rule['to']) {
                        $done++;
                    }
                }
            }

            $out['rules'][$name] = ['due' => count($due), 'transitioned' => $done];
            $out['transitioned'] += $done;
        }

        return $out;
    }

    /**
     * When the order most recently entered `$from`: the newest transition INTO that status across every audit trail
     * that may hold it, falling back to the order's creation time when there is none (logging off, or a pre-funnel
     * order). Both trails are read because a site that ran this module on a pre-6.3 core has its older transitions in
     * OrderTransitionLog and, after upgrading, its newer ones in core's OrderTransition.
     */
    private function enteredFromAt(Order $order, string $from): string
    {
        $latest = '';
        foreach ($this->auditTrailClasses() as $class) {
            $entry = DataObject::get($class)
                ->filter(['OrderID' => $order->ID, 'ToStatus' => $from])
                ->sort('Created', 'DESC')
                ->first();
            $created = $entry ? (string) $entry->Created : '';
            if ($created > $latest) {
                $latest = $created;
            }
        }

        return $latest !== '' ? $latest : (string) $order->Created;
    }

    /**
     * The audit-trail record classes present: always the module's own, plus core's when core ships it (6.3+).
     *
     * @return array<int,string>
     */
    private function auditTrailClasses(): array
    {
        $classes = [OrderTransitionLog::class];
        if (class_exists(OrderWorkflowService::CORE_AUDIT_CLASS)) {
            $classes[] = OrderWorkflowService::CORE_AUDIT_CLASS;
        }

        return $classes;
    }

    /**
     * The cutoff datetime: an order is due when it entered `from` at or before this. `after` is a strtotime duration
     * (e.g. '+14 days'), measured as a span from now, so it is computed relative to epoch and subtracted.
     */
    private function thresholdFor(string $after): string
    {
        $now = DBDatetime::now()->getTimestamp();

        return date('Y-m-d H:i:s', $now - $this->durationSeconds($after));
    }

    /**
     * The length of a strtotime duration string in seconds (0 when unparseable or non-positive).
     */
    private function durationSeconds(string $after): int
    {
        if ($after === '') {
            return 0;
        }

        $seconds = strtotime($after, 0);

        return is_int($seconds) && $seconds > 0 ? $seconds : 0;
    }
}
