<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Tests;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Model\OrderTransitionLog;
use SilverShop\OrderWorkflow\Service\ScheduledTransitionService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Scheduled/deferred transitions: rule validation, "is an order due?" detection (independent of the core funnel), and
 * the dormant-vs-firing behaviour that depends on it.
 */
class ScheduledTransitionServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function service(): ScheduledTransitionService
    {
        return ScheduledTransitionService::create();
    }

    /**
     * An order placed into $status at $enteredAt (mock-now controls both the record's Created and, optionally, a
     * matching transition-log entry's Created).
     */
    private function orderInStatusSince(string $status, string $enteredAt, bool $withLog = true): Order
    {
        DBDatetime::set_mock_now($enteredAt);
        $order = Order::create();
        $order->setField('Status', $status);
        $order->write();

        if ($withLog) {
            $this->logEntry($order, 'Cart', $status, $enteredAt);
        }

        DBDatetime::clear_mock_now();

        return $order;
    }

    private function logEntry(Order $order, string $from, string $to, string $at): void
    {
        DBDatetime::set_mock_now($at);
        $log = OrderTransitionLog::create();
        $log->OrderID = $order->ID;
        $log->FromStatus = $from;
        $log->ToStatus = $to;
        $log->write();
        DBDatetime::clear_mock_now();
    }

    public function testRulesDropInvalidAndDefaultTransitionName(): void
    {
        Config::modify()->set(ScheduledTransitionService::class, 'scheduled_transitions', [
            'cancel_unpaid' => ['from' => 'Unpaid', 'to' => 'AdminCancelled', 'after' => '+14 days'],
            'with_name' => ['from' => 'Sent', 'to' => 'Complete', 'after' => '+7 days', 'transition' => 'complete'],
            'bad_place' => ['from' => 'Nope', 'to' => 'Complete', 'after' => '+1 day'],
            'same_from_to' => ['from' => 'Paid', 'to' => 'Paid', 'after' => '+1 day'],
            'bad_after' => ['from' => 'Paid', 'to' => 'Processing', 'after' => 'nonsense'],
        ]);

        $rules = $this->service()->rules();

        $this->assertSame(['cancel_unpaid', 'with_name'], array_keys($rules), 'only valid rules survive');
        $this->assertSame('cancel_unpaid', $rules['cancel_unpaid']['transition'], 'transition defaults to the rule key');
        $this->assertSame('complete', $rules['with_name']['transition'], 'explicit transition name is kept');
    }

    public function testDueDetectionUsesLatestEntryIntoFrom(): void
    {
        $rule = ['from' => 'Unpaid', 'to' => 'AdminCancelled', 'after' => '+14 days', 'transition' => 'admin_cancel'];

        $overdue = $this->orderInStatusSince('Unpaid', date('Y-m-d H:i:s', strtotime('-20 days')));

        // Created long ago, but it most recently RE-entered Unpaid only yesterday -> not yet due.
        $reentered = $this->orderInStatusSince('Unpaid', date('Y-m-d H:i:s', strtotime('-20 days')));
        $this->logEntry($reentered, 'Paid', 'Unpaid', date('Y-m-d H:i:s', strtotime('-1 days')));

        $dueIds = array_map(static fn (Order $o): int => (int) $o->ID, $this->service()->dueOrders($rule));

        $this->assertContains((int) $overdue->ID, $dueIds, 'in Unpaid for 20 days -> due');
        $this->assertNotContains((int) $reentered->ID, $dueIds, 're-entered Unpaid yesterday -> not due');
    }

    public function testDueDetectionFallsBackToCreatedWithoutLog(): void
    {
        $rule = ['from' => 'Unpaid', 'to' => 'AdminCancelled', 'after' => '+14 days', 'transition' => 'admin_cancel'];

        $old = $this->orderInStatusSince('Unpaid', date('Y-m-d H:i:s', strtotime('-20 days')), false);
        $fresh = $this->orderInStatusSince('Unpaid', date('Y-m-d H:i:s', strtotime('-2 days')), false);

        $dueIds = array_map(static fn (Order $o): int => (int) $o->ID, $this->service()->dueOrders($rule));

        $this->assertContains((int) $old->ID, $dueIds, 'no log -> falls back to Created (20 days) -> due');
        $this->assertNotContains((int) $fresh->ID, $dueIds, 'created 2 days ago -> not due');
    }

    public function testDormantSweepFiresNothing(): void
    {
        $service = $this->service();
        if (!$service->isDormant()) {
            $this->markTestSkipped('core setStatus() funnel present — dormancy does not apply here.');
        }

        Config::modify()->set(ScheduledTransitionService::class, 'scheduled_transitions', [
            'cancel_unpaid' => ['from' => 'Unpaid', 'to' => 'AdminCancelled', 'after' => '+14 days', 'transition' => 'admin_cancel'],
        ]);
        $order = $this->orderInStatusSince('Unpaid', date('Y-m-d H:i:s', strtotime('-20 days')));

        $result = $service->sweep(true);

        $this->assertTrue($result['dormant']);
        $this->assertSame(0, $result['transitioned'], 'dormant mode never fires');
        $this->assertSame('Unpaid', (string) Order::get()->byID($order->ID)->Status, 'status untouched');
    }

    public function testFiresTransitionThroughSetStatus(): void
    {
        $service = $this->service();
        if ($service->isDormant()) {
            $this->markTestSkipped('Requires the silvershop/core Order::setStatus() funnel (#928, unreleased).');
        }

        Config::modify()->set(ScheduledTransitionService::class, 'scheduled_transitions', [
            'cancel_unpaid' => ['from' => 'Unpaid', 'to' => 'AdminCancelled', 'after' => '+14 days', 'transition' => 'admin_cancel'],
        ]);
        $order = $this->orderInStatusSince('Unpaid', date('Y-m-d H:i:s', strtotime('-20 days')));

        $result = $service->sweep(true);

        $this->assertFalse($result['dormant']);
        $this->assertSame(1, $result['transitioned']);
        $this->assertSame('AdminCancelled', (string) Order::get()->byID($order->ID)->Status, 'order was cancelled');

        $log = OrderTransitionLog::get()
            ->filter(['OrderID' => $order->ID, 'ToStatus' => 'AdminCancelled'])
            ->first();
        $this->assertNotNull($log, 'the auto-transition was logged');
        $this->assertSame('admin_cancel', $log->TransitionName, 'logged with the configured transition name');
    }
}
