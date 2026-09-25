<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Schema;

final class Validator
{
    public function validate(array $schema, array &$data, bool $dropUnknownKeys = false): array
    {
        $errors = [];
        $value = $data;
        $this->check($schema, $value, '', $dropUnknownKeys, $errors);
        if (is_array($value)) {
            $data = $value;
        }
        return $errors;
    }

    private function check(array $schema, mixed &$value, string $path, bool $dropUnknownKeys, array &$errors): void
    {
        $type = $schema['type'] ?? null;
        if (is_string($type) && !$this->matchesType($value, $type)) {
            $errors[] = $this->label($path) . ' must be of type ' . $type;
            return;
        }
        switch ($type) {
            case 'object':
                $this->checkObject($schema, $value, $path, $dropUnknownKeys, $errors);
                break;
            case 'array':
                $this->checkArray($schema, $value, $path, $dropUnknownKeys, $errors);
                break;
            case 'string':
                $this->checkString($schema, $value, $path, $errors);
                break;
            case 'integer':
            case 'number':
                $this->checkNumber($schema, $value, $path, $errors);
                break;
            default:
                break;
        }
        $this->checkEnum($schema, $value, $path, $errors);
    }

    private function checkObject(array $schema, mixed &$value, string $path, bool $dropUnknownKeys, array &$errors): void
    {
        if (!is_array($value)) {
            return;
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $key) {
            if (!array_key_exists((string)$key, $value)) {
                $errors[] = $this->childPath($path, (string)$key) . ' is required';
            }
        }
        $additionalProperties = $schema['additionalProperties'] ?? true;
        $additionalAllowed = $additionalProperties !== false;
        $additionalSchema = is_array($additionalProperties) ? $additionalProperties : null;
        foreach ($value as $key => $item) {
            $propertySchema = $properties[$key] ?? null;
            if (!is_array($propertySchema)) {
                if (!$additionalAllowed) {
                    if ($dropUnknownKeys) {
                        unset($value[$key]);
                    } else {
                        $errors[] = $this->childPath($path, (string)$key) . ' is not an allowed property';
                    }
                    continue;
                }
                if ($additionalSchema === null) {
                    continue;
                }
                $propertySchema = $additionalSchema;
            }
            $child = $item;
            $this->check($propertySchema, $child, $this->childPath($path, (string)$key), $dropUnknownKeys, $errors);
            $value[$key] = $child;
        }
    }

    private function checkArray(array $schema, mixed &$value, string $path, bool $dropUnknownKeys, array &$errors): void
    {
        if (!is_array($value)) {
            return;
        }
        $count = count($value);
        if (isset($schema['minItems']) && $count < (int)$schema['minItems']) {
            $errors[] = $this->label($path) . ' must have at least ' . (int)$schema['minItems'] . ' items';
        }
        if (isset($schema['maxItems']) && $count > (int)$schema['maxItems']) {
            $errors[] = $this->label($path) . ' must have at most ' . (int)$schema['maxItems'] . ' items';
        }
        $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : null;
        if ($itemSchema === null) {
            return;
        }
        $index = 0;
        foreach ($value as $key => $item) {
            $child = $item;
            $this->check($itemSchema, $child, $path . '[' . $index . ']', $dropUnknownKeys, $errors);
            $value[$key] = $child;
            $index++;
        }
    }

    private function checkString(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (!is_string($value)) {
            return;
        }
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && $length < (int)$schema['minLength']) {
            $errors[] = $this->label($path) . ' must be at least ' . (int)$schema['minLength'] . ' characters';
        }
        if (isset($schema['maxLength']) && $length > (int)$schema['maxLength']) {
            $errors[] = $this->label($path) . ' must be at most ' . (int)$schema['maxLength'] . ' characters';
        }
    }

    private function checkNumber(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (!is_int($value) && !is_float($value)) {
            return;
        }
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = $this->label($path) . ' must be at least ' . $schema['minimum'];
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = $this->label($path) . ' must be at most ' . $schema['maximum'];
        }
    }

    private function checkEnum(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (!isset($schema['enum']) || !is_array($schema['enum'])) {
            return;
        }
        if (!in_array($value, $schema['enum'], true)) {
            $errors[] = $this->label($path) . ' must be one of ' . implode(', ', array_map('strval', $schema['enum']));
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => true,
        };
    }

    private function childPath(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }

    private function label(string $path): string
    {
        return $path !== '' ? $path : 'input';
    }
}
