<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RepositoryHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_ignore_rules_and_untrack_manifest_cover_nonruntime_bloat(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        foreach (['/images/', '/artifacts/', '/design-previews/', '/qa-fixes/', '/files/', '/public/build/', '/node_modules/', '/uploads/SECURITY_MASTER_PROMPT_01-05.md'] as $rule) {
            $this->assertStringContainsString($rule, $gitignore);
        }

        $untrack = (string) file_get_contents(base_path('UNTRACK-LIST.txt'));
        foreach (['images/', 'artifacts/', 'design-previews/', 'qa-fixes/', 'files/', 'evidence/', 'uploads/SECURITY_MASTER_PROMPT_01-05.md'] as $path) {
            $this->assertContains($path, preg_split('/\R/', trim($untrack)) ?: []);
        }

        $sourceRoots = ['app', 'config', 'routes', 'resources', 'scripts', 'tests'];
        $pattern = '~(?:base_path|resource_path|storage_path|file_get_contents|fopen|is_file)\s*\(\s*(?:\x27|")/?(?:images|artifacts|design-previews|qa-fixes|files)/~';
        foreach ($sourceRoots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|blade\.php|js|mjs|ts|css|json|py|sh)$/', $file->getFilename())) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression($pattern, $source, 'Runtime source points into an ignored bloat directory: '.$file->getPathname());
            }
        }
    }
}
