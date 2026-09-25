<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\UserPreferencesInterface;

final class UserPreferences implements UserPreferencesInterface
{
    public function __construct(
        private readonly string $userId,
        private readonly ?string $displayName = null,
        private readonly ?string $loyaltyTier = null,
        private readonly ?string $defaultLocation = null,
        private readonly array $preferences = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            userId: (string)($data['user_id'] ?? ''),
            displayName: isset($data['display_name']) ? (string)$data['display_name'] : null,
            loyaltyTier: isset($data['loyalty_tier']) ? (string)$data['loyalty_tier'] : null,
            defaultLocation: isset($data['default_location']) ? (string)$data['default_location'] : null,
            preferences: is_array($data['preferences'] ?? null) ? $data['preferences'] : []
        );
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getLoyaltyTier(): ?string
    {
        return $this->loyaltyTier;
    }

    public function getDefaultLocation(): ?string
    {
        return $this->defaultLocation;
    }

    public function getPreferences(): array
    {
        return $this->preferences;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'loyalty_tier' => $this->loyaltyTier,
            'default_location' => $this->defaultLocation,
            'preferences' => $this->preferences,
        ];
    }
}
