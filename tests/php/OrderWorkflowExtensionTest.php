<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Tests;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Model\OrderTransitionLog;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The extension wiring over core's setStatus() funnel: advisory logging by
 * default, opt-in strict enforcement.
 */
class OrderWorkflowExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The whole extension hangs off core's Order::setStatus() funnel (silvershop/core #928),
        // which is not yet in a tagged release. Skip rather than error when it is absent, so CI is
        // meaningful today and these integration tests self-activate once the funnel ships.
        if (!Order::singleton()->hasMethod('setStatus')) {
            $this->markTestSkipped('Requires the silvershop/core Order::setStatus() funnel (#928, unreleased).');
        }
    }

    private function newOrder(string $status = 'Cart'): Order
    {
        $order = Order::create();
        $order->setField('Status', $status); // seed start state without firing the funnel
        $order->write();

        return $order;
    }

    // --- advisory (default) ---

    public function testAdvisoryLogsTransitionWithName(): void
    {
        $order = $this->newOrder('Cart');

        $order->setStatus('Unpaid', 'placed_unpaid');
        $order->write();

        $this->assertSame('Unpaid', $order->Status);

        $log = OrderTransitionLog::get()->filter(['OrderID' => $order->ID])->last();
        $this->assertNotNull($log, 'a transition was logged');
        $this->assertSame('Cart', $log->FromStatus);
        $this->assertSame('Unpaid', $log->ToStatus);
        $this->assertSame('placed_unpaid', $log->TransitionName, 'intent name captured from onBeforeStatusChange');
    }

    public function testAdvisoryDoesNotBlockUndefinedTransition(): void
    {
        $order = $this->newOrder('Cart');

        // Cart -> Complete is NOT in the graph, but advisory mode never vetoes.
        $order->setStatus('Complete', 'jump');
        $order->write();

        $this->assertSame('Complete', $order->Status, 'advisory mode allows any transition');
        $this->assertNotNull(
            OrderTransitionLog::get()->filter(['OrderID' => $order->ID, 'ToStatus' => 'Complete'])->first(),
            'the (undefined) transition is still logged'
        );
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
