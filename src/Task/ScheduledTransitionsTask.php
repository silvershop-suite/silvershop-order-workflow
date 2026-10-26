<?php

declare(strict_types=1);

namespace SilverShop\OrderWorkflow\Task;

use SilverShop\OrderWorkflow\Job\ScheduledTransitionsJob;
use SilverShop\OrderWorkflow\Service\ScheduledTransitionService;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI runner / backstop for scheduled order transitions — the same sweep the recurring {@link ScheduledTransitionsJob}
 * performs, runnable from cron without a job worker. Dry-run by default (lists each rule and how many orders are due);
 * pass --run to actually fire them inline. --schedule (re-)arms the recurring job.
 */
class ScheduledTransitionsTask extends BuildTask
{
    protected string $title = 'Fire scheduled order transitions';

    protected static string $description =
        'Auto-advance orders that have sat in a status past its configured deadline (e.g. cancel long-unpaid orders). Dry-run unless --run.';

    public function getOptions(): array
    {
        return [
            new InputOption('run', null, InputOption::VALUE_NONE, 'Actually fire the due transitions (otherwise just list them).'),
            new InputOption('schedule', null, InputOption::VALUE_NONE, 'Queue the recurring sweep job if it is not already scheduled.'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $service = ScheduledTransitionService::create();

        if ($input->getOption('schedule')) {
            ScheduledTransitionsJob::scheduleNext();
            $output->writeln('Recurring sweep job scheduled (if it was not already).');
        }

        $rules = $service->rules();
        if ($rules === []) {
            $output->writeln('No valid scheduled_transitions configured — nothing to do.');

            return Command::SUCCESS;
        }

        if ($service->isDormant()) {
            $output->writeln('NOTE: dormant — core Order::setStatus() funnel (#928) is absent, so --run would fire nothing.');
        }

        if (!$input->getOption('run')) {
            foreach ($rules as $name => $rule) {
                $output->writeln(sprintf(
                    '  %s: %s -> %s after %s — %d order(s) due.',
                    $name,
                    $rule['from'],
                    $rule['to'],
                    $rule['after'],
                    count($service->dueOrders($rule))
                ));
            }
            $output->writeln('Dry run. Pass --run to fire these transitions.');

            return Command::SUCCESS;
        }

        $result = $service->sweep(true);
        foreach ($result['rules'] as $name => $counts) {
            $output->writeln(sprintf('  %s: fired %d of %d due.', $name, $counts['transitioned'], $counts['due']));
        }
        $output->writeln(sprintf('Total transitions fired: %d.', (int) $result['transitioned']));

        return Command::SUCCESS;
    }
}
