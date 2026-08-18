<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Notification\NotificationOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'notifications:send', description: 'Deliver pending operator notifications from the outbox (exponential retry)')]
final class NotificationsSendCommand extends Command
{
    public function __construct(private readonly NotificationOutbox $outbox)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Same 1-minute-cron + short inner loop pattern as inbox:flush, so an
        // FTD alert reaches the operator within ~10s instead of up to 60s,
        // without needing sub-minute cron resolution from supercronic.
        $total = 0;
        $deadline = time() + 50;
        do {
            $total += $this->outbox->tick(50);
            if (time() >= $deadline) {
                break;
            }
            sleep(10);
        } while (time() < $deadline);

        $output->writeln("notifications:send processed={$total}");
        return self::SUCCESS;
    }
}
