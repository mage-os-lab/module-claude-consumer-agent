<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Framework\App\Config as AppConfig;
use MageOS\AiShoppingAssistant\Api\Backend\PolicySourceInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\Policy;

final class CmsPagePolicySource implements PolicySourceInterface
{
    private const CACHE_TAG = 'AIAGENT';
    private const CACHE_TAG_POLICIES = 'cms_p';
    private const CACHE_LIFETIME = 86400;
    private const MAX_CHUNK_CHARS = 1500;
    private const MAX_RESULTS = 12;

    public function __construct(
        private readonly \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly \Magento\Framework\App\CacheInterface $cache,
        private readonly \Magento\Framework\Serialize\Serializer\Json $json,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Magento\Cms\Api\BlockRepositoryInterface $blockRepository
    ) {
    }

    public function search(SessionContext $ctx, string $query): array
    {
        $config = $this->storeConfig->agent($ctx->storeId);
        $pageIdentifiers = $config->policyPages;
        $blockIdentifiers = [];
        $pageTopics = [];
        $blockTopics = [];

        foreach ($config->storeFacts as $fact) {
            $source = is_array($fact) ? ($fact['source'] ?? null) : null;
            $value = is_array($fact) ? trim((string)($fact['value'] ?? '')) : '';
            $topic = is_array($fact) ? (string)($fact['topic'] ?? '') : '';
            if ($source === 'cms_page' && $value !== '') {
                $pageIdentifiers[] = $value;
                $pageTopics[$value] = $topic;
            }
            if ($source === 'cms_block' && $value !== '') {
                $blockIdentifiers[] = $value;
                $blockTopics[$value] = $topic;
            }
        }

        $pageIdentifiers = array_values(array_unique($pageIdentifiers));
        $blockIdentifiers = array_values(array_unique($blockIdentifiers));
        if ($pageIdentifiers === [] && $blockIdentifiers === []) {
            return [];
        }

        $passages = $this->loadPassages(
            $ctx->storeId,
            $pageIdentifiers,
            $blockIdentifiers,
            $pageTopics,
            $blockTopics
        );

        $terms = $this->tokenize($query);
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($passages as $passage) {
            $score = $this->score($passage, $terms);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'passage' => $passage];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, self::MAX_RESULTS);

        return array_map(
            static fn (array $row): Policy => Policy::fromArray($row['passage']),
            $top
        );
    }

    private function loadPassages(
        int $storeId,
        array $pageIdentifiers,
        array $blockIdentifiers,
        array $pageTopics,
        array $blockTopics
    ): array {
        $cacheKey = 'aiagent_policies_' . $storeId . '_'
            . sha1(implode(',', $pageIdentifiers) . '|' . implode(',', $blockIdentifiers));
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $decoded = $this->json->unserialize($cached);
            return is_array($decoded) ? $decoded : [];
        }

        $passages = [];
        if ($pageIdentifiers !== []) {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('identifier', $pageIdentifiers, 'in')
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [0, $storeId], 'in')
                ->create();
            foreach ($this->pageRepository->getList($criteria)->getItems() as $page) {
                $category = $pageTopics[$page->getIdentifier()] ?? null;
                foreach ($this->buildPassages(
                    (string)$page->getIdentifier(),
                    (string)$page->getTitle(),
                    (string)$page->getContent(),
                    $category
                ) as $passage) {
                    $passages[] = $passage;
                }
            }
        }

        if ($blockIdentifiers !== []) {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('identifier', $blockIdentifiers, 'in')
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [0, $storeId], 'in')
                ->create();
            foreach ($this->blockRepository->getList($criteria)->getItems() as $block) {
                $category = $blockTopics[$block->getIdentifier()] ?? null;
                foreach ($this->buildPassages(
                    (string)$block->getIdentifier(),
                    (string)$block->getTitle(),
                    (string)$block->getContent(),
                    $category
                ) as $passage) {
                    $passages[] = $passage;
                }
            }
        }

        $this->cache->save(
            $this->json->serialize($passages),
            $cacheKey,
            [self::CACHE_TAG, self::CACHE_TAG_POLICIES, AppConfig::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $passages;
    }

    private function buildPassages(string $identifier, string $title, string $content, ?string $category): array
    {
        $passages = [];
        $n = 0;
        foreach ($this->chunkByHeadings($content) as $chunk) {
            $n++;
            $passageTitle = $title;
            if ($chunk['heading'] !== '') {
                $passageTitle .= ' / ' . $chunk['heading'];
            }
            $passages[] = [
                'policy_id' => $identifier . '#' . $n,
                'title' => $passageTitle,
                'category' => $category,
                'content' => $chunk['content'],
            ];
        }
        return $passages;
    }

    private function chunkByHeadings(string $html): array
    {
        $parts = preg_split('/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $parts = [$html];
        }

        $chunks = [];
        $heading = '';
        foreach ($parts as $index => $part) {
            if ($index > 0 && $index % 2 === 1) {
                $heading = $this->cleanText($part);
                continue;
            }
            $text = $this->cleanText($part);
            if ($text === '') {
                continue;
            }
            foreach ($this->splitByLength($text, self::MAX_CHUNK_CHARS) as $piece) {
                $chunks[] = ['heading' => $heading, 'content' => $piece];
            }
        }
        return $chunks;
    }

    private function splitByLength(string $text, int $maxChars): array
    {
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $pieces = [];
        while (mb_strlen($text) > $maxChars) {
            $cut = mb_substr($text, 0, $maxChars);
            $lastSpace = mb_strrpos($cut, ' ');
            if ($lastSpace !== false && $lastSpace > 0) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
            $pieces[] = trim($cut);
            $text = trim(mb_substr($text, mb_strlen($cut)));
        }
        if ($text !== '') {
            $pieces[] = $text;
        }
        return $pieces;
    }

    private function cleanText(string $html): string
    {
        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
        $withoutStyles = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $withoutScripts) ?? $withoutScripts;
        $withoutDirectives = preg_replace('/\{\{.*?\}\}/s', ' ', $withoutStyles) ?? $withoutStyles;
        $decoded = html_entity_decode(strip_tags($withoutDirectives), ENT_QUOTES | ENT_HTML5);
        $collapsed = preg_replace('/\s+/', ' ', $decoded) ?? '';
        return trim($collapsed);
    }

    private function tokenize(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', mb_strtolower($text), $matches);
        $tokens = array_filter(
            $matches[0],
            static fn (string $token): bool => mb_strlen($token) > 2
        );
        return array_values(array_unique($tokens));
    }

    private function score(array $passage, array $terms): int
    {
        $titleTokens = $this->tokenize((string)($passage['title'] ?? ''));
        $contentTokens = $this->tokenize((string)($passage['content'] ?? ''));

        $score = 0;
        foreach ($terms as $term) {
            if (in_array($term, $titleTokens, true)) {
                $score += 2;
            }
            if (in_array($term, $contentTokens, true)) {
                $score += 1;
            }
        }
        return $score;
    }
}
