<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\PolicyInterface;

final class Policy implements PolicyInterface
{
    public function __construct(
        private readonly string $policyId,
        private readonly string $title,
        private readonly string $content,
        private readonly ?string $category = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            policyId: (string)($data['policy_id'] ?? ''),
            title: (string)($data['title'] ?? ''),
            content: (string)($data['content'] ?? ''),
            category: isset($data['category']) ? (string)$data['category'] : null
        );
    }

    public function getPolicyId(): string
    {
        return $this->policyId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'title' => $this->title,
            'category' => $this->category,
            'content' => $this->content,
        ];
    }
}
