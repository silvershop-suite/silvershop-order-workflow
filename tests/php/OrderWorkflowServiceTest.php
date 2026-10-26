<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Tests;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Dev\SapphireTest;

/**
 * The Symfony Workflow graph itself: places derive from the Status enum, and
 * allows() answers from the configured transition table.
 */
class OrderWorkflowServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testPlacesAreTheStatusEnumValues(): void
    {
        $places = OrderWorkflowService::singleton()->getPlaces();

        $expected = [
            'Cart', 'Unpaid', 'Paid', 'Processing',
            'Sent', 'Complete', 'AdminCancelled', 'MemberCancelled',
        ];
        foreach ($expected as $place) {
            $this->assertContains($place, $places, "'$place' is a workflow place");
        }
        $this->assertNotContains('', $places, 'the blank enum option is not a place');
    }

    public function testAllowsDefinedTransition(): void
    {
        $service = OrderWorkflowService::singleton();

        $order = Order::create();
        $order->setField('Status', 'Cart');
        $this->assertTrue($service->allows($order, 'Cart', 'Unpaid'), 'Cart -> Unpaid is defined (placed_unpaid)');

        $order->setField('Status', 'Paid');
        $this->assertTrue($service->allows($order, 'Paid', 'Processing'), 'Paid -> Processing is defined (process)');
    }

    public function testRejectsUndefinedTransition(): void
    {
        $service = OrderWorkflowService::singleton();

        $order = Order::create();
        $order->setField('Status', 'Paid');
        $this->assertFalse($service->allows($order, 'Paid', 'Cart'), 'Paid -> Cart is not defined');

        $order->setField('Status', 'Complete');
        $this->assertFalse($service->allows($order, 'Complete', 'Processing'), 'Complete is terminal');
    }

    public function testUnknownOriginIsNotBlocked(): void
    {
        $service = OrderWorkflowService::singleton();

        $order = Order::create();
        $order->setField('Status', '');
        $this->assertTrue($service->allows($order, '', 'Cart'), 'unknown origin is allowed (cannot be validated)');
    }

    public function testEnabledTransitionsFromAStatus(): void
    {
        $service = OrderWorkflowService::singleton();

        $order = Order::create();
        $order->setField('Status', 'Paid');

        $names = array_column($service->enabledTransitions($order), 'name');
        sort($names);
        // From 'Paid' the graph enables process (→Processing), send (→Sent), admin_cancel, member_cancel.
        $this->assertSame(['admin_cancel', 'member_cancel', 'process', 'send'], $names);

        foreach ($service->enabledTransitions($order) as $transition) {
            $this->assertSame('Paid', $transition['from']);
            $this->assertNotSame('', $transition['to']);
        }
    }

    public function testEnabledTransitionsEmptyForTerminalOrUnknownStatus(): void
    {
        $service = OrderWorkflowService::singleton();

        $order = Order::create();
        $order->setField('Status', 'Complete');
        $this->assertSame([], $service->enabledTransitions($order), 'Complete is terminal → no enabled transitions');

        $order->setField('Status', '');
        $this->assertSame([], $service->enabledTransitions($order), 'blank status → none enabled');
    }
}
