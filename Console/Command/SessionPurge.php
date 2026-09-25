<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not final: Magento generates an interceptor for console commands.
 */
class SessionPurge extends Command
{
    private const OPTION_OLDER_THAN = 'older-than';
    private const OPTION_CUSTOMER = 'customer';
    private const OPTION_ALL = 'all';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_FORCE = 'force';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session $sessionResource,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('aiagent:session:purge');
        $this->setDescription('Deletes AI agent sessions and their transcripts.');
        $this->addOption(
            self::OPTION_OLDER_THAN,
            null,
            InputOption::VALUE_REQUIRED,
            'Delete sessions last updated more than this many days ago.'
        );
        $this->addOption(
            self::OPTION_CUSTOMER,
            null,
            InputOption::VALUE_REQUIRED,
            'Delete sessions belonging to this customer id.'
        );
        $this->addOption(
            self::OPTION_ALL,
            null,
            InputOption::VALUE_NONE,
            'Delete every session.'
        );
        $this->addOption(
            self::OPTION_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'Print the count that would be deleted without deleting anything.'
        );
        $this->addOption(
            self::OPTION_FORCE,
            null,
            InputOption::VALUE_NONE,
            'Required alongside --all to confirm a full purge.'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $olderThan = $input->getOption(self::OPTION_OLDER_THAN);
        $customer = $input->getOption(self::OPTION_CUSTOMER);
        $all = (bool)$input->getOption(self::OPTION_ALL);
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $force = (bool)$input->getOption(self::OPTION_FORCE);

        $selectorCount = ($olderThan !== null ? 1 : 0) + ($customer !== null ? 1 : 0) + ($all ? 1 : 0);
        if ($selectorCount !== 1) {
            $output->writeln('<error>Specify exactly one of --older-than, --customer or --all.</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($all && !$force) {
            $output->writeln('<error>--all requires --force.</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($olderThan !== null) {
            if (!$this->isPositiveInteger($olderThan)) {
                $output->writeln('<error>--older-than must be a whole number of days greater than zero.</error>');
                return Cli::RETURN_FAILURE;
            }
            return $this->purgeOlderThan((int)$olderThan, $dryRun, $output);
        }

        if ($customer !== null) {
            if (!$this->isPositiveInteger($customer)) {
                $output->writeln('<error>--customer must be a customer id greater than zero.</error>');
                return Cli::RETURN_FAILURE;
            }
            return $this->purgeCustomer((int)$customer, $dryRun, $output);
        }

        return $this->purgeAll($dryRun, $output);
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value) && (int)$value > 0;
    }

    private function purgeOlderThan(int $days, bool $dryRun, OutputInterface $output): int
    {
        $before = new \DateTimeImmutable(sprintf('-%d days', $days));
        if ($dryRun) {
            $count = $this->sessionResource->countOlderThan($before);
            $output->writeln(sprintf('%d session(s) would be deleted.', $count));
            return Cli::RETURN_SUCCESS;
        }
        $deleted = $this->sessionResource->deleteOlderThan($before);
        $output->writeln(sprintf('Deleted %d session(s).', $deleted));
        return Cli::RETURN_SUCCESS;
    }

    private function purgeCustomer(int $customerId, bool $dryRun, OutputInterface $output): int
    {
        if ($dryRun) {
            $count = $this->sessionResource->countByCustomer($customerId);
            $output->writeln(sprintf('%d session(s) would be deleted.', $count));
            return Cli::RETURN_SUCCESS;
        }
        $deleted = $this->sessionResource->deleteByCustomer($customerId);
        $output->writeln(sprintf('Deleted %d session(s).', $deleted));
        return Cli::RETURN_SUCCESS;
    }

    private function purgeAll(bool $dryRun, OutputInterface $output): int
    {
        if ($dryRun) {
            $count = $this->sessionResource->countAll();
            $output->writeln(sprintf('%d session(s) would be deleted.', $count));
            return Cli::RETURN_SUCCESS;
        }
        $deleted = $this->sessionResource->deleteAll();
        $output->writeln(sprintf('Deleted %d session(s).', $deleted));
        return Cli::RETURN_SUCCESS;
    }
}
