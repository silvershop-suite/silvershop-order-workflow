# SilverShop Order Workflow

Formalises the SilverShop order lifecycle as a [Symfony Workflow](https://symfony.com/doc/current/workflow.html)
**state machine** over the existing `Order.Status` enum — no new status storage, no change to the eight
core statuses.

- **Advisory by default.** It observes every status transition and records it as an immutable
  `OrderTransitionLog` (from → to, the transition *name*/intent, the acting member, the timestamp). This is
  the audit trail that feeds `silvershop-suite/ledger`.
- **Opt-in strict mode.** Set `strict: true` and the module enforces the configured transition graph:
  an illegal transition is **vetoed before the write** (via core's `onBeforeStatusChange` hook). The free
  CMS status dropdown and the core migration tasks are intentionally constrained by strict mode, so it is
  off unless you ask for it.

It reuses `Order.Status` as the Symfony Workflow marking (a ~20-line `MarkingStoreInterface`); places are
derived from the status enum at runtime, transitions come from config.

## Requirements

- `silverstripe/framework` ^6, `silvershop/core` ^6.2 (with the `Order::setStatus()` funnel /
  `onBeforeStatusChange` hook)
- `symfony/workflow` ^7.4

## Configuration

```yaml
SilverShop\OrderWorkflow\Workflow\OrderWorkflowService:
  strict: false          # true = enforce the graph (veto illegal transitions)
  log_transitions: true  # write an OrderTransitionLog per transition
  transitions:
    process:
      from: 'Paid'
      to: 'Processing'
    # ...
```

## Reading the graph

Expose the graph's valid next steps for an order — the single declarative source a UI (e.g. a CMS
status-button row) renders from, instead of hard-coding which status follows which:

```php
$order->enabledTransitions();                                            // via the Order extension
OrderWorkflowService::singleton()->enabledTransitions($order);           // or the service directly
```

Returns the transitions enabled from the order's current `Status`, each as
`['name' => <transition>, 'from' => <current status>, 'to' => <target status>]` — e.g. from `Paid`:
`process → Processing`, `send → Sent`, `admin_cancel`, `member_cancel`. Empty for a terminal or unknown
status. In strict mode these are the only changes the veto permits; in advisory mode they are the graph's
suggested next steps (an off-graph change still goes through, just logged as off-graph).

## Licence

BSD-3-Clause © 2026 Remy Vaartjes.

## Credits

Developed by Remy Vaartjes, with the SilverShop-Suite Contributors.
