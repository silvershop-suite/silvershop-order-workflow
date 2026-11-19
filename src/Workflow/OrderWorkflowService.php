<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Workflow;

use SilverShop\Model\Order;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
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
     * Core's own structured audit record (silvershop/core 6.3+, written by Order when
     * `audit_status_transitions` is on). Referenced by name so this module still loads
     * and analyses against a core that predates it.
     */
    public const CORE_AUDIT_CLASS = 'SilverShop\\Model\\OrderTransition';

    /**
     * When true, every persisted status change is recorded as an immutable
     * {@link \SilverShop\OrderWorkflow\Model\OrderTransitionLog} — but only while
     * core is NOT already recording it (see {@link coreAuditsTransitions()}). On
     * silvershop/core 6.3+ core's `OrderTransition` is the audit trail and the
     * module's log stays silent, so a transition is never recorded twice.
     */
    private static bool $log_transitions = true;

    /**
     * Transition table: name => ['from' => string|array<string>, 'to' => string].
     *
     * @var array<string,array{from:string|array<string>,to:string}>
     */
    private static array $transitions = [];

    private ?StateMachine $machine = null;

    /**
     * Is core itself recording every status transition? True on silvershop/core 6.3+
     * with `Order.audit_status_transitions` left on (the default). When it is, core's
     * `OrderTransition` is the single source of truth and the module does not write its
     * own {@link \SilverShop\OrderWorkflow\Model\OrderTransitionLog}.
     */
    public static function coreAuditsTransitions(): bool
    {
        return class_exists(self::CORE_AUDIT_CLASS)
            && (bool) Order::config()->get('audit_status_transitions');
    }

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

    /**
     * The transitions enabled from an order's current Status — the single declarative source a UI renders the
     * allowed next steps from (e.g. order-admin's status buttons), read straight from the config graph via the
     * state machine. Each entry: ['name' => <transition>, 'from' => <current place>, 'to' => <target place>].
     *
     * Empty when the order's Status is unknown/empty or the graph defines no outgoing transition from it. In
     * strict mode these are the ONLY status changes the veto permits; in advisory mode they are the graph's
     * suggested next steps (an off-graph change still goes through, just logged as off-graph).
     *
     * Each entry carries the transition's optional `permission` code (null when ungated); this is the full
     * graph-enabled set regardless of who is viewing. For the subset the current member may actually fire, use
     * {@link permittedTransitions()}.
     *
     * @return array<int,array{name:string,from:string,to:string,permission:string|null}>
     */
    public function enabledTransitions(Order $order): array
    {
        $from = (string) $order->Status;
        if ($from === '' || !in_array($from, $this->getPlaces(), true)) {
            return [];
        }

        $out = [];
        foreach ($this->getStateMachine()->getEnabledTransitions($order) as $transition) {
            $name = $transition->getName();
            foreach ($transition->getTos() as $to) {
                $out[] = [
                    'name' => $name,
                    'from' => $from,
                    'to' => (string) $to,
                    'permission' => $this->permissionFor($name),
                ];
            }
        }

        return $out;
    }

    /**
     * The transitions from an order's current Status that the given member (default: the current user) is actually
     * allowed to fire — {@link enabledTransitions()} minus any whose `permission` the member lacks. This is what a UI
     * should render status buttons from, so a member never sees an action they cannot perform.
     *
     * @return array<int,array{name:string,from:string,to:string,permission:string|null}>
     */
    public function permittedTransitions(Order $order, ?Member $member = null): array
    {
        $member ??= Security::getCurrentUser();

        return array_values(array_filter(
            $this->enabledTransitions($order),
            fn (array $transition): bool => $this->memberMayFire($transition['permission'], $member)
        ));
    }

    /**
     * Whether $member may fire the change $from -> $to (optional intent $name), taking BOTH the graph and per-transition
     * permissions into account. Used by the strict-mode veto. An off-graph change (no matching enabled transition) is
     * not blocked on permission grounds here — the graph check in {@link allows()} owns that decision. When several
     * transitions reach $to, the member needs to satisfy only one of them (the most permissive route wins).
     */
    public function permitsChange(Order $order, string $from, string $to, ?string $name = null, ?Member $member = null): bool
    {
        $member ??= Security::getCurrentUser();

        $matching = array_filter(
            $this->enabledTransitions($order),
            function (array $transition) use ($to, $name): bool {
                if ($transition['to'] !== $to) {
                    return false;
                }

                return $name === null || $name === '' || $transition['name'] === $name;
            }
        );

        if ($matching === []) {
            return true;
        }

        foreach ($matching as $transition) {
            if ($this->memberMayFire($transition['permission'], $member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The permission code gating a transition by name, or null when it is ungated. Read from the transition's optional
     * `permission` config key.
     */
    public function permissionFor(string $name): ?string
    {
        $transitions = (array) static::config()->get('transitions');
        $def = $transitions[$name] ?? null;
        if (!is_array($def)) {
            return null;
        }

        $permission = $def['permission'] ?? null;

        return is_string($permission) && $permission !== '' ? $permission : null;
    }

    /**
     * Whether a member satisfies a transition's permission requirement. An ungated transition ($permission null) is
     * always firable; a gated one needs the code (an unknown/typo'd code therefore fails closed — nobody but admins).
     */
    private function memberMayFire(?string $permission, ?Member $member): bool
    {
        if ($permission === null) {
            return true;
        }

        return Permission::check($permission, 'any', $member) !== false;
    }

    private function buildDefinition(): Definition
    {
        $transitions = [];
        foreach (static::config()->get('transitions') as $name => $def) {
            // A StateMachine carries a single marking, so a Transition with several `from`
            // places would need the order to occupy all of them at once (Petri-net AND) and
            // could never fire. Expand each `from` into its own same-named Transition; the
            // StateMachine resolves same-named transitions by the one whose `from` matches.
            foreach ((array) $def['from'] as $from) {
                $transitions[] = new Transition((string) $name, (string) $from, (string) $def['to']);
            }
        }

        return new Definition($this->getPlaces(), $transitions);
    }
}
