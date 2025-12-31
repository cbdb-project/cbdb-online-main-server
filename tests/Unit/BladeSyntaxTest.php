<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class BladeSyntaxTest extends TestCase {
    #[Test]
    public function test_blade_templates_do_not_use_php5_or_operator(): void {
        $viewsPath = base_path('resources/views');
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsPath));
        foreach ($iterator as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -10) !== '.blade.php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (!preg_match_all('/\{\{[^}]*\bor\b[^}]*\}\}/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                [$snippet, $offset] = $match;
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $violations[] = sprintf('%s:%d -> %s', $file->getPathname(), $line, trim($snippet));
            }
        }

        $this->assertEmpty(
            $violations,
            "發現仍有使用 PHP5 `or` 語法的 Blade 模板：\n" . implode("\n", $violations)
        );
    }
}
