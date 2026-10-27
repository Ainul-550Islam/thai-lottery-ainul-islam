<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EnvTemplateCompletenessTest extends TestCase
{
    public function test_all_literal_environment_keys_used_by_application_configuration_are_documented_in_each_template(): void
    {
        $root = dirname(__DIR__, 3);
        $keys = [];
        foreach (['config', 'bootstrap', 'app'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                preg_match_all('/\\benv\\(\\s*[\\\"\\\']([A-Z][A-Z0-9_]*)[\\\"\\\']/', $source, $matches);
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        foreach (['.env.example', '.env.production.example', '.env.ci'] as $template) {
            $contents = file_get_contents($root.'/'.$template);
            self::assertIsString($contents);
            preg_match_all('/^\\s*([A-Z][A-Z0-9_]*)=/m', $contents, $matches);
            $documented = array_fill_keys($matches[1], true);
            self::assertSame([], array_values(array_diff(array_keys($keys), array_keys($documented))), $template.' is missing environment keys.');
        }
    }
}
