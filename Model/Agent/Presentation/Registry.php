<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation;

use MageOS\AiShoppingAssistant\Api\Presentation\PresentationExtensionInterface;

final class Registry
{
    private const TEMPLATE_PREFIX = 'MageOS_AiShoppingAssistant::cards/';

    /** @var array<string, Component> */
    private array $components;

    /**
     * @param PresentationExtensionInterface[] $extensions
     */
    public function __construct(
        \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products $products,
        \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison $comparison,
        \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus $orderStatus,
        \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout $checkout,
        \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions $suggestions,
        array $extensions = []
    ) {
        $this->components = [
            'present_products' => new Component(
                'present_products',
                'products',
                self::presentProductsSchema(),
                self::presentProductsDescription(),
                $products,
                self::TEMPLATE_PREFIX . 'products.phtml'
            ),
            'present_comparison' => new Component(
                'present_comparison',
                'comparison',
                self::presentComparisonSchema(),
                self::presentComparisonDescription(),
                $comparison,
                self::TEMPLATE_PREFIX . 'comparison.phtml'
            ),
            'present_order_status' => new Component(
                'present_order_status',
                'order_status',
                self::presentOrderStatusSchema(),
                self::presentOrderStatusDescription(),
                $orderStatus,
                self::TEMPLATE_PREFIX . 'order-status.phtml'
            ),
            'checkout' => new Component(
                'checkout',
                'checkout',
                self::checkoutSchema(),
                self::checkoutDescription(),
                $checkout,
                self::TEMPLATE_PREFIX . 'checkout.phtml'
            ),
            'present_suggestions' => new Component(
                'present_suggestions',
                'suggestions',
                self::presentSuggestionsSchema(),
                self::presentSuggestionsDescription(),
                $suggestions,
                ''
            ),
        ];

        foreach ($extensions as $extension) {
            $name = $extension->getToolName();
            if (isset($this->components[$name])) {
                throw new \LogicException("Presentation component '{$name}' is already registered.");
            }
            $this->components[$name] = new Component(
                $name,
                $extension->getComponent(),
                $extension->getInputSchema(),
                $extension->getDescription(),
                static fn (array $input, EnrichmentContext $ctx): array => $extension->enrich($input, $ctx),
                $extension->getTemplate()
            );
        }
    }

    /**
     * @return array<string, Component>
     */
    public function components(): array
    {
        return $this->components;
    }

    public function templates(): array
    {
        $templates = [];
        foreach ($this->components as $component) {
            $templates[$component->template] = true;
        }
        return array_keys($templates);
    }

    public function has(string $name): bool
    {
        return isset($this->components[$name]);
    }

    private static function productIdSchema(string $description = 'product_id returned by a tool this session.'): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    private static function titleSchema(string $what): array
    {
        return ['type' => 'string', 'maxLength' => 80, 'description' => "Short heading for the {$what}."];
    }

    public static function presentProductsDescription(): string
    {
        return "Show products from this session's results as cards; the UI fills in title, "
            . 'price, and image. Layout: carousel by default, grid to scan many '
            . "options, list when order matters. Each pick's reason is the one "
            . 'judgment of yours on the card.';
    }

    public static function presentComparisonDescription(): string
    {
        return 'Compare 2-4 finalists side by side, with pros, cons, and what each is best '
            . 'for. Use it once the customer has narrowed to them or asks how they '
            . 'differ; a fresh shortlist goes through present_products. The UI adds the '
            . 'price delta; your text says what the extra money buys.';
    }

    public static function presentOrderStatusDescription(): string
    {
        return 'Show the status card for one order; the UI fills in the order data. Every '
            . 'answer about where an order stands goes through it. When several orders '
            . 'are in flight, send one card per order in the same round.';
    }

    public static function checkoutDescription(): string
    {
        return 'Stage the current cart as an order summary the customer confirms in the '
            . 'app; it places no order and charges nothing. Use only when the customer '
            . 'asks to check out.';
    }

    public static function presentSuggestionsDescription(): string
    {
        return "Give the turn its 1-4 chips; it ends the reply. Call it in the same round "
            . "as the turn's last component, without waiting for that component's "
            . 'result. Alone, after the text, only on a turn with no component (a '
            . 'terms answer, a clarifying question, a confirmed add or save).';
    }

    public static function presentProductsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => self::titleSchema('set of cards'),
                'layout' => [
                    'type' => 'string',
                    'enum' => ['carousel', 'grid', 'list'],
                    'description' => 'Card layout; carousel when omitted.',
                ],
                'picks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'description' => 'Products to show, recommended pick first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => self::productIdSchema(),
                            'reason' => [
                                'type' => 'string',
                                'maxLength' => 140,
                                'description' => 'One clause tying the pick to a stated need.',
                            ],
                            'option_values' => [
                                'type' => 'object',
                                'description' => 'Option values the customer already named, as option label to '
                                    . 'value, e.g. {"Color": "Blue"}; the card then shows only the matching variants.',
                                'additionalProperties' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['product_id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['picks'],
            'additionalProperties' => false,
        ];
    }

    public static function presentComparisonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => self::titleSchema('comparison'),
                'entries' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 4,
                    'description' => 'The finalists being compared.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => self::productIdSchema(),
                            'pros' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'maxItems' => 4,
                                'description' => 'Short advantages, from tool results.',
                            ],
                            'cons' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'maxItems' => 3,
                                'description' => 'Short drawbacks, from tool results.',
                            ],
                            'best_for' => [
                                'type' => 'string',
                                'maxLength' => 80,
                                'description' => 'Who or what this option suits best.',
                            ],
                        ],
                        'required' => ['product_id'],
                        'additionalProperties' => false,
                    ],
                ],
                'dimensions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 6,
                    'description' => 'The dimensions the customer is weighing.',
                ],
                'recommended_product_id' => self::productIdSchema('The entry you recommend.'),
            ],
            'required' => ['entries'],
            'additionalProperties' => false,
        ];
    }

    public static function presentOrderStatusSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => [
                    'type' => 'string',
                    'description' => 'Order id from get_orders or get_order_status.',
                ],
                'summary' => [
                    'type' => 'string',
                    'maxLength' => 300,
                    'description' => 'Current state and expected date, in a sentence.',
                ],
                'next_step' => [
                    'type' => 'string',
                    'maxLength' => 200,
                    'description' => 'The one concrete thing the customer can do next.',
                ],
            ],
            'required' => ['order_id', 'summary'],
            'additionalProperties' => false,
        ];
    }

    public static function checkoutSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => [
                    'type' => 'string',
                    'maxLength' => 300,
                    'description' => 'Anything the customer should check before confirming.',
                ],
                'fulfillment_method' => [
                    'type' => 'string',
                    'enum' => ['delivery', 'pickup', 'shipping'],
                    'description' => 'Method the customer chose, when they chose one.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function presentSuggestionsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => 4,
                    'description' => '1-4 chips, each a brief imperative and each a '
                        . 'different kind of step; leave out anything this turn already '
                        . 'displayed.',
                ],
            ],
            'required' => ['suggestions'],
            'additionalProperties' => false,
        ];
    }
}
