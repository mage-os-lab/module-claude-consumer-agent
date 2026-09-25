<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool;

use MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;

/**
 * Ports shopping_agent.tools.registry.build_tools for v1: the built-in tools every
 * deployment carries, in a fixed order. Descriptions are copied word for word from the
 * reference registry because they sit in the cached prefix.
 */
final class CoreToolProvider implements ToolProviderInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\LoadSkill $loadSkill,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchProducts $searchProducts,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchCategories $searchCategories,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetProductDetails $getProductDetails,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetCart $getCart,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\AddToCart $addToCart,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\UpdateCartItem $updateCartItem,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\RemoveFromCart $removeFromCart,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetPreferences $getPreferences,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrders $getOrders,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrderStatus $getOrderStatus,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchPolicies $searchPolicies,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetFulfillmentOptions $getFulfillmentOptions,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\MemoryOff $memoryOff,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry $skills,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry $presentation
    ) {
    }

    /**
     * @return \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[]
     */
    public function getTools(AgentConfig $config): array
    {
        $tools = [
            new Definition(
                'load_skill',
                'Load the rules of the flow whose entry in the skill index the request '
                    . 'matches; they are not in your prompt. Call it in the same round as the '
                    . "flow's first read and follow them for the rest of the flow.",
                [
                    'type' => 'object',
                    'properties' => [
                        'skill_name' => [
                            'type' => 'string',
                            'enum' => $this->sortedSkillNames(),
                            'description' => 'Name of the skill as listed in the index.',
                        ],
                    ],
                    'required' => ['skill_name'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->loadSkill,
                [],
                false,
                true,
                1
            ),
            new Definition(
                'search_products',
                'Search the catalog; returns products with id, title, brand, price, rating, '
                    . 'and availability; a product with options shows its lowest in-stock price '
                    . 'and its options. Use a specific query and put stated constraints in '
                    . 'filters; category_id belongs inside filters, never at the top level. Run '
                    . 'one search per distinct item a request names.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => "What to look for, in the catalog's vocabulary. Omit it to "
                                . 'list a category\'s products; then filters.category_id is required. '
                                . 'When the customer names a specific product or brand, that name alone '
                                . 'is the query; put a feature, a use, or any other word from the '
                                . 'request in filters or a second search instead, since the extra words '
                                . 'can bury the name match.',
                        ],
                        'filters' => $this->filtersSchema(),
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => $config->maxSearchResults,
                            'description' => 'Maximum results to return.',
                        ],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
                'read',
                $this->searchProducts,
                [],
                true,
                true,
                2
            ),
            new Definition(
                'search_categories',
                'Find catalog categories by keyword at every level of the store\'s tree; '
                    . 'returns category_id, the full path, and the product count. Use it for a '
                    . 'kind of product the catalog map does not show, before saying the store '
                    . 'does not carry it, and to get a category_id for search_products.',
                [
                    'type' => 'object',
                    'properties' => [
                        'keywords' => [
                            'type' => 'string',
                            'description' => 'One to three words naming the kind of product, in the '
                                . "catalog's vocabulary.",
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 20,
                            'description' => 'Maximum matches to return.',
                        ],
                    ],
                    'required' => ['keywords'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->searchCategories,
                [],
                false,
                true,
                3
            ),
            new Definition(
                'get_product_details',
                'Full details for one product: description, specs, review highlights, and '
                    . 'for a product with options, its variants with their ids, prices, and '
                    . 'stock. Use for a question about one product, before comparing finalists '
                    . 'or choosing a variant, and for a reference shaped like a catalog id.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => $this->productId('Catalog product_id to look up, or a SKU the customer typed.'),
                    ],
                    'required' => ['product_id'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->getProductDetails,
                [],
                true,
                true,
                4
            ),
            new Definition(
                'get_cart',
                'Current cart contents with quantities and subtotal.',
                ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'read',
                $this->getCart,
                [],
                false,
                true,
                5
            ),
            new Definition(
                'add_to_cart',
                'Add a product, or the chosen variant of a product with options, by a '
                    . 'product_id a catalog or order tool returned this session; quantity '
                    . 'defaults to 1. For a product whose record lists custom_options, pass '
                    . 'every required option in options as returned by the record.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => $this->productId(),
                        'quantity' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'Units to add; omit for one.',
                        ],
                        'options' => [
                            'type' => 'object',
                            'description' => 'Custom option selections: option title to value '
                                . 'title, or to text for text options.',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['product_id'],
                    'additionalProperties' => false,
                ],
                'write',
                $this->addToCart,
                ['product_id'],
                false,
                true,
                6
            ),
            new Definition(
                'update_cart_item',
                'Set the quantity of an item that is already in the cart.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => $this->productId('product_id of a line already in the cart.'),
                        'quantity' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'New quantity for the line.',
                        ],
                    ],
                    'required' => ['product_id', 'quantity'],
                    'additionalProperties' => false,
                ],
                'write',
                $this->updateCartItem,
                ['product_id'],
                false,
                true,
                7
            ),
            new Definition(
                'remove_from_cart',
                'Remove an item from the cart.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => $this->productId('product_id of the line to remove.'),
                    ],
                    'required' => ['product_id'],
                    'additionalProperties' => false,
                ],
                'write',
                $this->removeFromCart,
                ['product_id'],
                false,
                true,
                8
            ),
            new Definition(
                'get_preferences',
                "The customer's profile and saved preferences. Usually already in the "
                    . 'Session context block; call this only when it is missing there.',
                ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'read',
                $this->getPreferences,
                [],
                false,
                true,
                9
            ),
            new Definition(
                'get_orders',
                'Recent orders with status and estimated delivery. Use for a status question '
                    . 'that names no order and for a request to buy something again.',
                [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 20,
                            'description' => 'Maximum orders to return.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'read',
                $this->getOrders,
                [],
                true,
                true,
                10
            ),
            new Definition(
                'get_order_status',
                'Status, items, and tracking for one order the customer named.',
                [
                    'type' => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'string', 'description' => 'Order id to look up.'],
                    ],
                    'required' => ['order_id'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->getOrderStatus,
                [],
                true,
                true,
                11
            ),
            new Definition(
                'search_policies',
                "Search the store's own terms and help content: returns, shipping, "
                    . 'warranties, membership, fees, and the buying guides. It covers the '
                    . 'pages and blocks listed under Store facts.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The term or topic to look up.'],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->searchPolicies,
                [],
                false,
                true,
                12
            ),
            new Definition(
                'get_fulfillment_options',
                "Delivery and pickup options, with their dates, for given products at the "
                    . "customer's location.",
                [
                    'type' => 'object',
                    'properties' => [
                        'product_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'maxItems' => 20,
                            'description' => 'product_ids to quote options for.',
                        ],
                    ],
                    'required' => ['product_ids'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->getFulfillmentOptions,
                ['product_ids[]'],
                false,
                true,
                13
            ),
            new Definition(
                'save_memory',
                'Save a durable fact about the customer when they ask you to remember '
                    . 'something or state a standing rule about how they shop. An ask to '
                    . 'remember is the memory-personalization flow, and its skill says how the '
                    . 'fact is worded: read it in the same round. Save the need an item '
                    . 'reveals, never product or policy text.',
                [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'maxLength' => 64,
                            'description' => 'Topic key; reuse an existing key to replace its value.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'maxLength' => 200,
                            'description' => 'The fact, worded to stand on its own later.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'enum' => ['preference', 'constraint', 'context'],
                            'description' => 'constraint for a rule picks must respect; else '
                                . 'preference or context.',
                        ],
                    ],
                    'required' => ['key', 'value'],
                    'additionalProperties' => false,
                ],
                'write',
                $this->memoryOff,
                [],
                false,
                true,
                14
            ),
            new Definition(
                'recall_memories',
                "Search the customer's saved facts that are not in the Session context "
                    . 'block: older preferences, sizes, past recipients, recurring needs. Use '
                    . 'it when such a fact would change your recommendation.',
                [
                    'type' => 'object',
                    'properties' => [
                        'topic' => [
                            'type' => 'string',
                            'maxLength' => 100,
                            'description' => 'Topic to search for, in a few words.',
                        ],
                    ],
                    'required' => ['topic'],
                    'additionalProperties' => false,
                ],
                'read',
                $this->memoryOff,
                [],
                false,
                true,
                15
            ),
        ];
        return array_merge($tools, $this->presentationDefinitions());
    }

    /**
     * @return \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[]
     */
    private function presentationDefinitions(): array
    {
        $sortOrders = [
            'present_products' => 16,
            'present_comparison' => 17,
            'present_order_status' => 18,
            'checkout' => 19,
            'present_suggestions' => 20,
        ];
        $components = $this->presentation->components();
        $definitions = [];
        foreach ($sortOrders as $name => $sortOrder) {
            $component = $components[$name];
            $definitions[] = new Definition(
                $component->name,
                $component->description,
                $component->schema,
                'presentation',
                null,
                [],
                false,
                false,
                $sortOrder
            );
        }
        return $definitions;
    }

    private function sortedSkillNames(): array
    {
        return $this->skills->names();
    }

    private function productId(string $description = 'product_id returned by a tool this session.'): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    private function filtersSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Constraints the customer stated; leave guesses in the query.',
            'properties' => [
                'category' => ['type' => 'string', 'description' => 'Catalog category name.'],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'category_id from the catalog map or search_categories; preferred '
                        . 'over category.',
                ],
                'min_price' => ['type' => 'number', 'description' => 'Lowest acceptable price.'],
                'max_price' => ['type' => 'number', 'description' => 'Price ceiling the customer stated.'],
                'attributes' => [
                    'type' => 'object',
                    'description' => 'Attribute or option filters as key/value pairs, e.g. '
                        . '{"material": "wool"}.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['relevance', 'price_asc', 'price_desc', 'best_sellers'],
                    'description' => 'Result order; relevance unless the customer asked otherwise. '
                        . 'best_sellers ranks by units sold in the store over the last two years.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
