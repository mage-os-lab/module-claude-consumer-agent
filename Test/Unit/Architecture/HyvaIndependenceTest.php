<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class HyvaIndependenceTest extends TestCase
{
    private const SCANNED_DIRECTORIES = ['Api', 'Block', 'Console', 'Controller', 'Cron', 'Model', 'Observer', 'ViewModel'];

    public function testPhpClassesDoNotReferenceHyvaClasses(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $offenders = [];
        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $path = $moduleRoot . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match('/Hyva\\\\/', (string)file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen($moduleRoot) + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Luma stores run without Hyvä packages, so these classes must not use Hyva classes.');
    }
}
