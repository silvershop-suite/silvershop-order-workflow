<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Tests;

use SilverShop\Model\Order;
use SilverShop\OrderWorkflow\Workflow\OrderWorkflowService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Role/permission-gated transitions: the optional per-transition `permission` code, how it narrows
 * permittedTransitions() per member, and how it feeds the strict-mode veto via permitsChange().
 */
class OrderWorkflowPermissionTest extends SapphireTest
{
    protected $usesDatabase = true;

    private const PERM = 'SHOP_CANCEL_ORDERS';

    protected function setUp(): void
    {
        parent::setUp();

        // A focused graph: process + send ungated, admin_cancel gated behind PERM.
        Config::modify()->set(OrderWorkflowService::class, 'transitions', [
            'process' => ['from' => 'Paid', 'to' => 'Processing'],
            'send' => ['from' => ['Paid', 'Processing'], 'to' => 'Sent'],
            'admin_cancel' => ['from' => ['Unpaid', 'Paid', 'Processing'], 'to' => 'AdminCancelled', 'permission' => self::PERM],
        ]);
    }

    private function service(): OrderWorkflowService
    {
        // Fresh instance so the state machine is rebuilt from this test's transition config.
        return OrderWorkflowService::create();
    }

    private function paidOrder(): Order
    {
        $order = Order::create();
        $order->setField('Status', 'Paid');
        $order->write();

        return $order;
    }

    public function testPermissionForReadsConfig(): void
    {
        $service = $this->service();

        $this->assertSame(self::PERM, $service->permissionFor('admin_cancel'), 'gated transition reports its code');
        $this->assertNull($service->permissionFor('process'), 'ungated transition reports null');
        $this->assertNull($service->permissionFor('no_such_transition'), 'unknown transition reports null');
    }

    public function testEnabledTransitionsCarryPermission(): void
    {
        $byName = [];
        foreach ($this->service()->enabledTransitions($this->paidOrder()) as $transition) {
            $byName[$transition['name']] = $transition['permission'];
        }

        $this->assertSame(self::PERM, $byName['admin_cancel'] ?? 'missing', 'admin_cancel carries its permission');
        $this->assertArrayHasKey('process', $byName);
        $this->assertNull($byName['process'], 'process is ungated');
    }

    public function testPermittedTransitionsFilterByMember(): void
    {
        $service = $this->service();
        $order = $this->paidOrder();

        $privileged = $this->createMemberWithPermission(self::PERM);
        $plain = Member::create();
        $plain->write();

        $allowedNames = static fn (array $list): array => array_column($list, 'name');

        $forPrivileged = $allowedNames($service->permittedTransitions($order, $privileged));
        $forPlain = $allowedNames($service->permittedTransitions($order, $plain));

        $this->assertContains('admin_cancel', $forPrivileged, 'holder of the permission sees the gated action');
        $this->assertNotContains('admin_cancel', $forPlain, 'member without it does not');
        $this->assertContains('process', $forPlain, 'ungated actions remain visible to everyone');
    }

    public function testPermitsChangeRespectsPermission(): void
    {
        $service = $this->service();
        $order = $this->paidOrder();

        $privileged = $this->createMemberWithPermission(self::PERM);
        $plain = Member::create();
        $plain->write();

        $this->assertTrue(
            $service->permitsChange($order, 'Paid', 'AdminCancelled', 'admin_cancel', $privileged),
            'privileged member may fire the gated transition'
        );
        $this->assertFalse(
            $service->permitsChange($order, 'Paid', 'AdminCancelled', 'admin_cancel', $plain),
            'unprivileged member is blocked'
        );
        $this->assertTrue(
            $service->permitsChange($order, 'Paid', 'Processing', 'process', $plain),
            'ungated transition is permitted for anyone'
        );
        $this->assertTrue(
            $service->permitsChange($order, 'Paid', 'Cart', 'reopen', $plain),
            'an off-graph change is not blocked on permission grounds (the graph check owns that)'
        );
    }
}
