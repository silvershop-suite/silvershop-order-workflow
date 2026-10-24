<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Model;

use SilverShop\Model\Order;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Immutable, append-only record of a single order status transition: from, to,
 * the transition name (intent, when core supplied one), the acting member and
 * the timestamp. This is the audit trail that formalises issue #4 and can feed
 * silvershop-suite/ledger. Never edited or deleted through the ORM.
 *
 * @property string $FromStatus
 * @property string $ToStatus
 * @property string $TransitionName
 * @method Order Order()
 * @method Member Member()
 */
class OrderTransitionLog extends DataObject
{
    private static string $table_name = 'SilverShop_OrderTransitionLog';

    private static array $db = [
        'FromStatus' => 'Varchar(20)',
        'ToStatus' => 'Varchar(20)',
        'TransitionName' => 'Varchar(50)',
    ];

    private static array $has_one = [
        'Order' => Order::class,
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'OrderID' => true,
    ];

    private static string $default_sort = 'ID DESC';

    private static array $summary_fields = [
        'Created' => 'When',
        'FromStatus' => 'From',
        'ToStatus' => 'To',
        'TransitionName' => 'Transition',
        'Member.Title' => 'By',
    ];

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }

    public function canView($member = null): bool
    {
        return true;
    }
}
