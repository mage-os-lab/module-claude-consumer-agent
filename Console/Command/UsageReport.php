<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not final: Magento generates an interceptor for console commands.
 */
class UsageReport extends Command
{
    private const OPTION_DAYS = 'days';
    private const OPTION_STORE = 'store';
    private const TABLE = 'aiagent_turn';

    public function __construct(
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('aiagent:usage:report')
            ->setDescription('Prints per-day and total token usage recorded in aiagent_turn.')
            ->addOption(
                self::OPTION_DAYS,
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days to include.',
                '30'
            )
            ->addOption(
                self::OPTION_STORE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Restrict to this store id.'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int)$input->getOption(self::OPTION_DAYS);
        $storeOption = $input->getOption(self::OPTION_STORE);
        $storeId = $storeOption !== null ? (int)$storeOption : null;
        $since = new \DateTimeImmutable(sprintf('-%d days', $days));

        $this->renderDaily($output, $this->fetchDaily($since, $storeId));
        $this->renderTotals($output, $this->fetchTotals($since, $storeId));

        return Cli::RETURN_SUCCESS;
    }

    private function fetchDaily(\DateTimeImmutable $since, ?int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'day' => 'DATE(created_at)',
                    'turns' => 'COUNT(*)',
                    'input_tokens' => 'SUM(input_tokens)',
                    'output_tokens' => 'SUM(output_tokens)',
                    'cache_creation_input_tokens' => 'SUM(cache_creation_input_tokens)',
                    'cache_read_input_tokens' => 'SUM(cache_read_input_tokens)',
                ]
            )
            ->where('created_at >= ?', $since->format('Y-m-d H:i:s'))
            ->group('DATE(created_at)')
            ->order('day ASC');
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }
        return $connection->fetchAll($select);
    }

    private function fetchTotals(\DateTimeImmutable $since, ?int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'turns' => 'COUNT(*)',
                    'sessions' => 'COUNT(DISTINCT session_id)',
                    'input_tokens' => 'SUM(input_tokens)',
                    'output_tokens' => 'SUM(output_tokens)',
                    'cache_creation_input_tokens' => 'SUM(cache_creation_input_tokens)',
                    'cache_read_input_tokens' => 'SUM(cache_read_input_tokens)',
                ]
            )
            ->where('created_at >= ?', $since->format('Y-m-d H:i:s'));
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }
        $row = $connection->fetchRow($select);
        return $row !== false ? $row : [];
    }

    private function renderDaily(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['day', 'turns', 'input', 'output', 'cache write', 'cache read']);
        foreach ($rows as $row) {
            $table->addRow([
                (string)($row['day'] ?? ''),
                (string)($row['turns'] ?? 0),
                (string)($row['input_tokens'] ?? 0),
                (string)($row['output_tokens'] ?? 0),
                (string)($row['cache_creation_input_tokens'] ?? 0),
                (string)($row['cache_read_input_tokens'] ?? 0),
            ]);
        }
        $table->render();
    }

    private function renderTotals(OutputInterface $output, array $totals): void
    {
        $turns = (int)($totals['turns'] ?? 0);
        $sessions = (int)($totals['sessions'] ?? 0);
        $inputTokens = (int)($totals['input_tokens'] ?? 0);
        $outputTokens = (int)($totals['output_tokens'] ?? 0);
        $cacheWriteTokens = (int)($totals['cache_creation_input_tokens'] ?? 0);
        $cacheReadTokens = (int)($totals['cache_read_input_tokens'] ?? 0);
        $totalTokens = $inputTokens + $outputTokens + $cacheWriteTokens + $cacheReadTokens;
        $avgPerTurn = $turns > 0 ? $totalTokens / $turns : 0.0;
        $avgPerSession = $sessions > 0 ? $totalTokens / $sessions : 0.0;

        $table = new Table($output);
        $table->setHeaders([
            'turns',
            'sessions',
            'input',
            'output',
            'cache write',
            'cache read',
            'avg tokens/turn',
            'avg tokens/session',
        ]);
        $table->addRow([
            (string)$turns,
            (string)$sessions,
            (string)$inputTokens,
            (string)$outputTokens,
            (string)$cacheWriteTokens,
            (string)$cacheReadTokens,
            sprintf('%.2f', $avgPerTurn),
            sprintf('%.2f', $avgPerSession),
        ]);
        $table->render();
    }
}
