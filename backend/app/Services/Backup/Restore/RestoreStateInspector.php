<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use Illuminate\Support\Facades\DB;

final class RestoreStateInspector
{
    /** @return array<string, mixed> */
    public function inspect(RestoreContext $context): array
    {
        $connectionName = (string) config('database.default');
        $database = (string) config("database.connections.$connectionName.database");
        $connection = DB::connection($connectionName);
        $tableRows = $connection->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database],
        );
        $foreignKeyRows = $connection->select(
            'SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME,
 kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, kcu.ORDINAL_POSITION,
 rc.UPDATE_RULE, rc.DELETE_RULE
 FROM information_schema.KEY_COLUMN_USAGE kcu
 JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
 ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
 AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
 ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [$database],
        );

        $tables = array_values(array_map(
            fn (object $row): string => (string) $row->TABLE_NAME,
            $tableRows,
        ));
        $foreignKeys = $this->groupForeignKeys($foreignKeyRows);

        return $this->classify($context, $tables, $foreignKeys);
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, mixed>>  $foreignKeys
     * @return array<string, mixed>
     */
    private function classify(RestoreContext $context, array $tables, array $foreignKeys): array
    {
        $expected = $context->swapTables();
        $recognized = ['rst' => [], 'old' => []];
        $malformed = [];
        $tokens = [];

        foreach ($tables as $table) {
            if (preg_match('/^__(rst|old)_([a-f0-9]{12})_([A-Za-z_][A-Za-z0-9_]*)$/D', $table, $matches) === 1) {
                $kind = $matches[1];
                $token = $matches[2];
                $recognized[$kind][$token][] = $matches[3];
                $tokens[$token] = true;

                continue;
            }
            if (str_starts_with($table, '__rst_') || str_starts_with($table, '__old_')) {
                $malformed[] = $table;
            }
        }

        $tokenList = array_keys($tokens);
        sort($tokenList);
        $token = count($tokenList) === 1 ? $tokenList[0] : null;
        $shadowSuffixes = $token === null ? [] : array_values(array_unique($recognized['rst'][$token] ?? []));
        $oldSuffixes = $token === null ? [] : array_values(array_unique($recognized['old'][$token] ?? []));
        sort($shadowSuffixes);
        sort($oldSuffixes);

        $missingShadow = $token === null || $shadowSuffixes === []
            ? []
            : array_values(array_diff($expected, $shadowSuffixes));
        $unexpectedShadow = array_values(array_diff($shadowSuffixes, $expected));
        $missingOld = $token === null || $oldSuffixes === []
            ? []
            : array_values(array_diff($expected, $oldSuffixes));
        $unexpectedOld = array_values(array_diff($oldSuffixes, $expected));
        $missingActive = array_values(array_diff($expected, $tables));

        $activeForeignKeys = array_filter(
            $foreignKeys,
            fn (array $foreignKey): bool => in_array($foreignKey['table'], $expected, true),
        );
        $shadowForeignKeys = $token === null ? [] : array_filter(
            $foreignKeys,
            fn (array $foreignKey): bool => str_starts_with(
                (string) $foreignKey['table'],
                "__rst_{$token}_",
            ),
        );
        [$missingShadowForeignKeys, $unexpectedShadowForeignKeys] = $this->shadowForeignKeyDifferences(
            $context,
            $shadowForeignKeys,
            $token,
        );
        $shadowForeignKeysReady = $missingShadowForeignKeys === [] && $unexpectedShadowForeignKeys === [];

        $namespaceCount = count($malformed);
        foreach (['rst', 'old'] as $kind) {
            foreach ($recognized[$kind] as $suffixes) {
                $namespaceCount += count($suffixes);
            }
        }
        $ambiguous = $malformed !== []
            || count($tokenList) > 1
            || $unexpectedShadow !== []
            || $unexpectedOld !== []
            || ($shadowSuffixes !== [] && $missingShadow !== [])
            || ($oldSuffixes !== [] && $missingOld !== [])
            || ($shadowSuffixes !== [] && $oldSuffixes !== [])
            || (($shadowSuffixes !== [] || $oldSuffixes !== []) && $missingActive !== []);

        $state = match (true) {
            $namespaceCount === 0 => RestoreState::Clean,
            $ambiguous => RestoreState::BrokenOldSet,
            $oldSuffixes !== [] => RestoreState::ActiveWithOld,
            $activeForeignKeys !== [] => RestoreState::Staged,
            $shadowForeignKeysReady => RestoreState::ShadowForeignKeysReady,
            default => RestoreState::ActiveForeignKeysRemoved,
        };

        return [
            'state' => $state->value,
            'tokens' => $tokenList,
            'shadow_tables' => $this->namespaceTables('rst', $recognized['rst']),
            'old_tables' => $this->namespaceTables('old', $recognized['old']),
            'missing_active_suffixes' => $missingActive,
            'missing_shadow_suffixes' => $missingShadow,
            'unexpected_shadow_suffixes' => $unexpectedShadow,
            'missing_old_suffixes' => $missingOld,
            'unexpected_old_suffixes' => $unexpectedOld,
            'malformed_namespace_tables' => $malformed,
            'active_foreign_keys' => $activeForeignKeys,
            'shadow_foreign_keys' => $shadowForeignKeys,
            'desired_shadow_foreign_keys_ready' => $shadowForeignKeysReady,
            'missing_shadow_foreign_keys' => $missingShadowForeignKeys,
            'unexpected_shadow_foreign_keys' => $unexpectedShadowForeignKeys,
        ];
    }

    /**
     * @param  array<string, list<string>>  $byToken
     * @return list<string>
     */
    private function namespaceTables(string $kind, array $byToken): array
    {
        $tables = [];
        foreach ($byToken as $token => $suffixes) {
            foreach ($suffixes as $suffix) {
                $tables[] = "__{$kind}_{$token}_{$suffix}";
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function groupForeignKeys(array $rows): array
    {
        $foreignKeys = [];
        foreach ($rows as $row) {
            $name = (string) $row->CONSTRAINT_NAME;
            if (! isset($foreignKeys[$name])) {
                $foreignKeys[$name] = [
                    'name' => $name,
                    'table' => (string) $row->TABLE_NAME,
                    'columns' => [],
                    'references' => [
                        'table' => (string) $row->REFERENCED_TABLE_NAME,
                        'columns' => [],
                    ],
                    'on_update' => strtoupper((string) $row->UPDATE_RULE),
                    'on_delete' => strtoupper((string) $row->DELETE_RULE),
                ];
            }
            $foreignKeys[$name]['columns'][] = (string) $row->COLUMN_NAME;
            $foreignKeys[$name]['references']['columns'][] = (string) $row->REFERENCED_COLUMN_NAME;
        }

        return $foreignKeys;
    }

    /**
     * @param  array<string, array<string, mixed>>  $shadowForeignKeys
     * @return array{list<string>, list<string>}
     */
    private function shadowForeignKeyDifferences(
        RestoreContext $context,
        array $shadowForeignKeys,
        ?string $actualToken,
    ): array {
        $missing = [];
        foreach ($context->desiredForeignKeys as $name => $desired) {
            if (! $this->shadowForeignKeyMatches(
                $shadowForeignKeys[$name] ?? null,
                $desired,
                $actualToken,
            )) {
                $missing[] = (string) $name;
            }
        }

        $unexpected = [];
        foreach ($shadowForeignKeys as $name => $actual) {
            $desired = $context->desiredForeignKeys[$name] ?? null;
            if (! is_array($desired) || ! $this->shadowForeignKeyMatches($actual, $desired, $actualToken)) {
                $unexpected[] = (string) $name;
            }
        }

        return [$missing, $unexpected];
    }

    private function shadowForeignKeyMatches(mixed $actual, array $desired, ?string $actualToken): bool
    {
        $owner = $actualToken === null ? null : "__rst_{$actualToken}_{$desired['table']}";
        $referenced = $actualToken === null
            ? null
            : "__rst_{$actualToken}_{$desired['references']['table']}";

        return is_array($actual)
            && $actual['table'] === $owner
            && $actual['columns'] === $desired['columns']
            && $actual['references']['table'] === $referenced
            && $actual['references']['columns'] === $desired['references']['columns']
            && $this->canonicalReferentialAction((string) $actual['on_delete'])
                === $this->canonicalReferentialAction((string) $desired['on_delete'])
            && $this->canonicalReferentialAction((string) $actual['on_update'])
                === $this->canonicalReferentialAction((string) $desired['on_update']);
    }

    private function canonicalReferentialAction(string $action): string
    {
        $action = strtoupper($action);

        return $action === 'RESTRICT' ? 'NO ACTION' : $action;
    }
}
