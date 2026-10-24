<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Workflow;

use SilverShop\Model\Order;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Builds and owns the Symfony Workflow {@link StateMachine} for orders. Places
 * are the Order.Status enum values (resolved at runtime); transitions come from
 * the `transitions` config. The machine is used for validation only — core still
 * drives Status via setStatus() — so we read the graph through can()/getEnabledTransitions()
 * and never call apply().
 */
class OrderWorkflowService
{
    use Injectable;
    use Configurable;

    /**
     * When true, {@link OrderWorkflowExtension} vetoes any transition the graph
     * does not define. Off by default (advisory).
     */
    private static bool $strict = false;

    /**
     * When true, every persisted status change is recorded as an immutable
     * {@link \SilverShop\OrderWorkflow\Model\OrderTransitionLog}.
     */
    private static bool $log_transitions = true;

    /**
     * Transition table: name => ['from' => string|array<string>, 'to' => string].
     *
     * @var array<string,array{from:string|array<string>,to:string}>
     */
    private static array $transitions = [];

    private ?StateMachine $machine = null;

    public function getStateMachine(): StateMachine
    {
        if (!$this->machine instanceof StateMachine) {
            $this->machine = new StateMachine(
                $this->buildDefinition(),
                new OrderStatusMarkingStore(),
                null,
                'order'
            );
        }

        return $this->machine;
    }

    /**
     * The workflow places — i.e. the Order.Status enum values.
     *
     * @return array<int,string>
     */
    public function getPlaces(): array
    {
        /** @var \SilverStripe\ORM\FieldType\DBEnum $enum */
        $enum = Order::singleton()->dbObject('Status');

        return array_values(array_filter(array_keys($enum->enumValues(false)), fn ($p) => $p !== ''));
    }

    /**
     * Is a transition from $from to $to permitted by the graph? An unknown or
     * empty origin place cannot be validated, so it is allowed (never blocks a
     * legitimate first assignment).
     */
    public function allows(Order $order, string $from, string $to): bool
    {
        if ($from === '' || !in_array($from, $this->getPlaces(), true)) {
            return true;
        }

        foreach ($this->getStateMachine()->getEnabledTransitions($order) as $transition) {
            if (in_array($to, $transition->getTos(), true)) {
                return true;
            }
        }

        return false;
    }

    private function buildDefinition(): Definition
    {
        $transitions = [];
        foreach (static::config()->get('transitions') as $name => $def) {
            $transitions[] = new Transition((string) $name, $def['from'], $def['to']);
        }

        return new Definition($this->getPlaces(), $transitions);
    }
}
