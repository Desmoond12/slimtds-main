<?php

declare(strict_types=1);

use App\Cron\Command\GoldenClickCommand;
use Symfony\Component\Console\Tester\CommandTester;

/** Resolve the command through the real DI container (autowires every dep). */
function goldenCommand(): GoldenClickCommand
{
    $container = (require dirname(__DIR__, 3) . '/config/di.php')();
    return $container->get(GoldenClickCommand::class);
}

test('golden-click: no GOLDEN_SLUG → success (DB/liveness only, routing skipped)', function (): void {
    unset($_ENV['GOLDEN_SLUG']);
    putenv('GOLDEN_SLUG');

    $tester = new CommandTester(goldenCommand());
    $code = $tester->execute([]);

    expect($code)->toBe(0);
    expect($tester->getDisplay())->toContain('routing check skipped');
});

test('golden-click: unknown GOLDEN_SLUG → failure (broken chain), no TG in test env', function (): void {
    $slug = 'nope_' . bin2hex(random_bytes(3));
    $_ENV['GOLDEN_SLUG'] = $slug;
    putenv("GOLDEN_SLUG={$slug}");

    try {
        $tester = new CommandTester(goldenCommand());
        $code = $tester->execute([]);

        expect($code)->toBe(1); // Command::FAILURE
        expect($tester->getDisplay())->toContain('not found');
    } finally {
        unset($_ENV['GOLDEN_SLUG']);
        putenv('GOLDEN_SLUG');
    }
});
