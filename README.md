# SilverShop Order Workflow

Formalises the SilverShop order lifecycle as a [Symfony Workflow](https://symfony.com/doc/current/workflow.html)
**state machine** over the existing `Order.Status` enum — no new status storage, no change to the eight
core statuses.

- **Advisory by default.** It observes every status transition, and makes sure each one is recorded exactly
  once as an immutable audit record (from → to, the transition *name*/intent, the acting member, the
  timestamp). This is the audit trail that feeds `silvershop-suite/ledger`. See [Audit trail](#audit-trail)
  for which table holds it.
- **Opt-in strict mode.** Set `strict: true` and the module enforces the configured transition graph:
  an illegal transition is **vetoed before the write** (via core's `onBeforeStatusChange` hook). The free
  CMS status dropdown and the core migration tasks are intentionally constrained by strict mode, so it is
  off unless you ask for it.

It reuses `Order.Status` as the Symfony Workflow marking (a ~20-line `MarkingStoreInterface`); places are
derived from the status enum at runtime, transitions come from config.

## Requirements

- `silverstripe/framework` ^6, `silvershop/core` ^6.3 (the first release with the `Order::setStatus()` funnel /
  `onBeforeStatusChange` hook)
- `symfony/workflow` ^7.4
- `symbiote/silverstripe-queuedjobs` ^6 (drives the recurring sweep for [Scheduled transitions](#scheduled-transitions);
  the rest of the module works without a job worker)

## Configuration

```yaml
SilverShop\OrderWorkflow\Workflow\OrderWorkflowService:
  strict: false          # true = enforce the graph (veto illegal transitions)
  log_transitions: true  # write an OrderTransitionLog per transition (only while core is not auditing, see below)
  transitions:
    process:
      from: 'Paid'
      to: 'Processing'
    # ...
```

## Audit trail

Every transition is recorded **once**, never twice:

| Core | Records each transition in |
| --- | --- |
| `silvershop/core` 6.3+ with `Order.audit_status_transitions: true` (core's default) | core's own `SilverShop\Model\OrderTransition` (`$order->StatusTransitions()`). The module writes nothing. |
| `silvershop/core` 6.3+ with `audit_status_transitions: false` | the module's `OrderTransitionLog`, if `log_transitions` is on |
| `silvershop/core` older than 6.3 (no `OrderTransition`) | the module's `OrderTransitionLog`, if `log_transitions` is on |

Both tables have the same columns. To record nothing at all (for example around a bulk status migration), turn off
both `Order.audit_status_transitions` and `log_transitions`. Existing `OrderTransitionLog` rows from before an upgrade
to core 6.3 are kept and still count for [scheduled-transition timing](#scheduled-transitions).

## Reading the graph

Expose the graph's valid next steps for an order — the single declarative source a UI (e.g. a CMS
status-button row) renders from, instead of hard-coding which status follows which:

```php
$order->enabledTransitions();                                            // via the Order extension
OrderWorkflowService::singleton()->enabledTransitions($order);           // or the service directly
```

Returns the transitions enabled from the order's current `Status`, each as
`['name' => <transition>, 'from' => <current status>, 'to' => <target status>, 'permission' => <code|null>]` — e.g.
from `Paid`: `process → Processing`, `send → Sent`, `admin_cancel`, `member_cancel`. Empty for a terminal or unknown
status. In strict mode these are the only changes the veto permits; in advisory mode they are the graph's
suggested next steps (an off-graph change still goes through, just logged as off-graph).

`enabledTransitions()` is the full graph-enabled set regardless of viewer. To render buttons for the **current
member**, use [`permittedTransitions()`](#permission-gated-transitions), which drops any the member lacks the
permission for.

## Scheduled transitions

Move orders on **automatically** once they have sat in a status for a configured length of time — the lifecycle jobs
every shop needs:

- **auto-cancel** an order left `Unpaid` for N days (release stock, stop chasing it),
- **auto-complete** a `Sent` order after the return window,
- **dunning** / staged follow-ups, or any "after N days in status X, go to Y".

**Why route it through the module instead of a bespoke cron script?** Each scheduled move is fired through core's
`Order::setStatus()` — exactly like a human changing the status in the CMS — so everything downstream lights up for
free and stays consistent: the immutable [audit record](#audit-trail) is written, `silvershop-order-notifications` sends
the customer the matching status email, and `silvershop-suite/ledger` posts. A hand-rolled `UPDATE Order SET Status`
would silently skip all three. And because each rule names a real transition in the graph above, **strict mode still
guards it** — a scheduled move that is not a legal edge is vetoed just like a manual one.

### Configure the rules

Rules are opt-in — none are defined by default. Add them under the service:

```yaml
SilverShop\OrderWorkflow\Service\ScheduledTransitionService:
  scheduled_transitions:
    cancel_unpaid:
      from: 'Unpaid'            # the status the order must currently be in
      to: 'AdminCancelled'      # where to move it
      transition: 'admin_cancel' # transition name recorded on the log (optional; defaults to the rule key)
      after: '+14 days'         # how long it must have been in `from` (any strtotime duration: '+48 hours', '+2 weeks', …)
    auto_complete:
      from: 'Sent'
      to: 'Complete'
      transition: 'complete'
      after: '+7 days'
```

Keep each `from → to` a real edge in `$transitions` (above) so strict mode permits it; in advisory mode any pair fires
(and is logged as off-graph). Invalid rules — an unknown status, `from === to`, an unparseable `after` — are skipped
rather than fatal.

**How "time in status" is measured:** from the most recent transition *into* `from`, read from the
[audit trail](#audit-trail): the newest entry across core's `OrderTransition` and the module's `OrderTransitionLog`
(so a re-opened order is timed from when it re-entered the status, not from when it was created, and a site that
upgraded core mid-life keeps its older entries). With no record at all (logging disabled, or a pre-funnel order) it
falls back to the order's creation time, so leave the audit trail on (the default) for accurate timing.

### Run it

Two interchangeable ways — pick whichever your hosting uses:

**A recurring queued job** (needs a running `queuedjobs` worker). Arm it once; it re-queues itself every
`scan_interval_mins` (default 60) and shows up in *QueuedJobAdmin*:

```bash
# one-off, to start the recurring sweep
vendor/bin/sake tasks:SilverShop-OrderWorkflow-Task-ScheduledTransitionsTask --schedule
```

**A plain cron task** (no worker needed) — the same sweep on demand. Dry-run by default (lists each rule and how many
orders are due); `--run` actually fires them:

```bash
# see what WOULD happen
vendor/bin/sake tasks:SilverShop-OrderWorkflow-Task-ScheduledTransitionsTask
# fire the due transitions now (e.g. from a nightly cron)
vendor/bin/sake tasks:SilverShop-OrderWorkflow-Task-ScheduledTransitionsTask --run
```

### Tuning & safety

```yaml
SilverShop\OrderWorkflow\Service\ScheduledTransitionService:
  batch_size: 100          # max orders advanced per rule per sweep (most-overdue first; the rest wait for the next run)
SilverShop\OrderWorkflow\Job\ScheduledTransitionsJob:
  scan_interval_mins: 60   # minutes between recurring sweeps
```

The sweep is **idempotent** — it only ever touches orders *still* in `from`, so re-running it (or running the job and
the cron task together) can never double-fire. Like the rest of the module it is **dormant** until core's
`setStatus()` funnel (#928) ships: without it the sweep fires nothing and the task says so, rather than writing a raw
status that would bypass the log, notifications and ledger.

## Permission-gated transitions

Restrict individual transitions to members who hold a given permission — e.g. only staff with
`SHOP_CANCEL_ORDERS` may cancel a paid order, while anyone handling fulfilment can mark it `Sent`. The gate is
honoured in **both** directions, from one declarative source:

- **UI** — `permittedTransitions()` hides a gated action from members who lack the code, so they never see a button
  they cannot use.
- **Enforcement** — in strict mode the `onBeforeStatusChange` veto blocks a gated change by a member without the code,
  so the rule holds even against a hand-crafted request or the free CMS status dropdown.

### Configure

Add an optional `permission` to any transition in the graph (ungated by default):

```yaml
SilverShop\OrderWorkflow\Workflow\OrderWorkflowService:
  transitions:
    admin_cancel:
      from: ['Unpaid', 'Paid', 'Processing']
      to: 'AdminCancelled'
      permission: 'SHOP_CANCEL_ORDERS'   # only holders of this code may fire it
```

The code is any SilverStripe permission (a core one such as `ADMIN`, or your own registered via
`Permission::declare_permissions()`). It **fails closed**: an unknown or mistyped code gates the transition to
administrators only, never opens it up.

### Use

```php
// Render status buttons for whoever is logged in (defaults to the current user):
foreach ($order->permittedTransitions() as $t) {
    // $t = ['name' => ..., 'from' => ..., 'to' => ..., 'permission' => 'SHOP_CANCEL_ORDERS'|null]
    // draw a button that fires $t['name'] / moves to $t['to']
}

// Or check a specific member / a specific change:
OrderWorkflowService::singleton()->permittedTransitions($order, $member);
OrderWorkflowService::singleton()->permitsChange($order, 'Paid', 'AdminCancelled', 'admin_cancel', $member);
```

`permitsChange()` answers "may this member make this change?" taking both the graph and the permission into account;
when several transitions reach the same target the member needs satisfy only one of them. An **off-graph** change is
not blocked here on permission grounds — the graph check (strict mode) owns that decision.

> Permissions describe *who may* fire a transition; they do not replace strict mode (*which* transitions are legal).
> Gating only narrows the veto further, so it is most useful with `strict: true`. In advisory mode the gate still
> filters `permittedTransitions()` for the UI, but nothing is enforced on write.

## Licence

BSD-3-Clause © 2026 Remy Vaartjes.

## Credits

Developed by Remy Vaartjes, with the SilverShop-Suite Contributors.
