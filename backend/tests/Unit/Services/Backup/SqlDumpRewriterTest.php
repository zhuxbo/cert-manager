<?php

use App\Services\Backup\SqlDumpRewritePlan;
use App\Services\Backup\SqlDumpRewriter;
use App\Services\Backup\SqlStreamTransformer;

function taskFiveRewritePlan(): SqlDumpRewritePlan
{
    return new SqlDumpRewritePlan(
        tableMap: [
            'transactions' => '__rst_transactions',
            'widgets' => '__rst_widgets',
            'users' => '__rst_users',
            'literals' => '__rst_literals',
        ],
        columnOrder: [
            'transactions' => ['id', 'type', 'dedup_key', 'note'],
            'widgets' => ['id', 'generated_a', 'payload', 'generated_b'],
            'users' => ['id', 'name'],
            'literals' => [
                'null_value', 'positive_integer', 'negative_integer', 'positive_decimal',
                'negative_decimal', 'scientific', 'hex', 'quoted_hex', 'bits', 'single_text', 'double_text',
            ],
        ],
        generatedColumns: [
            'transactions' => ['dedup_key'],
            'widgets' => ['generated_a', 'generated_b'],
            'users' => [],
            'literals' => [],
        ],
    );
}

/** @param list<int> $chunkSizes */
function taskFiveRewrite(string $sql, array $chunkSizes = []): string
{
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());
    if ($chunkSizes === []) {
        return $rewriter->push($sql).$rewriter->finish();
    }

    $output = '';
    $offset = 0;
    $chunkIndex = 0;
    while ($offset < strlen($sql)) {
        $size = $chunkSizes[$chunkIndex % count($chunkSizes)];
        $output .= $rewriter->push(substr($sql, $offset, $size));
        $offset += $size;
        $chunkIndex++;
    }

    return $output.$rewriter->finish();
}

test('历史 transactions 生成列和同表多个生成列会从无列清单多值 INSERT 中删除', function () {
    $sql = "INSERT INTO `transactions` VALUES (1,'order','legacy','first'),(2,'refund',NULL,'second');\n"
        ."INSERT INTO `widgets` VALUES (7,'ga',X'00FF',b'1010'),(8,'gb',0xCAFE,NULL);\n";

    expect(taskFiveRewrite($sql))
        ->toBe("INSERT INTO `__rst_transactions` (`id`,`type`,`note`) VALUES (1,'order','first'),(2,'refund','second');\n"
            ."INSERT INTO `__rst_widgets` (`id`,`payload`) VALUES (7,X'00FF'),(8,0xCAFE);\n");
});

test('显式列清单同步删除生成列和值并保留字符串转义和 NULL', function () {
    $sql = <<<'SQL'
INSERT INTO `transactions` (`id`,`dedup_key`,`type`,`note`) VALUES (1,'legacy','order','a\'b\\c'),(2,NULL,"refund","x\"y");
SQL;

    expect(taskFiveRewrite($sql))->toBe(<<<'SQL'
INSERT INTO `__rst_transactions` (`id`,`type`,`note`) VALUES (1,'order','a\'b\\c'),(2,"refund","x\"y");
SQL);
});

test('显式 INSERT 列清单重复或超过 Schema 列数时立即拒绝', function (string $columns, string $message) {
    expect(fn () => taskFiveRewrite("INSERT INTO `users` ($columns) VALUES (1,'alice');"))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'duplicate column' => ['`id`,`id`', 'INSERT 列清单重复'],
    'more columns than schema' => ['`id`,`name`,`extra`', 'INSERT 列清单超过 Schema 列数'],
]);

test('每个 INSERT tuple 字段数必须与原始列清单严格一致', function (string $sql) {
    expect(fn () => taskFiveRewrite($sql))
        ->toThrow(RuntimeException::class, '字段数');
})->with([
    '缺字段' => ['INSERT INTO `users` VALUES (1);'],
    '多字段' => ["INSERT INTO `users` VALUES (1,'alice','extra');"],
    '第二个 tuple 缺字段' => ["INSERT INTO `users` VALUES (1,'alice'),(2);"],
]);

