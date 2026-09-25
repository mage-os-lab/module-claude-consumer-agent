<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\ProductInterface;
use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Data\SearchFilters;

final class SearchProducts implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $query = (string)($input['query'] ?? '');
        $filters = SearchFilters::fromArray(is_array($input['filters'] ?? null) ? $input['filters'] : []);
        if ($query === '' && $filters->getCategoryId() === null) {
            return ToolOutcome::error('Give a query, or filters.category_id to list a category.');
        }
        $limit = max(1, min((int)($input['limit'] ?? $config->maxSearchResults), $config->maxSearchResults));
        $products = $this->backend->searchProducts($context, $query, $filters, $limit);
        $records = array_map(static fn (ProductInterface $product): array => $product->toArray(), $products);
        $text = $this->serializer->searchResultText($query, $records, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $records);
    }
}
