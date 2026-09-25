<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface UserPreferencesInterface
{
    public function getUserId(): string;

    public function getDisplayName(): ?string;

    public function getLoyaltyTier(): ?string;

    public function getDefaultLocation(): ?string;

    public function getPreferences(): array;

    public function toArray(): array;
}
