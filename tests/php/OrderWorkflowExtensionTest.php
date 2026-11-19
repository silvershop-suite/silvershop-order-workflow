<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Tests;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Model\OrderTransitionLog;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

/**
 * The extension wiring over core's setStatus() funnel: advisory logging by
 * default, opt-in strict enforcement, and exactly one audit record per
 * transition (core's OrderTransition when core audits, else the module's log).
 */
class OrderWorkflowExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The whole extension hangs off core's Order::setStatus() funnel (silvershop/core #928, released in 6.3.0).
        // Skip rather than error on an older core, so these integration tests self-activate on a funnel-bearing core.
        if (!Order::singleton()->hasMethod('setStatus')) {
            $this->markTestSkipped('Requires the silvershop/core Order::setStatus() funnel (core 6.3+).');
        }
    }

    private function newOrder(string $status = 'Cart'): Order
    {
        $order = Order::create();
        $order->setField('Status', $status); // seed start state without firing the funnel
        $order->write();

        return $order;
    }

    /**
     * Audit records for an order into $to, per trail. 'core' is null on a core without OrderTransition.
     *
     * @return array{module:int,core:int|null}
     */
    private function recordCounts(Order $order, string $to): array
    {
        $filter = ['OrderID' => $order->ID, 'ToStatus' => $to];

        return [
            'module' => OrderTransitionLog::get()->filter($filter)->count(),
            'core' => class_exists(OrderWorkflowService::CORE_AUDIT_CLASS)
                ? DataObject::get(OrderWorkflowService::CORE_AUDIT_CLASS)->filter($filter)->count()
                : null,
        ];
    }

    /**
     * The single record for this transition, from whichever trail is active.
     */
    private function activeRecord(Order $order, string $to): ?DataObject
    {
        $class = OrderWorkflowService::coreAuditsTransitions()
            ? OrderWorkflowService::CORE_AUDIT_CLASS
            : OrderTransitionLog::class;

        return DataObject::get($class)->filter(['OrderID' => $order->ID, 'ToStatus' => $to])->first();
    }

    // --- advisory (default) ---

    public function testAdvisoryLogsTransitionWithName(): void
    {
        $order = $this->newOrder('Cart');

        $order->setStatus('Unpaid', 'placed_unpaid');
        $order->write();

        $this->assertSame('Unpaid', $order->Status);

        $log = $this->activeRecord($order, 'Unpaid');
        $this->assertNotNull($log, 'a transition was logged');
        $this->assertSame('Cart', $log->FromStatus);
        $this->assertSame('Unpaid', $log->ToStatus);
        $this->assertSame('placed_unpaid', $log->TransitionName, 'intent name captured');
    }

    public function testAdvisoryDoesNotBlockUndefinedTransition(): void
    {
        $order = $this->newOrder('Cart');

        // Cart -> Complete is NOT in the graph, but advisory mode never vetoes.
        $order->setStatus('Complete', 'jump');
        $order->write();

        $this->assertSame('Complete', $order->Status, 'advisory mode allows any transition');
        $this->assertNotNull($this->activeRecord($order, 'Complete'), 'the (undefined) transition is still logged');
    }

    // --- one record per transition (no double logging) ---

    public function testEachTransitionIsRecordedExactlyOnce(): void
    {
        $order = $this->newOrder('Cart');

        // Unpaid -> Processing rather than -> Paid: Paid makes core send the order email, which needs a full site.
        $order->setStatus('Unpaid', 'placed_unpaid');
        $order->write();
        $order->setStatus('Processing', 'process');
        $order->write();

        foreach (['Unpaid', 'Processing'] as $to) {
            $counts = $this->recordCounts($order, $to);
            $total = $counts['module'] + ($counts['core'] ?? 0);
            $this->assertSame(1, $total, "the transition into $to is recorded once");

            if (OrderWorkflowService::coreAuditsTransitions()) {
                $this->assertSame(1, $counts['core'], "core's OrderTransition holds the $to record");
                $this->assertSame(0, $counts['module'], "the module stays silent while core audits ($to)");
            }
        }
    }

    public function testModuleLogsWhenCoreAuditIsOff(): void
    {
        // A core with the audit trail switched off (or a core without one): the module's own log takes over.
        Config::modify()->set(Order::class, 'audit_status_transitions', false);
        $this->assertFalse(OrderWorkflowService::coreAuditsTransitions());

        $order = $this->newOrder('Cart');
        $order->setStatus('Unpaid', 'placed_unpaid');
        $order->write();

        $counts = $this->recordCounts($order, 'Unpaid');
        $this->assertSame(1, $counts['module'], 'the module logs the transition');
        $this->assertContains($counts['core'], [null, 0], 'core wrote nothing');
        $this->assertSame(
            'placed_unpaid',
            OrderTransitionLog::get()->filter(['OrderID' => $order->ID])->first()->TransitionName,
            'the module log still carries the intent name'
        );
    }

    public function testNothingIsLoggedWhenBothSwitchesAreOff(): void
    {
        Config::modify()->set(Order::class, 'audit_status_transitions', false);
        Config::modify()->set(OrderWorkflowService::class, 'log_transitions', false);

        $order = $this->newOrder('Cart');
        $order->setStatus('Unpaid', 'placed_unpaid');
        $order->write();

        $counts = $this->recordCounts($order, 'Unpaid');
        $this->assertSame(0, $counts['module'] + ($counts['core'] ?? 0), 'both switches off -> no record anywhere');
    }

    // --- strict (opt-in) ---

    public function testStrictVetoesIllegalTransition(): void
    {
        Config::modify()->set(OrderWorkflowService::class, 'strict', true);

        $order = $this->newOrder('Paid');

        // Paid -> Cart is not defined -> vetoed before the write.
        $order->setStatus('Cart', 'reopen');
        $this->assertSame('Paid', $order->getField('Status'), 'illegal transition is vetoed; status unchanged');

        // Paid -> Processing (process) is defined -> allowed.
        $order->setStatus('Processing', 'process');
        $this->assertSame('Processing', $order->getField('Status'), 'legal transition proceeds');
    }

    public function testStrictStillAllowsCoreOwnTransitions(): void
    {
        Config::modify()->set(OrderWorkflowService::class, 'strict', true);

        $order = $this->newOrder('Cart');

        // This is exactly what OrderProcessor::placeOrder() does.
        $order->setStatus('Unpaid', 'placed_unpaid');
        $this->assertSame('Unpaid', $order->getField('Status'), 'strict mode does not block core placement');
    }
}
