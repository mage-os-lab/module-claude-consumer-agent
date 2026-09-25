<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Options;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates;
use MageOS\AiShoppingAssistant\Model\Agent\Lexicon;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\Assembly;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem;
use MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry as SkillRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRoundFactory;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;

class Toolkit
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem $staticSystem,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext $dynamicContext,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\Assembly $assembly,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Lexicon $lexicon,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates $skuCandidates,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRoundFactory $streamedRoundFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator $validator,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance $provenance,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Options $options,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer $sanitizer,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry $skillRegistry,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry $presentationRegistry
    ) {
    }

    public function storeConfig(): StoreConfig
    {
        return $this->storeConfig;
    }

    public function staticSystem(): StaticSystem
    {
        return $this->staticSystem;
    }

    public function dynamicContext(): DynamicContext
    {
        return $this->dynamicContext;
    }

    public function assembly(): Assembly
    {
        return $this->assembly;
    }

    public function lexicon(): Lexicon
    {
        return $this->lexicon;
    }

    public function skuCandidates(): SkuCandidates
    {
        return $this->skuCandidates;
    }

    public function streamedRoundFactory(): StreamedRoundFactory
    {
        return $this->streamedRoundFactory;
    }

    public function validator(): Validator
    {
        return $this->validator;
    }

    public function provenance(): Provenance
    {
        return $this->provenance;
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function sanitizer(): Sanitizer
    {
        return $this->sanitizer;
    }

    public function fence(): Fence
    {
        return $this->fence;
    }

    public function serializer(): Serializer
    {
        return $this->serializer;
    }

    public function skillRegistry(): SkillRegistry
    {
        return $this->skillRegistry;
    }

    public function presentationRegistry(): PresentationRegistry
    {
        return $this->presentationRegistry;
    }
}
