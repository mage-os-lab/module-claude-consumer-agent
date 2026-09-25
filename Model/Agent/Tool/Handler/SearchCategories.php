<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\CategoryMatchInterface;
use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class SearchCategories implements HandlerInterface
{
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $keywords = (string)($input['keywords'] ?? '');
        $limit = max(1, min((int)($input['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $matches = $this->backend->searchCategories($context, $keywords, $limit);
        $records = array_map(
            static fn (CategoryMatchInterface $match): array => $match->toArray(),
            $matches
        );
        $text = $this->serializer->categorySearchText($keywords, $records, $config->maxFencedChars);
        return ToolOutcome::ok($text);
    }
}
