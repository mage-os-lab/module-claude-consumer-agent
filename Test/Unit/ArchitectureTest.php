<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit;

use PHPUnit\Framework\TestCase;

class ArchitectureTest extends TestCase
{
    private const FORBIDDEN_SESSION_TYPES = [
        'Magento\Customer\Model\Session',
        'Magento\Checkout\Model\Session',
    ];

    private const FORBIDDEN_SESSION_NAMESPACE = 'Magento\Framework\Session';

    public function testNoAgentOrBackendConstructorDependsOnAMagentoSessionClass(): void
    {
        $violations = [];
        $classes = array_merge($this->classesUnder('Model/Agent'), $this->classesUnder('Model/Backend'));
        foreach ($classes as $class) {
            foreach ($this->constructorParameterTypes($class) as $type) {
                if ($this->isForbiddenSessionType($type)) {
                    $violations[] = $class . '::__construct(' . $type . ')';
                }
            }
        }

        $this->assertSame([], $violations, 'Session-class dependency found: ' . implode(', ', $violations));
    }

    public function testNoAgentClassDependsOnAMagentoRepositoryInterface(): void
    {
        $violations = [];
        foreach ($this->classesUnder('Model/Agent') as $class) {
            foreach ($this->constructorParameterTypes($class) as $type) {
                if (str_starts_with($type, 'Magento\\') && str_ends_with($type, 'RepositoryInterface')) {
                    $violations[] = $class . '::__construct(' . $type . ')';
                }
            }
        }

        $this->assertSame([], $violations, 'Magento repository dependency found under Model\\Agent: ' . implode(', ', $violations));
    }

    /**
     * @return string[]
     */
    private function classesUnder(string $relativeDir): array
    {
        $moduleRoot = dirname(__DIR__, 2);
        $classes = [];
        foreach ($this->phpFilesUnder($moduleRoot . '/' . $relativeDir) as $file) {
            $relative = substr($file, strlen($moduleRoot) + 1);
            $relative = substr($relative, 0, -4);
            $className = 'MageOS\\AiShoppingAssistant\\' . str_replace('/', '\\', $relative);
            if (class_exists($className) || interface_exists($className)) {
                $classes[] = $className;
            }
        }
        return $classes;
    }

    /**
     * @return string[]
     */
    private function phpFilesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $entries = scandir($dir);
        $entries = $entries !== false ? $entries : [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->phpFilesUnder($path));
                continue;
            }
            if (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
        return $files;
    }

    /**
     * @return string[]
     */
    private function constructorParameterTypes(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }
        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }
        return $types;
    }

    private function isForbiddenSessionType(string $type): bool
    {
        if (in_array($type, self::FORBIDDEN_SESSION_TYPES, true)) {
            return true;
        }
        return str_starts_with($type, self::FORBIDDEN_SESSION_NAMESPACE . '\\');
    }
}
