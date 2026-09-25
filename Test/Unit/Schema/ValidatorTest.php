<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Schema;

use MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'maxLength' => 300],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'filters' => [
                    'type' => 'object',
                    'properties' => [
                        'sort' => ['type' => 'string', 'enum' => ['relevance', 'price_asc', 'price_desc']],
                    ],
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function testValidDataProducesNoErrors(): void
    {
        $data = ['query' => 'tent'];
        $this->assertSame([], $this->validator->validate($this->schema(), $data));
    }

    public function testMissingRequiredFieldIsReported(): void
    {
        $data = [];
        $errors = $this->validator->validate($this->schema(), $data);
        $this->assertSame(['query is required'], $errors);
    }

    public function testWrongTypeIsReported(): void
    {
        $data = ['query' => 123];
        $errors = $this->validator->validate($this->schema(), $data);
        $this->assertSame(['query must be of type string'], $errors);
    }

    public function testEnumViolationIsReportedWithTheNestedPath(): void
    {
        $data = ['query' => 'tent', 'filters' => ['sort' => 'cheapest']];
        $errors = $this->validator->validate($this->schema(), $data);
        $this->assertSame(['filters.sort must be one of relevance, price_asc, price_desc'], $errors);
    }

    public function testMinimumAndMaximumAreEnforced(): void
    {
        $data = ['query' => 'tent', 'limit' => 0];
        $this->assertSame(['limit must be at least 1'], $this->validator->validate($this->schema(), $data));

        $data2 = ['query' => 'tent', 'limit' => 21];
        $this->assertSame(['limit must be at most 20'], $this->validator->validate($this->schema(), $data2));
    }

    public function testMinLengthAndMaxLengthAreEnforced(): void
    {
        $schema = ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5]]];
        $data = ['note' => 'x'];
        $this->assertSame(['note must be at least 2 characters'], $this->validator->validate($schema, $data));

        $data2 = ['note' => 'toolong'];
        $this->assertSame(['note must be at most 5 characters'], $this->validator->validate($schema, $data2));
    }

    public function testAdditionalPropertiesFalseRejectsUnknownKeys(): void
    {
        $schema = $this->schema();
        $schema['additionalProperties'] = false;
        $data = ['query' => 'tent', 'extra' => 'x'];
        $this->assertSame(['extra is not an allowed property'], $this->validator->validate($schema, $data));
    }

    public function testDropUnknownKeysRemovesRatherThanErrors(): void
    {
        $schema = $this->schema();
        $schema['additionalProperties'] = false;
        $data = ['query' => 'tent', 'extra' => 'x'];
        $errors = $this->validator->validate($schema, $data, true);
        $this->assertSame([], $errors);
        $this->assertSame(['query' => 'tent'], $data);
    }

    public function testAdditionalPropertiesAreAllowedByDefault(): void
    {
        $data = ['query' => 'tent', 'extra' => 'x'];
        $this->assertSame([], $this->validator->validate($this->schema(), $data));
    }

    public function testArrayItemsAreValidatedAndCounted(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'product_ids' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 2,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
        $data = ['product_ids' => []];
        $this->assertSame(['product_ids must have at least 1 items'], $this->validator->validate($schema, $data));

        $data2 = ['product_ids' => ['a', 'b', 'c']];
        $this->assertSame(['product_ids must have at most 2 items'], $this->validator->validate($schema, $data2));

        $data3 = ['product_ids' => ['a', 2]];
        $this->assertSame(['product_ids[1] must be of type string'], $this->validator->validate($schema, $data3));
    }

    public function testNestedObjectsAreValidatedRecursively(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'picks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['product_id' => ['type' => 'string']],
                        'required' => ['product_id'],
                    ],
                ],
            ],
        ];
        $data = ['picks' => [['reason' => 'nice']]];
        $errors = $this->validator->validate($schema, $data);
        $this->assertSame(['picks[0].product_id is required'], $errors);
    }

    public function testAdditionalPropertiesSchemaValidatesUnknownKeys(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'options' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
        ];
        $data = ['options' => ['color' => 'blue', 'size' => ['m', 'l']]];
        $errors = $this->validator->validate($schema, $data);
        $this->assertSame(['options.size must be of type string'], $errors);

        $data2 = ['options' => ['color' => 'blue']];
        $this->assertSame([], $this->validator->validate($schema, $data2));
    }

    public function testBooleanAndNumberTypesAreChecked(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'flag' => ['type' => 'boolean'],
                'amount' => ['type' => 'number'],
            ],
        ];
        $data = ['flag' => 'yes', 'amount' => 'nine'];
        $errors = $this->validator->validate($schema, $data);
        $this->assertSame(['flag must be of type boolean', 'amount must be of type number'], $errors);

        $data2 = ['flag' => true, 'amount' => 9.5];
        $this->assertSame([], $this->validator->validate($schema, $data2));
    }
}
