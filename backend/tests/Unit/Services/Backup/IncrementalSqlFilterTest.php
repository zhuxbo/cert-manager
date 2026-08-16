<?php

use App\Services\Backup\IncrementalSqlFilter;
use Tests\TestCase;

uses(TestCase::class);

function writeGzipSql(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'incr_test_');
    $gzPath = $path.'.sql.gz';
    $gz = gzopen($gzPath, 'wb');
    gzwrite($gz, $content);
    gzclose($gz);
    @unlink($path);

    return $gzPath;
}

test('DROP TABLE 与 CREATE TABLE 被跳过，INSERT 被改写为 INSERT IGNORE', function () {
    $sql = <<<'SQL'
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint NOT NULL,
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
INSERT INTO `users` VALUES (1,'alice'),(2,'bob');
SQL;
    $src = writeGzipSql($sql);
    $dst = tempnam(sys_get_temp_dir(), 'incr_out_').'.sql';

    $stats = (new IncrementalSqlFilter)->filter($src, $dst);
    $out = file_get_contents($dst);

    expect($stats['skipped_create'])->toBe(1)
        ->and($stats['skipped_drop'])->toBe(1)
        ->and($stats['rewritten_insert'])->toBe(1)
        ->and($out)->not->toContain('CREATE TABLE')
        ->and($out)->not->toContain('DROP TABLE')
        ->and($out)->toContain('INSERT IGNORE INTO `users`')
        ->and($out)->toContain("(1,'alice'),(2,'bob')");

    @unlink($src);
    @unlink($dst);
});

test('LOCK/UNLOCK TABLES 和 ALTER TABLE disable keys 被跳过', function () {
    $sql = <<<'SQL'
LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (1);
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;
SQL;
    $src = writeGzipSql($sql);
    $dst = tempnam(sys_get_temp_dir(), 'incr_out_').'.sql';

    (new IncrementalSqlFilter)->filter($src, $dst);
    $out = file_get_contents($dst);

    expect($out)->not->toContain('LOCK TABLES')
        ->and($out)->not->toContain('UNLOCK TABLES')
        ->and($out)->not->toContain('DISABLE KEYS')
        ->and($out)->not->toContain('ENABLE KEYS')
        ->and($out)->toContain('INSERT IGNORE INTO `orders`');

    @unlink($src);
    @unlink($dst);
});

test('单行 CREATE TABLE 立即收尾也被跳过', function () {
    $sql = "CREATE TABLE `x` (`a` int);\nINSERT INTO `x` VALUES (1);\n";
    $src = writeGzipSql($sql);
    $dst = tempnam(sys_get_temp_dir(), 'incr_out_').'.sql';

    $stats = (new IncrementalSqlFilter)->filter($src, $dst);
    $out = file_get_contents($dst);

    expect($stats['skipped_create'])->toBe(1)
        ->and($out)->not->toContain('CREATE TABLE')
        ->and($out)->toContain('INSERT IGNORE INTO `x`');

    @unlink($src);
    @unlink($dst);
});

test('注释与 SET 语句原样保留', function () {
    $sql = <<<'SQL'
-- comment
/*!40101 SET NAMES utf8mb4 */;
INSERT INTO `t` VALUES (1);
SQL;
    $src = writeGzipSql($sql);
    $dst = tempnam(sys_get_temp_dir(), 'incr_out_').'.sql';

    (new IncrementalSqlFilter)->filter($src, $dst);
    $out = file_get_contents($dst);

    expect($out)->toContain('-- comment')
        ->and($out)->toContain('SET NAMES utf8mb4')
        ->and($out)->toContain('INSERT IGNORE INTO `t`');

    @unlink($src);
    @unlink($dst);
});

test('INSERT 的跨行 VALUES 保留完整（只改首行）', function () {
    $sql = "INSERT INTO `t` VALUES\n(1,'a'),\n(2,'b');\n";
    $src = writeGzipSql($sql);
    $dst = tempnam(sys_get_temp_dir(), 'incr_out_').'.sql';

    (new IncrementalSqlFilter)->filter($src, $dst);
    $out = file_get_contents($dst);

    expect($out)->toContain('INSERT IGNORE INTO `t`')
        ->and($out)->toContain("(1,'a')")
        ->and($out)->toContain("(2,'b');");

    @unlink($src);
    @unlink($dst);
});

test('目标文件无法打开时抛出稳定的领域错误并关闭 gzip 源', function () {
    $src = writeGzipSql("INSERT INTO `t` VALUES (1);\n");
    $dst = sys_get_temp_dir().'/incr_out_dir_'.uniqid();
    mkdir($dst);

    try {
        expect(fn () => (new IncrementalSqlFilter)->filter($src, $dst))
            ->toThrow(RuntimeException::class, "无法写入目标: $dst");
    } finally {
        expect(@unlink($src))->toBeTrue();
        expect(@rmdir($dst))->toBeTrue();
    }
});
