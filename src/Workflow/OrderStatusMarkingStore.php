<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Workflow;

use SilverShop\Model\Order;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Bridges Symfony Workflow's marking to the existing Order.Status enum — no new
 * storage. The current place IS the order's Status. Reads/writes go through the
 * raw field (getField/setField) so this store never re-enters core's setStatus()
 * funnel (which would recurse) and never triggers a write().
 */
class OrderStatusMarkingStore implements MarkingStoreInterface
{
    public function getMarking(object $subject): Marking
    {
        $status = $subject instanceof Order ? (string) $subject->getField('Status') : '';

        return new Marking($status !== '' ? [$status => 1] : []);
    }

    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = array_keys($marking->getPlaces());
        $place = $places[0] ?? null;

        // State machine: a subject is in exactly one place. Write it straight to
        // the field, bypassing the setStatus() funnel to avoid recursion.
        if ($place !== null && $subject instanceof Order) {
            $subject->setField('Status', $place);
        }
    }
}
