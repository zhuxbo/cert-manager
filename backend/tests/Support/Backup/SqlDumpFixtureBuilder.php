<?php

declare(strict_types=1);

namespace Tests\Support\Backup;

use App\Services\Backup\SqlDumpRewritePlan;

final class SqlDumpFixtureBuilder
{
    /** @var list<string> */
    public const SOURCE_TABLES = [
        'admins',
        'users',
        'transactions',
        'task12_parents',
        'task12_children',
        'agisos',
    ];

    /** @var list<string> */
    public const RUNTIME_TABLES = ['jobs', 'admin_refresh_tokens', 'user_refresh_tokens'];

    /** @var list<string> */
    public const RETAINED_LOG_TABLES = ['activity_logs', 'easy_logs', 'cloud_deploy_logs'];

    public function dump(int $bulkRows = 4): string
    {
        $bulkRows = max(1, $bulkRows);
        $users = [];
        for ($index = 0; $index < $bulkRows; $index++) {
            $id = 718793000000000000 + $index;
            $users[] = "($id,'member-$index','成员\\'{$index}😀')";
        }

        return <<<'SQL'
/*!40101 SET NAMES utf8mb4 */;
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `admins` (`id`,`username`) VALUES (41,'root');
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL,
  `username` varchar(64) NOT NULL,
  `note` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `users` (`id`,`username`,`note`) VALUES
SQL
            .implode(',', $users).";\n"
            .<<<'SQL'
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  `dedup_key` varchar(96) GENERATED ALWAYS AS (concat(`type`,':',`transaction_id`)) VIRTUAL,
  `payload` varbinary(16) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `transactions` VALUES
(1,718793000000000000,'order',9001,'order:9001',0x00ffCAFE),
(2,718793000000000000,'cancel',9002,'cancel:9002',X'1020');
INSERT INTO `transactions` (`id`,`user_id`,`dedup_key`,`type`,`transaction_id`,`payload`) VALUES
(3,718793000000000000,'addfunds:9003','addfunds',9003,b'10101010');
DROP TABLE IF EXISTS `task12_parents`;
CREATE TABLE `task12_parents` (
  `id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
INSERT INTO `task12_parents` VALUES (1),(2);
DROP TABLE IF EXISTS `task12_children`;
CREATE TABLE `task12_children` (
  `id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `task12_children_parent_idx` (`parent_id`),
  CONSTRAINT `task12_children_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `task12_parents` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
INSERT INTO `task12_children` VALUES (1,1),(2,2);
DROP TABLE IF EXISTS `agisos`;
CREATE TABLE `agisos` (
  `id` bigint unsigned NOT NULL,
  `payload` blob NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
INSERT INTO `agisos` VALUES (1,0x000102ffCAFE);
SQL;
    }

    /** @return array<string, mixed> */
    public function schema(
        string $series = '8.4',
        bool $legacyMetadata = false,
        string $clientVendor = 'mysql',
    ): array {
        $columns = $this->columnOrder();
        $tables = [];
        foreach ($columns as $table => $names) {
            $tables[$table] = [
                'engine' => 'InnoDB',
                'columns' => array_combine($names, array_map(
                    fn (string $column): array => [
                        'type' => $column === 'id' ? 'bigint unsigned' : 'varchar(255)',
                        'extra' => $column === 'dedup_key' ? 'VIRTUAL GENERATED' : '',
                        'generation_expression' => $column === 'dedup_key'
                            ? "concat(`type`,':',`transaction_id`)"
                            : '',
                    ],
                    $names,
                )),
                'foreign_keys' => [],
            ];
        }
        $tables['task12_children']['foreign_keys'] = [
            'task12_children_parent_fk' => [
                'columns' => ['parent_id'],
                'references' => ['table' => 'task12_parents', 'columns' => ['id']],
                'on_delete' => 'RESTRICT',
                'on_update' => 'CASCADE',
            ],
        ];

        $schema = ['tables' => $tables];
        if (! $legacyMetadata) {
            $version = match ($series) {
                '5.7' => '5.7.44',
                '8.0' => '8.0.43',
                default => '8.4.6',
            };
            $schema['backup_meta'] = [
                'format_version' => 2,
                'application' => [
                    'version' => '2.9.0-fixture',
                    'channel' => 'main',
                    'build_commit' => 'fixture-commit',
                ],
                'toolchain' => [
                    'server_version' => $version,
                    'client_version' => $clientVendor === 'mariadb' ? '10.11.18-MariaDB' : $version,
                ],
                'included_tables' => self::SOURCE_TABLES,
                'excluded_tables' => array_merge(self::RUNTIME_TABLES, self::RETAINED_LOG_TABLES),
            ];
        }

        return $schema;
    }

    public function rewritePlan(string $token = 'abcdef123456'): SqlDumpRewritePlan
    {
        $tables = self::SOURCE_TABLES;
        $map = [];
        foreach ($tables as $table) {
            $map[$table] = "__rst_{$token}_{$table}";
        }

        return new SqlDumpRewritePlan(
            tableMap: $map,
            columnOrder: $this->columnOrder(),
            generatedColumns: $this->generatedColumns($tables),
        );
    }

    /** @param list<string> $tables @return array<string, list<string>> */
    private function generatedColumns(array $tables): array
    {
        $generated = [];
        foreach ($tables as $table) {
            $generated[$table] = $table === 'transactions' ? ['dedup_key'] : [];
        }

        return $generated;
    }

    /** @return array<string, list<string>> */
    public function columnOrder(): array
    {
        return [
            'admins' => ['id', 'username'],
            'users' => ['id', 'username', 'note'],
            'transactions' => ['id', 'user_id', 'type', 'transaction_id', 'dedup_key', 'payload'],
            'task12_parents' => ['id'],
            'task12_children' => ['id', 'parent_id'],
            'agisos' => ['id', 'payload'],
            'jobs' => ['id', 'payload'],
            'admin_refresh_tokens' => ['id', 'token'],
            'user_refresh_tokens' => ['id', 'token'],
        ];
    }

    /** @return array<string, list<array<string, int|string>>> */
    public function activeOnlyRows(): array
    {
        return [
            'activity_logs' => [['id' => 1, 'user_id' => 999, 'message' => 'deleted user retained']],
            'easy_logs' => [['id' => 2, 'user_id' => 999, 'message' => 'easy retained']],
            'cloud_deploy_logs' => [['id' => 3, 'user_id' => 999, 'message' => 'cloud retained']],
            'jobs' => [['id' => 4, 'payload' => 'discard']],
            'admin_refresh_tokens' => [['id' => 5, 'token' => 'invalidate-admin']],
            'user_refresh_tokens' => [['id' => 6, 'token' => 'invalidate-user']],
        ];
    }
}