test('INSERT 只接受受控 mysqldump literal 并保持原值', function () {
    $sql = <<<'SQL'
INSERT INTO `literals` VALUES (NULL,+12,-34,56.78,-.25,6.02e+23,0xCAFE,X'00ff',b'1010','a\'b\\c',"x\"y");
SQL;

    expect(taskFiveRewrite($sql))->toBe(<<<'SQL'
INSERT INTO `__rst_literals` (`null_value`,`positive_integer`,`negative_integer`,`positive_decimal`,`negative_decimal`,`scientific`,`hex`,`quoted_hex`,`bits`,`single_text`,`double_text`) VALUES (NULL,+12,-34,56.78,-.25,6.02e+23,0xCAFE,X'00ff',b'1010','a\'b\\c',"x\"y");
SQL);
});

test('INSERT 拒绝表达式子查询函数注释和未知 literal', function (string $value) {
    expect(fn () => taskFiveRewrite("INSERT INTO `users` VALUES (1,$value);"))
        ->toThrow(RuntimeException::class, 'literal');
})->with([
    'subquery' => ['(SELECT 1)'],
    'select token' => ['SELECT'],
    'function' => ['NOW()'],
    'arithmetic' => ['1+2'],
    'block comment' => ['/* hidden */NULL'],
    'line comment' => ["-- hidden\nNULL"],
    'unknown keyword' => ['TRUE'],
    'odd quoted hex' => ["X'0'"],
    'invalid quoted hex' => ["X'GG'"],
    'invalid bit' => ["b'2'"],
    'empty unquoted hex' => ['0x'],
]);

test('被删除的生成列值也必须通过 literal 白名单', function () {
    expect(fn () => taskFiveRewrite("INSERT INTO `transactions` VALUES (1,'order',NOW(),'note');"))
        ->toThrow(RuntimeException::class, 'literal');
});

test('逐字节输入覆盖关键字反引号转义 UTF-8 值字段和 tuple 边界', function () {
    $sql = "INSERT INTO `transactions` (`id`,`type`,`dedup_key`,`note`) VALUES\n"
        ."(1,'or\\'der','legacy','证书😀'),\n(2,\"re\\\"fund\",NULL,'尾部');\n";
    $expected = taskFiveRewrite($sql);

    expect(taskFiveRewrite($sql, [1]))->toBe($expected)
        ->and(taskFiveRewrite($sql, [2, 3, 5, 7]))->toBe($expected);
});

test('单个超大 INSERT 和超大非生成字段按 push 持续流出', function () {
    $payload = str_repeat('大字段\\\'segment-', 100000);
    $sql = "INSERT INTO `widgets` VALUES (1,'discarded','".$payload."','also-discarded');";
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());
    $output = '';
    $positivePushes = 0;

    for ($offset = 0; $offset < strlen($sql); $offset += 4096) {
        $part = $rewriter->push(substr($sql, $offset, 4096));
        $output .= $part;
        if ($offset > 8192 && $part !== '') {
            $positivePushes++;
        }
        expect(strlen($part))->toBeLessThan(8192);
    }
    $output .= $rewriter->finish();

    expect($output)->toBe("INSERT INTO `__rst_widgets` (`id`,`payload`) VALUES (1,'".$payload."');")
        ->and($positivePushes)->toBeGreaterThan(100);
});

test('超大十六进制二进制字段保持流式且单次输出受输入 chunk 限制', function () {
    $hex = str_repeat('00ffCAFE', 200000);
    $sql = "INSERT INTO `users` VALUES (1,0x$hex);";
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());
    $output = '';
    $maxPartBytes = 0;
    $positivePushes = 0;

    for ($offset = 0; $offset < strlen($sql); $offset += 4096) {
        $part = $rewriter->push(substr($sql, $offset, 4096));
        $output .= $part;
        $maxPartBytes = max($maxPartBytes, strlen($part));
        if ($offset > 8192 && $part !== '') {
            $positivePushes++;
        }
    }
    $output .= $rewriter->finish();

    expect($output)->toBe("INSERT INTO `__rst_users` (`id`,`name`) VALUES (1,0x$hex);")
        ->and($maxPartBytes)->toBeLessThan(8192)
        ->and($positivePushes)->toBeGreaterThan(100);
});

