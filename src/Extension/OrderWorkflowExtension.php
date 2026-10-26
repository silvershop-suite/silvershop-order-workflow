<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Extension;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Model\OrderTransitionLog;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Core\Extension;
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
 *    Writes the immutable transition log.
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
     * @param string      $from
     * @param string      $to
     * @param string|null $name
     * @return bool|null false vetoes the transition; null allows it
     */
    public function onBeforeStatusChange($from, $to, $name)
    {
        $order = $this->getOwner();
        $this->pending[spl_object_id($order)] = ['to' => (string) $to, 'name' => $name];

        if (!OrderWorkflowService::config()->get('strict')) {
            return null;
        }

        return OrderWorkflowService::singleton()->allows($order, (string) $from, (string) $to) ? null : false;
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

        if (!OrderWorkflowService::config()->get('log_transitions')) {
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
     * The transitions enabled from this order's current Status — the single source a consumer (e.g. order-admin's
     * status buttons) renders the allowed next steps from. Delegates to
     * {@link OrderWorkflowService::enabledTransitions()}.
     *
     * @return array<int,array{name:string,from:string,to:string}>
     */
    public function enabledTransitions(): array
    {
        return OrderWorkflowService::singleton()->enabledTransitions($this->getOwner());
    }
}
