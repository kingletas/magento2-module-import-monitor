<?php
/**
 * CheckImportsCommand.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Console\Command;

use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\ImportMonitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs the import health checks from the CLI.
 */
class CheckImportsCommand extends Command
{
    private const string OPTION_ALERT = 'alert';

    public function __construct(
        private readonly ImportMonitor $monitor,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Run the import health checks and report what they find.')
            ->addOption(
                self::OPTION_ALERT,
                'a',
                InputOption::VALUE_NONE,
                'Also raise alerts and send notifications, as the cron job does.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $results = $input->getOption(self::OPTION_ALERT)
                ? $this->monitor->run()
                : $this->monitor->check();
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>The checks could not run: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($results === []) {
            $output->writeln('<comment>No checks are registered.</comment>');

            return Command::SUCCESS;
        }

        $failures = 0;

        foreach ($results as $result) {
            if ($result->isHealthy) {
                $output->writeln(sprintf('<info>  OK  </info> %s', $result->checkCode));
                continue;
            }

            $failures++;
            $output->writeln(sprintf('<error> FAIL </error> %s — %s', $result->checkCode, (string) $result->message));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '%d check(s) run, %d failing.',
            count($results),
            $failures
        ));

        // A non-zero exit lets an external scheduler notice a failing check
        // without parsing the output.
        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