test('大量重复显式列在第二个列名处早拒绝且 seen 集合受 Schema 列数限制', function () {
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());
    $rewriter->push('INSERT INTO `users` (`id`,');

    expect(fn () => $rewriter->push(str_repeat('`id`,', 100000)))
        ->toThrow(RuntimeException::class, 'INSERT 列清单重复');
});

test('表 DDL 和锁表语句映射到影子表且 CREATE 只省略外键', function () {
    $sql = <<<'SQL'
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint NOT NULL,
  `name` varchar(64) GENERATED ALWAYS AS (concat(`id`,'-name')) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_name_unique` (`name`),
  KEY `users_name_index` (`name`),
  CONSTRAINT `users_parent_fk` FOREIGN KEY (`id`) REFERENCES `parents` (`id`),
  CONSTRAINT `users_id_check` CHECK ((`id` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `users` DISABLE KEYS;
LOCK TABLES `users` WRITE, `widgets` READ;
UNLOCK TABLES;
SQL;

    $output = taskFiveRewrite($sql, [1, 11, 64]);

    expect($output)->toContain('DROP TABLE IF EXISTS `__rst_users`;')
        ->toContain('CREATE TABLE `__rst_users` (')
        ->toContain('GENERATED ALWAYS AS')
        ->toContain('PRIMARY KEY')
        ->toContain('UNIQUE KEY')
        ->toContain('KEY `users_name_index`')
        ->toContain('CONSTRAINT `users_id_check` CHECK')
        ->not->toContain('FOREIGN KEY')
        ->not->toContain('users_parent_fk')
        ->toContain('ALTER TABLE `__rst_users` DISABLE KEYS;')
        ->toContain('LOCK TABLES `__rst_users` WRITE, `__rst_widgets` READ;')
        ->toContain('UNLOCK TABLES;');
});

test('只读扫描返回所有遇到的映射表且去重', function () {
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());
    $rewriter->push("DROP TABLE IF EXISTS `users`;\nLOCK TABLES `widgets` WRITE, `users` READ;\nUNLOCK TABLES;");
    $rewriter->finish();

    expect($rewriter->encounteredTables())->toBe(['users', 'widgets']);
});

test('推导模式从 CREATE TABLE 取得旧备份列序生成列和外键', function () {
    $rewriter = new SqlDumpRewriter(new SqlDumpRewritePlan([], [], [], inferSchema: true));
    $sql = <<<'SQL'
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `dedup_key` varchar(64) GENERATED ALWAYS AS (concat(`user_id`,':',`id`)) VIRTUAL,
  CONSTRAINT `transactions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB;
INSERT INTO `transactions` VALUES (1,2,'legacy');
SQL;

    $rewriter->push($sql);
    $rewriter->finish();
    $schema = $rewriter->inferredSchema();

    expect(array_keys($schema['tables']))->toBe(['users', 'transactions'])
        ->and(array_keys($schema['tables']['users']['columns']))->toBe(['id', 'name'])
        ->and($schema['tables']['users']['columns']['id']['type'])->toBe('bigint unsigned')
        ->and($schema['tables']['users']['auto_increment'])->toBe('9')
        ->and($schema['tables']['users']['indexes']['PRIMARY'])->toBe([
            'unique' => true,
            'type' => 'BTREE',
            'columns' => ['id'],
            'sub_parts' => [null],
        ])
        ->and(array_keys($schema['tables']['transactions']['columns']))
        ->toBe(['id', 'user_id', 'dedup_key'])
        ->and($schema['tables']['transactions']['columns']['dedup_key']['generation_expression'])
        ->not->toBe('')
        ->and($schema['tables']['transactions']['foreign_keys']['transactions_user_fk'])
        ->toMatchArray([
            'columns' => ['user_id'],
            'references' => ['table' => 'users', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ]);
});

test('CREATE clause 遇到可伪造深度的普通或版本块注释立即 fail closed', function (string $comment) {
    $sql = "CREATE TABLE `users` (\n"
        ."  `id` bigint NOT NULL $comment,\n"
        .'  CONSTRAINT `users_parent_fk` FOREIGN KEY (`id`) REFERENCES `parents` (`id`)'."\n"
        .') ENGINE=InnoDB;';

    expect(fn () => taskFiveRewrite($sql, [1]))
        ->toThrow(RuntimeException::class, 'CREATE clause 不允许注释');
})->with([
    'ordinary comment with close parenthesis' => ['/* ) hides depth */'],
    'version comment with comma' => ['/*!99999 , hides delimiter */'],
    'split line comment with close parenthesis' => ["-- ) hides depth\n"],
    'hash comment with comma' => ["# , hides delimiter\n"],
]);

test('CREATE clause 不允许内部分号提前终止并夹带第二语句', function () {
    $sql = 'CREATE TABLE `users` (`id` bigint; DROP DATABASE forbidden);';

    expect(fn () => taskFiveRewrite($sql, [1]))
        ->toThrow(RuntimeException::class, 'CREATE clause 不允许分号');
});

test('CREATE 保留的 clause 不允许留下 active REFERENCES', function () {
    $sql = 'CREATE TABLE `users` (`id` bigint REFERENCES `parents` (`id`));';

    expect(fn () => taskFiveRewrite($sql, [1]))
        ->toThrow(RuntimeException::class, 'REFERENCES');
});

test('普通注释 SET 与版本条件 ALTER 保留且条件语句中的表名被映射', function () {
    $sql = <<<'SQL'
-- controlled dump
# another comment
/* ordinary block */
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
SQL;

    $output = taskFiveRewrite($sql, [1]);

    expect($output)->toContain('-- controlled dump')
        ->toContain('# another comment')
        ->toContain('/* ordinary block */')
        ->toContain('/*!40101 */;')
        ->toContain('/*!40000 ALTER TABLE `__rst_users` DISABLE KEYS */;')
        ->toContain('/*!40000 ALTER TABLE `__rst_users` ENABLE KEYS */;');
});

test('真实 artifact 的 21 种唯一 SET 形态逐一归一化', function (string $version, string $statement, string $normalized) {
    $input = "/*!$version $statement */;";
    $expected = "/*!$version ".($normalized === '' ? '' : $normalized.' ').'*/;';

    expect(taskFiveRewrite($input, [1, 3, 17]))->toBe($expected);
})->with([
    ['40014', 'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0', 'SET FOREIGN_KEY_CHECKS=0'],
    ['40014', 'SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0', 'SET UNIQUE_CHECKS=0'],
    ['40014', 'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS', ''],
    ['40014', 'SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS', ''],
    ['40101', 'SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT', ''],
    ['40101', 'SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS', ''],
    ['40101', 'SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION', ''],
    ['40101', "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"],
    ['40101', 'SET @saved_cs_client     = @@character_set_client', ''],
    ['40101', 'SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT', ''],
    ['40101', 'SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS', ''],
    ['40101', 'SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION', ''],
    ['40101', 'SET NAMES utf8mb4', 'SET NAMES utf8mb4'],
    ['40101', 'SET SQL_MODE=@OLD_SQL_MODE', ''],
    ['40101', 'SET character_set_client = @saved_cs_client', ''],
    ['40101', 'SET character_set_client = utf8mb4', 'SET character_set_client=utf8mb4'],
    ['40103', 'SET @OLD_TIME_ZONE=@@TIME_ZONE', ''],
    ['40103', "SET TIME_ZONE='+00:00'", "SET TIME_ZONE='+00:00'"],
    ['40103', 'SET TIME_ZONE=@OLD_TIME_ZONE', ''],
    ['40111', 'SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0', 'SET SQL_NOTES=0'],
    ['40111', 'SET SQL_NOTES=@OLD_SQL_NOTES', ''],
]);

test('MySQL 8 mysqldump 的 50503 SET 形态精确归一化', function (string $statement, string $normalized) {
    $input = "/*!50503 $statement */;";
    $expected = "/*!50503 $normalized */;";

    expect(taskFiveRewrite($input, [1, 3, 17]))->toBe($expected);
})->with([
    ['SET NAMES utf8mb4', 'SET NAMES utf8mb4'],
    ['SET character_set_client = utf8mb4', 'SET character_set_client=utf8mb4'],
]);

test('SET 拒绝越权 scope 表达式注释和未知变量', function (string $sql) {
    expect(fn () => taskFiveRewrite($sql))->toThrow(RuntimeException::class);
})->with([
    'global' => ["SET GLOBAL SQL_MODE='ANSI';"],
    'persist' => ["SET PERSIST SQL_MODE='ANSI';"],
    'password' => ["SET PASSWORD='secret';"],
    'backtick scope' => ['SET `SQL_MODE`=0;'],
    'function' => ['SET SQL_MODE=NOW();'],
    'subquery' => ['SET SQL_MODE=(SELECT 1);'],
    'comment' => ['SET SQL_MODE=0 /* hidden */;'],
    'unknown user variable' => ['SET @x=1;'],
    'nonstandard sql mode changes string lexer' => ["/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;"],
    'arbitrary sql mode' => ["/*!40101 SET SQL_MODE='ANSI' */;"],
    'non-artifact sql mode casing' => ["/*!40101 SET SQL_MODE='no_auto_value_on_zero' */;"],
    'cartesian old value mismatch' => ['/*!40103 SET TIME_ZONE=@OLD_SQL_MODE */;'],
    'other charset' => ['/*!40101 SET NAMES latin1 */;'],
    'saved client wrong source' => ['/*!40101 SET @saved_cs_client=@@SQL_MODE */;'],
    'saved client arbitrary value' => ['/*!40101 SET @saved_cs_client=utf8mb4 */;'],
    'client charset other value' => ['/*!40101 SET character_set_client=latin1 */;'],
    'real statement outside version comment' => ['SET NAMES utf8mb4;'],
    'real statement under wrong version' => ['/*!40103 SET NAMES utf8mb4 */;'],
    'mysql 8 statement under wrong version' => ['/*!50504 SET NAMES utf8mb4 */;'],
    'mysql 8 unknown statement combination' => ["/*!50503 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;"],
]);

test('版本条件注释只允许一条受控语句且内部分号立即拒绝', function () {
    expect(fn () => taskFiveRewrite('/*!40101 SET @x=1; DROP DATABASE forbidden */;'))
        ->toThrow(RuntimeException::class);
});

test('MySQL 5.7 官方 mysqldump 的 utf8 client SET 形态精确归一化', function () {
    expect(taskFiveRewrite('/*!40101 SET character_set_client = utf8 */;'))
        ->toBe('/*!40101 SET character_set_client=utf8 */;');
});

test('短控制语句缓冲有 16KiB 硬上限', function () {
    expect(fn () => taskFiveRewrite('/*!40101 SET NAMES '.str_repeat('a', 17000).' */;'))
        ->toThrow(RuntimeException::class, '超过安全上限');
});

test('CREATE tail 只接受真实表选项并拒绝 AS SELECT LIKE 注释和额外语句', function (string $tail) {
    $sql = "CREATE TABLE `users` (`id` bigint NOT NULL) $tail;";

    expect(fn () => taskFiveRewrite($sql))->toThrow(RuntimeException::class);
})->with([
    'as select' => ['ENGINE=InnoDB AS SELECT 1'],
    'like' => ['LIKE `widgets`'],
    'block comment' => ['ENGINE=InnoDB /* hidden */'],
    'embedded statement' => ['ENGINE=InnoDB SET SQL_MODE=0'],
]);

test('CREATE tail 保留 mysqldump 实际表选项组合', function () {
    $tail = "ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='users; table'";
    $sql = "CREATE TABLE `users` (`id` bigint NOT NULL) $tail;";

    expect(taskFiveRewrite($sql))->toBe("CREATE TABLE `__rst_users` (`id` bigint NOT NULL) $tail;");
});

test('ALTER TABLE 只接受官方 mysqldump 的 ENABLE 或 DISABLE KEYS', function (string $sql) {
    expect(fn () => taskFiveRewrite($sql))
        ->toThrow(RuntimeException::class, 'ALTER TABLE 只允许 ENABLE KEYS 或 DISABLE KEYS');
})->with([
    'add key' => ['ALTER TABLE `users` ADD KEY `idx` (`name`);'],
    'add foreign key' => ['ALTER TABLE `users` ADD FOREIGN KEY (`id`) REFERENCES `widgets` (`id`);'],
    'references' => ['ALTER TABLE `users` REFERENCES `widgets`;'],
    'rename' => ['ALTER TABLE `users` RENAME TO `escaped`;'],
]);

test('所有表语法拒绝库限定标识符', function (string $sql) {
    expect(fn () => taskFiveRewrite($sql))
        ->toThrow(RuntimeException::class, '不允许库限定表名');
})->with([
    'drop' => ['DROP TABLE `users`.`widgets`;'],
    'create' => ['CREATE TABLE `users`.`widgets` (`id` int);'],
    'alter' => ['ALTER TABLE `users`.`widgets` DISABLE KEYS;'],
    'lock' => ['LOCK TABLES `users`.`widgets` WRITE;'],
    'insert' => ['INSERT INTO `users`.`widgets` VALUES (1);'],
]);

test('LOCK TABLES 只接受已映射表和 READ WRITE 模式', function (string $sql) {
    expect(fn () => taskFiveRewrite($sql))
        ->toThrow(RuntimeException::class, 'LOCK TABLES');
})->with([
    'unknown mode' => ['LOCK TABLES `users` LOW_PRIORITY WRITE;'],
    'extra token' => ['LOCK TABLES `users` WRITE AS alias;'],
    'missing next table' => ['LOCK TABLES `users` WRITE, ;'],
]);

test('非白名单语句危险对象和未知表全部 fail closed', function (string $sql) {
    expect(fn () => taskFiveRewrite($sql))->toThrow(RuntimeException::class);
})->with([
    'create database' => ['CREATE DATABASE forbidden;'],
    'drop database' => ['DROP DATABASE forbidden;'],
    'use database' => ['USE forbidden;'],
    'create user' => ["CREATE USER 'attacker'@'%';"],
    'grant' => ["GRANT ALL ON *.* TO 'attacker'@'%';"],
    'procedure' => ['CREATE PROCEDURE p() SELECT 1;'],
    'function' => ['CREATE FUNCTION f() RETURNS INT RETURN 1;'],
    'event' => ['CREATE EVENT e ON SCHEDULE EVERY 1 DAY DO SELECT 1;'],
    'view' => ['CREATE VIEW v AS SELECT 1;'],
    'trigger' => ['CREATE TRIGGER tr BEFORE INSERT ON users FOR EACH ROW SET @x=1;'],
    'unknown statement' => ['SELECT * FROM users;'],
    'unknown insert table' => ['INSERT INTO `unknown` VALUES (1);'],
    'unknown ddl table' => ['CREATE TABLE `unknown` (`id` int);'],
    'unsafe alter rename' => ['ALTER TABLE `users` RENAME TO `escaped`;'],
]);

test('finish 拒绝半条 SQL 和未闭合词法结构', function (string $sql) {
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());
    $rewriter->push($sql);

    expect(fn () => $rewriter->finish())->toThrow(RuntimeException::class);
})->with([
    'half statement' => ["INSERT INTO `users` VALUES (1,'alice')"],
    'incomplete tuple' => ['INSERT INTO `users` VALUES (1'],
    'single quote' => ["INSERT INTO `users` VALUES (1,'alice);"],
    'double quote' => ['INSERT INTO `users` VALUES (1,"alice);'],
    'backtick' => ['INSERT INTO `users VALUES (1,\'alice\');'],
    'block comment' => ['/* unfinished'],
    'version comment' => ['/*!40101 SET NAMES utf8mb4'],
]);

test('合法尾部行注释和块注释可以在无额外 SQL 时结束', function () {
    expect(taskFiveRewrite('-- tail without newline'))->toBe('-- tail without newline')
        ->and(taskFiveRewrite('/* complete tail */'))->toBe('/* complete tail */');
});

test('实现 Task 4 的流式转换接口并报告当前源表', function () {
    $rewriter = new SqlDumpRewriter(taskFiveRewritePlan());

    expect($rewriter)->toBeInstanceOf(SqlStreamTransformer::class)
        ->and($rewriter->push('INSERT INTO `users` VALUES (1,'))->toContain('`__rst_users`')
        ->and($rewriter->currentTable())->toBe('users');

    $rewriter->push("'alice');");
    $rewriter->finish();

    expect($rewriter->currentTable())->toBeNull();
});
