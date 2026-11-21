<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Extension;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Model\OrderTransitionLog;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Applied to {@link Order}. Ties the Symfony Workflow state machine to the order
 * lifecycle through core's two hooks:
 *
 *  - onBeforeStatusChange($from, $to, $name): fired at assignment time by
 *    Order::setStatus(), BEFORE the write. In strict mode it returns false to
 *    veto a transition the graph forbids; in advisory mode it only records the
 *    intent (so the audit log can attach the transition name).
 *  - onStatusChange($from, $to): fired at write time by Order::statusTransition().
 *    Writes the immutable transition log, unless core already audits transitions
 *    itself (silvershop/core 6.3+ `OrderTransition`), in which case core's record is
 *    the audit trail and nothing is written here (no double logging).
 *
 * @extends Extension<Order>
 */
class OrderWorkflowExtension extends Extension
{
    /**
     * Intent captured at onBeforeStatusChange (assignment time), keyed by order
     * object id, consumed at onStatusChange (write time) to attach the transition
     * name to the audit log. A plain instance property — deliberately NOT a
     * `private static`, which SilverStripe treats as immutable config — and keyed
     * by object id so the single shared extension instance never conflates two
     * orders written in the same request.
     *
     * @var array<int,array{to:string,name:string|null}>
     */
    private array $pending = [];

    /**
     * Names an unnamed change after the graph (by reference: core records the name in its OrderTransition audit),
     * then, in strict mode, vetoes a change the graph or its permission does not allow.
     *
     * @param string      $from
     * @param string      $to
     * @param string|null $name passed by reference from core's setStatus()
     * @return bool|null false vetoes the transition; null allows it
     */
    public function onBeforeStatusChange($from, $to, &$name)
    {
        $order = $this->getOwner();
        if ($name === null || $name === '') {
            $name = OrderWorkflowService::singleton()->transitionNameFor($order, (string) $to);
        }
        $this->pending[spl_object_id($order)] = ['to' => (string) $to, 'name' => $name];

        if (!OrderWorkflowService::config()->get('strict')) {
            return null;
        }

        $service = OrderWorkflowService::singleton();

        // Strict mode enforces BOTH the graph edge and the transition's optional permission.
        if (!$service->allows($order, (string) $from, (string) $to)) {
            return false;
        }
        if (!$service->permitsChange($order, (string) $from, (string) $to, is_string($name) ? $name : null)) {
            return false;
        }

        return null;
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function onStatusChange($from, $to): void
    {
        $order = $this->getOwner();
        $pending = $this->pending[spl_object_id($order)] ?? null;
        unset($this->pending[spl_object_id($order)]);

        // Core 6.3+ already writes a structured OrderTransition for this change (with the same
        // from/to/name/member); writing our own as well would record every transition twice.
        if (!OrderWorkflowService::config()->get('log_transitions') || OrderWorkflowService::coreAuditsTransitions()) {
            return;
        }

        $from = (string) $from;
        $to = (string) $to;
        if ($to === '' || $from === $to || !$order->isInDB()) {
            return;
        }

        $name = ($pending !== null && $pending['to'] === $to) ? $pending['name'] : null;

        $log = OrderTransitionLog::create();
        $log->OrderID = $order->ID;
        $log->FromStatus = $from;
        $log->ToStatus = $to;
        $log->TransitionName = $name;
        if ($member = Security::getCurrentUser()) {
            $log->MemberID = $member->ID;
        }
        $log->write();
    }

    /**
     * The transitions enabled from this order's current Status — the full graph-enabled set, each annotated with its
     * optional `permission`. Delegates to {@link OrderWorkflowService::enabledTransitions()}.
     *
     * @return array<int,array{name:string,from:string,to:string,permission:string|null}>
     */
    public function enabledTransitions(): array
    {
        return OrderWorkflowService::singleton()->enabledTransitions($this->getOwner());
    }

    /**
     * The transitions the given member (default: the current user) may actually fire — {@link enabledTransitions()}
     * minus any gated behind a permission the member lacks. This is the list a CMS status-button row should render.
     * Delegates to {@link OrderWorkflowService::permittedTransitions()}.
     *
     * @param Member|null $member
     * @return array<int,array{name:string,from:string,to:string,permission:string|null}>
     */
    public function permittedTransitions(?Member $member = null): array
    {
        return OrderWorkflowService::singleton()->permittedTransitions($this->getOwner(), $member);
    }
}
