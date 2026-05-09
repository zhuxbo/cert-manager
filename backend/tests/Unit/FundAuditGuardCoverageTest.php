<?php

test('疑似资金测试必须接入 afterEach 审计守门或显式登记排除', function () {
    $root = realpath(__DIR__.'/..');
    $guarded = array_flip(fundAuditGuardedTestPaths());
    $excluded = array_flip(fundAuditGuardExcludedTestPaths());
    $missing = [];

    foreach (fundAuditGuardAllTestPaths($root) as $path) {
        if (! preg_match(fundAuditGuardCandidatePattern(), $path)) {
            continue;
        }
        if (isset($guarded[$path]) || isset($excluded[$path])) {
            continue;
        }

        $missing[] = $path;
    }

    if ($missing !== []) {
        test()->fail('以下疑似资金测试未接入资金审计守门，也未登记排除：'.implode(', ', $missing));
    }

    expect($missing)->toBeEmpty();
});

/**
 * 扫描 Feature/Unit 测试目录，忽略 fixture/support 等非测试运行文件。
 */
function fundAuditGuardAllTestPaths(string $root): array
{
    $paths = [];

    foreach (['Feature', 'Unit'] as $directory) {
        $base = $root.DIRECTORY_SEPARATOR.$directory;
        if (! is_dir($base)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
    }

    sort($paths);

    return $paths;
}
