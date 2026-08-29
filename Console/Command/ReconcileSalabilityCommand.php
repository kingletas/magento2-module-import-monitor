<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Console\Command;

use Commerce\ImportMonitor\Model\Salability\Discrepancy;
use Commerce\ImportMonitor\Model\Salability\Reconciler;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Compares a supplier feed against the catalogue.
 */
class ReconcileSalabilityCommand extends Command
{
    private const string OPTION_FILE = 'file';
    private const string OPTION_WEBSITE = 'website-id';
    private const string OPTION_LIMIT = 'limit';

    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Report SKUs the supplier can sell but Magento cannot.')
            ->addOption(self::OPTION_FILE, 'f', InputOption::VALUE_REQUIRED, 'Path to the feed file.')
            ->addOption(self::OPTION_WEBSITE, 'w', InputOption::VALUE_REQUIRED, 'Website id to check stock against.')
            ->addOption(
                self::OPTION_LIMIT,
                'l',
                InputOption::VALUE_REQUIRED,
                'Print at most this many findings.',
                '50'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption(self::OPTION_FILE);

        if ($file === '') {
            $output->writeln('<error>--file is required.</error>');

            return Command::INVALID;
        }

        $this->ensureArea();

        $limit = max(0, (int) $input->getOption(self::OPTION_LIMIT));
        $printed = 0;
        $websiteId = $input->getOption(self::OPTION_WEBSITE);

        try {
            $result = $this->reconciler->reconcile(
                $file,
                $websiteId === null ? null : (int) $websiteId,
                function (Discrepancy $discrepancy) use ($output, $limit, &$printed): void {
                    // Streamed as they are found, so a long run shows progress
                    // rather than sitting silent until the end.
                    if ($limit === 0 || $printed < $limit) {
                        $output->writeln(sprintf('  %-14s %s', $discrepancy->reason->value, $discrepancy->message));
                        $printed++;
                    }
                }
            );
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Reconciliation failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($limit > 0 && $result->getDiscrepancyCount() > $printed) {
            // Never silently truncate: say how many were withheld.
            $output->writeln(sprintf(
                '  … and %d more (raise --limit to see them).',
                $result->getDiscrepancyCount() - $printed
            ));
        }

        $output->writeln('');

        // Three outcomes, three exit codes.
        if ($result->isInconclusive()) {
            $output->writeln(sprintf('<comment>%s</comment>', $result->summarise()));

            return Command::FAILURE;
        }

        $output->writeln($result->summarise());

        return $result->isClean() ? Command::SUCCESS : Command::FAILURE;
    }

    private function ensureArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (Throwable) {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        }
    }
}
