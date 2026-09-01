<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

final class SqlDumpRewriter implements SqlStreamTransformer
{
    private const MAX_TOKEN_BYTES = 16384;

    private const MAX_CREATE_CLAUSE_BYTES = 1048576;

    /** @var array<string, string> */
    private const SET_NORMALIZATIONS = [
        '40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0' => 'SET FOREIGN_KEY_CHECKS=0 ',
        '40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0' => 'SET UNIQUE_CHECKS=0 ',
        '40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS' => '',
        '40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS' => '',
        '40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT' => '',
        '40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS' => '',
        '40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION' => '',
        "40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" => "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' ",
        '40101 SET @saved_cs_client = @@character_set_client' => '',
        '40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT' => '',
        '40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS' => '',
        '40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION' => '',
        '40101 SET NAMES utf8mb4' => 'SET NAMES utf8mb4 ',
        '40101 SET SQL_MODE=@OLD_SQL_MODE' => '',
        '40101 SET character_set_client = @saved_cs_client' => '',
        '40101 SET character_set_client = utf8' => 'SET character_set_client=utf8 ',
        '40101 SET character_set_client = utf8mb4' => 'SET character_set_client=utf8mb4 ',
        '40103 SET @OLD_TIME_ZONE=@@TIME_ZONE' => '',
        "40103 SET TIME_ZONE='+00:00'" => "SET TIME_ZONE='+00:00' ",
        '40103 SET TIME_ZONE=@OLD_TIME_ZONE' => '',
        '40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0' => 'SET SQL_NOTES=0 ',
        '40111 SET SQL_NOTES=@OLD_SQL_NOTES' => '',
        '50503 SET NAMES utf8mb4' => 'SET NAMES utf8mb4 ',
        '50503 SET character_set_client = utf8mb4' => 'SET character_set_client=utf8mb4 ',
    ];

    private string $input = '';

    private string $state = 'between';

    private string $token = '';

    private string $identifier = '';

    private string $identifierContext = '';

    private ?string $currentTable = null;

    private bool $inVersionComment = false;

    private string $versionCode = '';

    private bool $finishing = false;

    /** @var list<string> */
    private array $insertColumns = [];

    /** @var list<bool> */
    private array $insertKeep = [];

    /** @var array<string, true> */
    private array $seenInsertColumns = [];

    /** @var array<string, bool> */
    private array $insertColumnRules = [];

    /** @var array<int, string> */
    private array $fastTuplePatterns = [];

    private int $insertKeptColumns = 0;

    private int $tupleField = 0;

    private bool $tupleFieldOutputStarted = false;

    private int $tupleKeptFields = 0;

    private string $literalState = 'start';

    private string $literalToken = '';

    private int $literalDigits = 0;

    private string $createPrefix = '';

    private string $createPrefixWord = '';

    private bool $createClauseKeep = true;

    private int $createDepth = 0;

    private int $createKeptClauses = 0;

    private string $lex = 'normal';

    private bool $escaped = false;

    private string $controlKeyword = '';

    private string $controlBuffer = '';

    /** @var array<string, true> */
    private array $encounteredTables = [];

    /** @var array<string, string> */
    private array $tableMap;

    /** @var array<string, list<string>> */
    private array $columnOrder;

    /** @var array<string, list<string>> */
    private array $generatedColumns;

    /** @var list<string> */
    private array $createClauses = [];

    private string $createClauseCapture = '';

    /** @var array<string, array<string, mixed>> */
    private array $inferredTables = [];

    public function __construct(private readonly SqlDumpRewritePlan $plan)
    {
        $this->tableMap = $plan->tableMap;
        $this->columnOrder = $plan->columnOrder;
        $this->generatedColumns = $plan->generatedColumns;
    }

    public function push(string $chunk): string
    {
        $this->input .= $chunk;

        return $this->drain();
    }

    public function finish(): string
    {
        $this->finishing = true;
        $output = $this->drain();

        if ($this->input !== '' || ! in_array($this->state, ['between', 'line_comment'], true)) {
            throw new RuntimeException('SQL 输入在不完整的语句或词法结构中结束');
        }

        $this->state = 'between';

        return $output;
    }

    public function currentTable(): ?string
    {
        return $this->currentTable;
    }

    /** @return list<string> */
    public function encounteredTables(): array
    {
        return array_keys($this->encounteredTables);
    }

    /** @return array{tables:array<string, array<string, mixed>>} */
    public function inferredSchema(): array
    {
        if (! $this->plan->inferSchema) {
            throw new RuntimeException('当前 SQL 改写器未启用 Schema 推导');
        }
        if (! in_array($this->state, ['between', 'line_comment'], true) || ! $this->finishing) {
            throw new RuntimeException('必须完成 SQL 流扫描后才能读取推导 Schema');
        }
        if ($this->inferredTables === []) {
            throw new RuntimeException('备份 SQL 未包含可恢复的 CREATE TABLE');
        }

        return ['tables' => $this->inferredTables];
    }

    private function drain(): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($this->input);

        while ($offset < $length) {
            $result = $this->consume($offset, $length);
            if ($result === null) {
                break;
            }

            [$consumed, $emitted] = $result;
            $offset += $consumed;
            $output .= $emitted;
        }

        $this->input = (string) substr($this->input, $offset);

        return $output;
    }

    /** @return array{int, string}|null */
    private function consume(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];

        return match ($this->state) {
            'between' => $this->consumeBetween($offset, $length),
            'statement_keyword' => $this->consumeStatementKeyword($char),
            'line_comment' => $this->consumeLineComment($char),
            'block_comment' => $this->consumeBlockComment($offset, $length),
            'version_prefix' => $this->consumeVersionPrefix($char),
            'version_after' => $this->consumeVersionAfter($char),
            'insert_into' => $this->consumeExpectedWord($char, 'INTO', 'insert_table'),
            'insert_table' => $this->consumeTableStart($char, 'insert'),
            'insert_after_table' => $this->consumeInsertAfterTable($char),
            'insert_values_keyword' => $this->consumeInsertValuesKeyword($char, false),
            'insert_values_keyword_implicit' => $this->consumeInsertValuesKeyword($char, true),
            'insert_column_start' => $this->consumeInsertColumnStart($char),
            'insert_column_backtick' => $this->consumeBacktick($offset, $length),
            'insert_column_after' => $this->consumeInsertColumnAfter($char),
            'insert_values_wait' => $this->consumeInsertValuesWait($offset),
            'insert_tuple' => $this->consumeInsertTuple($offset, $length),
            'insert_after_tuple' => $this->consumeInsertAfterTuple($offset, $length),
            'create_table_word' => $this->consumeExpectedWord($char, 'TABLE', 'create_table'),
            'create_table' => $this->consumeTableStart($char, 'create'),
            'create_before_open' => $this->consumeCreateBeforeOpen($char),
            'create_clause_start' => $this->consumeCreateClauseStart($char),
            'create_clause_word' => $this->consumeCreateClauseWord($char),
            'create_clause_backtick', 'create_constraint_backtick' => $this->consumeBacktick($offset, $length),
            'create_constraint_scan' => $this->consumeCreateConstraintScan($char),
            'create_constraint_word' => $this->consumeCreateConstraintWord($char),
            'create_clause_body' => $this->consumeCreateClauseBody($offset, $length),
            'create_tail' => $this->consumeCreateTail($offset, $length),
            'control' => $this->consumeControl($offset, $length),
            'table_backtick' => $this->consumeBacktick($offset, $length),
            default => throw new RuntimeException("未知 SQL 改写状态: $this->state"),
        };
    }

    /** @return array{int, string}|null */
    private function consumeBetween(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($char === '#') {
            $this->state = 'line_comment';

            return [1, '#'];
        }
        if ($char === '-') {
            if ($offset + 2 >= $length && ! $this->finishing) {
                return null;
            }
            if (substr($this->input, $offset, 2) === '--'
                && ($offset + 2 >= $length || $this->isWhitespace($this->input[$offset + 2]))) {
                $this->state = 'line_comment';

                return [2, '--'];
            }
            throw new RuntimeException('不允许的 SQL 起始符号');
        }
        if ($char === '/') {
            if ($offset + 1 >= $length && ! $this->finishing) {
                return null;
            }
            if (substr($this->input, $offset, 2) !== '/*') {
                throw new RuntimeException('不允许的 SQL 起始符号');
            }
            if ($offset + 2 >= $length && ! $this->finishing) {
                return null;
            }
            if (($this->input[$offset + 2] ?? '') === '!') {
                $this->inVersionComment = true;
                $this->versionCode = '';
                $this->state = 'version_prefix';

                return [3, '/*!'];
            }
            $this->state = 'block_comment';

            return [2, '/*'];
        }
        if ($this->isWordStart($char)) {
            $this->token = $char;
            $this->state = 'statement_keyword';

            return [1, ''];
        }

        throw new RuntimeException('不允许或无法识别的 SQL 语句');
    }

    /** @return array{int, string} */
    private function consumeStatementKeyword(string $char): array
    {
        if ($this->isWordChar($char)) {
            $this->appendToken($char);

            return [1, ''];
        }

        $keyword = strtoupper($this->token);
        if ($this->inVersionComment && ! in_array($keyword, ['ALTER', 'SET'], true)) {
            throw new RuntimeException('版本条件注释只允许受控 SET 或 ALTER 语句');
        }
        $emitted = $this->token;
        $this->token = '';
        $this->state = match ($keyword) {
            'INSERT' => 'insert_into',
            'CREATE' => 'create_table_word',
            'DROP', 'ALTER', 'LOCK', 'UNLOCK', 'SET' => $this->beginControl($keyword),
            'USE', 'GRANT', 'SELECT', 'REPLACE', 'UPDATE', 'DELETE', 'CALL' => throw new RuntimeException("SQL 语句不在恢复白名单中: $keyword"),
            default => throw new RuntimeException("无法识别的 SQL 语句: $keyword"),
        };

        return [0, $this->state === 'control' ? '' : $emitted];
    }

    /** @return array{int, string} */
    private function consumeLineComment(string $char): array
    {
        if ($char === "\n") {
            $this->state = 'between';
        }

        return [1, $char];
    }

    /** @return array{int, string}|null */
    private function consumeBlockComment(int $offset, int $length): ?array
    {
        if ($this->input[$offset] !== '*') {
            return [1, $this->input[$offset]];
        }
        if ($offset + 1 >= $length && ! $this->finishing) {
            return null;
        }
        if (substr($this->input, $offset, 2) === '*/') {
            $this->state = 'between';

            return [2, '*/'];
        }

        return [1, '*'];
    }

    /** @return array{int, string} */
    private function consumeVersionPrefix(string $char): array
    {
        if ($this->isWhitespace($char) || ($char >= '0' && $char <= '9')) {
            $this->versionCode .= $char;
            if (strlen($this->versionCode) > 16) {
                throw new RuntimeException('版本条件注释前缀超过安全上限');
            }

            return [1, $char];
        }
        if ($this->isWordStart($char)) {
            if (preg_match('/^[0-9]{5}\s+$/D', $this->versionCode) !== 1) {
                throw new RuntimeException('版本条件注释前缀格式无效');
            }
            $this->versionCode = substr($this->versionCode, 0, 5);
            $this->token = $char;
            $this->state = 'statement_keyword';

            return [1, ''];
        }

        throw new RuntimeException('版本条件注释不包含受支持的 SQL 语句');
    }

    /** @return array{int, string} */
    private function consumeVersionAfter(string $char): array
    {
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($char !== ';') {
            throw new RuntimeException('版本条件注释后缺少语句终止符');
        }
        $this->state = 'between';

        return [1, ';'];
    }

    /** @return array{int, string} */
    private function consumeExpectedWord(string $char, string $expected, string $nextState): array
    {
        if ($this->token === '' && $this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($this->isWordChar($char)) {
            $this->appendToken($char);

            return [1, ''];
        }
        if ($this->token === '' || strtoupper($this->token) !== $expected) {
            $actual = $this->token === '' ? $char : $this->token;
            throw new RuntimeException("SQL 语句需要 {$expected}，实际为 $actual");
        }

        $emitted = $this->token;
        $this->token = '';
        $this->state = $nextState;

        return [0, $emitted];
    }

    /** @return array{int, string} */
    private function consumeTableStart(string $char, string $context): array
    {
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        $this->identifierContext = $context;
        $this->identifier = '';
        if ($char === '`') {
            $this->state = 'table_backtick';

            return [1, ''];
        }
        throw new RuntimeException('受控 mysqldump 的表名必须使用反引号');
    }

    /** @return array{int, string}|null */
    private function consumeBacktick(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];
        if ($char !== '`') {
            $this->appendIdentifier($char);

            return [1, ''];
        }
        if ($offset + 1 >= $length && ! $this->finishing) {
            return null;
        }
        if (($this->input[$offset + 1] ?? '') === '`') {
            $this->appendIdentifier('`');

            return [2, ''];
        }

        if ($this->state === 'insert_column_backtick') {
            return [1, $this->finishInsertColumn()];
        }
        if ($this->state === 'create_clause_backtick') {
            $this->createPrefix .= $this->quoteIdentifier($this->identifier);
            $this->identifier = '';

            return [1, $this->decideCreateClause(true)];
        }
        if ($this->state === 'create_constraint_backtick') {
            $this->createPrefix .= $this->quoteIdentifier($this->identifier);
            $this->identifier = '';
            $this->state = 'create_constraint_scan';

            return [1, ''];
        }

        return [1, $this->finishTableIdentifier()];
    }

    private function finishTableIdentifier(): string
    {
        $source = $this->identifier;
        $mapped = $this->tableMap[$source] ?? null;
        if ((! is_string($mapped) || $mapped === '') && $this->plan->inferSchema
            && $this->identifierContext === 'create') {
            $this->assertInferredIdentifier($source);
            $mapped = $source;
            $this->tableMap[$source] = $source;
        }
        if (! is_string($mapped) || $mapped === '') {
            throw new RuntimeException("备份包含未知表: $source");
        }

        $this->encounteredTables[$source] = true;
        $this->currentTable = $source;
        $context = $this->identifierContext;
        $this->identifier = '';
        $this->identifierContext = '';
        $this->state = match ($context) {
            'insert' => 'insert_after_table',
            'create' => 'create_before_open',
            default => throw new RuntimeException('未知表名上下文'),
        };

        return $this->quoteIdentifier($mapped);
    }

    /** @return array{int, string} */
    private function consumeInsertAfterTable(string $char): array
    {
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($char === '(') {
            $this->insertColumns = [];
            $this->insertKeep = [];
            $this->seenInsertColumns = [];
            $this->insertKeptColumns = 0;
            $this->prepareInsertColumnRules();
            $this->state = 'insert_column_start';

            return [1, '('];
        }
        if ($char === '.') {
            throw new RuntimeException('不允许库限定表名');
        }
        if ($this->isWordStart($char)) {
            $this->token = $char;
            $this->state = 'insert_values_keyword_implicit';

            return [1, ''];
        }

        throw new RuntimeException('INSERT 表名后只允许列清单或 VALUES');
    }

    /** @return array{int, string} */
    private function consumeInsertColumnStart(string $char): array
    {
        if ($this->isWhitespace($char)) {
            return [1, ''];
        }
        if ($char !== '`') {
            throw new RuntimeException('INSERT 列清单必须使用反引号标识符');
        }
        $this->identifier = '';
        $this->state = 'insert_column_backtick';

        return [1, ''];
    }

    private function finishInsertColumn(): string
    {
        $table = $this->requireCurrentTable();
        if (isset($this->seenInsertColumns[$this->identifier])) {
            throw new RuntimeException("INSERT 列清单重复: $table.$this->identifier");
        }
        if (count($this->insertColumns) >= count($this->insertColumnRules)) {
            throw new RuntimeException("INSERT 列清单超过 Schema 列数: $table");
        }
        if (! array_key_exists($this->identifier, $this->insertColumnRules)) {
            throw new RuntimeException("INSERT 包含未知列: $table.$this->identifier");
        }
        $column = $this->identifier;
        $this->seenInsertColumns[$column] = true;
        $keep = $this->insertColumnRules[$column];
        $this->insertColumns[] = $column;
        $this->insertKeep[] = $keep;
        $this->identifier = '';
        $this->state = 'insert_column_after';

        if (! $keep) {
            return '';
        }

        $delimiter = $this->insertKeptColumns > 0 ? ',' : '';
        $this->insertKeptColumns++;

        return $delimiter.$this->quoteIdentifier($column);
    }

    /** @return array{int, string} */
    private function consumeInsertColumnAfter(string $char): array
    {
        if ($this->isWhitespace($char)) {
            return [1, ''];
        }
        if ($char === ',') {
            $this->state = 'insert_column_start';

            return [1, ''];
        }
        if ($char === ')') {
            if ($this->insertKeptColumns === 0) {
                throw new RuntimeException('INSERT 删除生成列后没有可写列');
            }
            $this->state = 'insert_values_keyword';

            return [1, ')'];
        }

        throw new RuntimeException('INSERT 列清单格式无效');
    }

    /** @return array{int, string} */
    private function consumeInsertValuesKeyword(string $char, bool $implicit): array
    {
        if ($this->token === '' && $this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($this->isWordChar($char)) {
            $this->appendToken($char);

            return [1, ''];
        }
        if (strtoupper($this->token) !== 'VALUES') {
            throw new RuntimeException('INSERT 只允许 VALUES 载荷');
        }

        $emitted = '';
        if ($implicit) {
            $this->prepareImplicitInsertColumns();
            $emitted = $this->keptColumnList().' ';
        }
        $emitted .= $this->token;
        $this->token = '';
        $this->state = 'insert_values_wait';

        return [0, $emitted];
    }

    private function prepareImplicitInsertColumns(): void
    {
        $table = $this->requireCurrentTable();
        $columns = $this->columnOrder[$table] ?? [];
        if ($columns === []) {
            throw new RuntimeException("备份 Schema 缺少表列序: $table");
        }
        $this->prepareInsertColumnRules();
        $this->insertColumns = $columns;
        $this->insertKeep = array_map(fn (string $column): bool => $this->insertColumnRules[$column], $columns);
        if (! in_array(true, $this->insertKeep, true)) {
            throw new RuntimeException('INSERT 删除生成列后没有可写列');
        }
    }

    private function prepareInsertColumnRules(): void
    {
        $table = $this->requireCurrentTable();
        $generated = array_fill_keys($this->generatedColumns[$table] ?? [], true);
        $this->insertColumnRules = [];
        foreach ($this->columnOrder[$table] ?? [] as $column) {
            $this->insertColumnRules[$column] = ! isset($generated[$column]);
        }
    }

    private function keptColumnList(): string
    {
        $columns = [];
        foreach ($this->insertColumns as $index => $column) {
            if ($this->insertKeep[$index]) {
                $columns[] = $this->quoteIdentifier($column);
            }
        }

        return '('.implode(',', $columns).')';
    }

    /** @return array{int, string} */
    private function consumeInsertValuesWait(int $offset): array
    {
        $char = $this->input[$offset];
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($char !== '(') {
            throw new RuntimeException('INSERT VALUES 后缺少 tuple');
        }

        $fast = $this->tryFastTuple($offset);
        if ($fast !== null) {
            return $fast;
        }

        $this->beginInsertTuple();

        return [1, '('];
    }

    /** @return array{int, string}|null */
    private function tryFastTuple(int $offset): ?array
    {
        $fieldCount = count($this->insertColumns);
        if ($fieldCount === 0) {
            return null;
        }
        $pattern = $this->fastTuplePatterns[$fieldCount] ??= $this->fastTuplePattern($fieldCount);
        $matched = preg_match($pattern, $this->input, $matches, PREG_OFFSET_CAPTURE, $offset);
        if ($matched !== 1 || ! isset($matches[0][0])) {
            return null;
        }

        $outputFields = [];
        for ($index = 0; $index < $fieldCount; $index++) {
            $field = $matches[$index + 1][0] ?? null;
            if (! is_string($field)) {
                return null;
            }
            $trimmed = trim($field);
            if ($trimmed !== ''
                && ! in_array($trimmed[0], ["'", '"'], true)
                && strlen($trimmed) > self::MAX_TOKEN_BYTES) {
                return null;
            }
            if ($this->insertKeep[$index]) {
                $outputFields[] = $field;
            }
        }
        if ($outputFields === []) {
            throw new RuntimeException('INSERT 删除生成列后没有可写列');
        }

        $this->state = 'insert_after_tuple';

        return [strlen($matches[0][0]), '('.implode(',', $outputFields).')'];
    }

    private function fastTuplePattern(int $fieldCount): string
    {
        $space = '[ \t\r\n\f]*';
        $number = '[+-]?(?:(?:[0-9]+(?:\.[0-9]*)?)|(?:\.[0-9]+))(?:[eE][+-]?[0-9]+)?';
        $hex = '0[xX][0-9A-Fa-f]+';
        $quotedHex = '[xX]\'(?:[0-9A-Fa-f]{2})+\'';
        $quotedBit = '[bB]\'[01]+\'';
        $single = '\'(?:\\\\.|\'\'|[^\'\\\\])*\'';
        $double = '"(?:\\\\.|""|[^"\\\\])*"';
        $literal = '(?:NULL|'.$hex.'|'.$quotedHex.'|'.$quotedBit.'|'.$number.'|'.$single.'|'.$double.')';
        $field = '('.$space.$literal.$space.')';

        return '~\\G\\('.implode(',', array_fill(0, $fieldCount, $field)).'\\)~AD';
    }

    private function beginInsertTuple(): void
    {
        $this->tupleField = 0;
        $this->tupleFieldOutputStarted = false;
        $this->tupleKeptFields = 0;
        $this->resetLiteral();
        $this->lex = 'normal';
        $this->escaped = false;
        $this->state = 'insert_tuple';
    }

    /** @return array{int, string}|null */
    private function consumeInsertTuple(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];
        $keep = $this->insertKeep[$this->tupleField] ?? null;
        if ($keep === null) {
            throw new RuntimeException('INSERT tuple 字段数多于列清单');
        }

        if ($this->lex !== 'normal') {
            $result = $this->consumeQuoted(
                $offset,
                $length,
                $this->lex,
                $this->escaped,
                (bool) $keep,
            );
            if ($result !== null && $this->lex === 'normal') {
                $this->literalState = 'after';
            }

            return $result;
        }

        if ($this->literalState === 'start') {
            if ($this->isWhitespace($char)) {
                $run = strspn($this->input, " \t\n\r\f", $offset);

                return [$run, $this->emitTupleField(substr($this->input, $offset, $run), (bool) $keep)];
            }
            if ($char === "'" || $char === '"') {
                $lex = $char === "'" ? 'single' : 'double';
                $specialOffset = $offset + 1 + strcspn($this->input, '\\'.$char, $offset + 1);
                if ($specialOffset < $length && $this->input[$specialOffset] === $char) {
                    if ($specialOffset + 1 < $length && $this->input[$specialOffset + 1] !== $char) {
                        $this->literalState = 'after';
                        $run = $specialOffset - $offset + 1;

                        return [$run, $this->emitTupleField(
                            substr($this->input, $offset, $run),
                            (bool) $keep,
                        )];
                    }
                    if ($specialOffset + 1 >= $length && $this->finishing) {
                        $this->literalState = 'after';
                        $run = $specialOffset - $offset + 1;

                        return [$run, $this->emitTupleField(
                            substr($this->input, $offset, $run),
                            (bool) $keep,
                        )];
                    }
                }
                $this->lex = $lex;
                $run = max(1, $specialOffset - $offset);

                return [$run, $this->emitTupleField(
                    substr($this->input, $offset, $run),
                    (bool) $keep,
                )];
            }
            if ($char === 'X' || $char === 'x' || $char === 'B' || $char === 'b') {
                $this->literalState = 'prefixed';
                $this->literalToken = $char;

                return [1, $this->emitTupleField($char, (bool) $keep)];
            }
            if (($char >= '0' && $char <= '9') || $char === '+' || $char === '-' || $char === '.') {
                $this->literalState = 'number';
                $run = strspn($this->input, '0123456789+-.eE', $offset);
                $text = substr($this->input, $offset, $run);
                $this->appendLiteralText($text);

                return [$run, $this->emitTupleField($text, (bool) $keep)];
            }
            if ($this->isWordStart($char)) {
                $this->literalState = 'word';
                $run = strspn(
                    $this->input,
                    'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_',
                    $offset,
                );
                $text = substr($this->input, $offset, $run);
                $this->appendLiteralText($text);

                return [$run, $this->emitTupleField($text, (bool) $keep)];
            }

            throw new RuntimeException('INSERT literal 起始符号不受支持');
        }

        if ($this->literalState === 'prefixed') {
            if ($char !== "'") {
                throw new RuntimeException('INSERT literal 的 X/b 前缀后必须紧跟单引号');
            }
            $this->literalState = strtolower($this->literalToken) === 'x' ? 'quoted_hex' : 'quoted_bit';
            $this->literalToken = '';
            $this->literalDigits = 0;

            return [1, $this->emitTupleField("'", (bool) $keep)];
        }

        if ($this->literalState === 'quoted_hex' || $this->literalState === 'quoted_bit') {
            if ($char === "'") {
                if ($this->literalDigits === 0
                    || ($this->literalState === 'quoted_hex' && $this->literalDigits % 2 !== 0)) {
                    throw new RuntimeException('INSERT literal 的引号二进制长度无效');
                }
                $this->literalState = 'after';

                return [1, $this->emitTupleField("'", (bool) $keep)];
            }
            $valid = $this->literalState === 'quoted_hex'
                ? $this->isHexDigit($char)
                : $char === '0' || $char === '1';
            if (! $valid) {
                throw new RuntimeException('INSERT literal 包含无效的引号二进制字符');
            }
            $mask = $this->literalState === 'quoted_hex' ? '0123456789abcdefABCDEF' : '01';
            $run = strspn($this->input, $mask, $offset);
            $this->literalDigits += $run;

            return [$run, $this->emitTupleField(substr($this->input, $offset, $run), (bool) $keep)];
        }

        if ($this->literalState === 'hex') {
            if ($this->isHexDigit($char)) {
                $run = strspn($this->input, '0123456789abcdefABCDEF', $offset);
                $this->literalDigits += $run;

                return [$run, $this->emitTupleField(substr($this->input, $offset, $run), (bool) $keep)];
            }

            return $this->finishUnquotedLiteral($offset, $length);
        }

        if ($this->literalState === 'number') {
            if ($this->literalToken === '0' && ($char === 'x' || $char === 'X')) {
                $this->literalState = 'hex';
                $this->literalToken = '';
                $this->literalDigits = 0;

                return [1, $this->emitTupleField($char, (bool) $keep)];
            }
            if (($char >= '0' && $char <= '9') || in_array($char, ['+', '-', '.', 'e', 'E'], true)) {
                $run = strspn($this->input, '0123456789+-.eE', $offset);
                $text = substr($this->input, $offset, $run);
                $this->appendLiteralText($text);

                return [$run, $this->emitTupleField($text, (bool) $keep)];
            }

            return $this->finishUnquotedLiteral($offset, $length);
        }

        if ($this->literalState === 'word') {
            if ($this->isWordChar($char)) {
                $run = strspn(
                    $this->input,
                    'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_',
                    $offset,
                );
                $text = substr($this->input, $offset, $run);
                $this->appendLiteralText($text);

                return [$run, $this->emitTupleField($text, (bool) $keep)];
            }

            return $this->finishUnquotedLiteral($offset, $length);
        }

        if ($this->literalState === 'after') {
            if ($this->isWhitespace($char)) {
                $run = strspn($this->input, " \t\n\r\f", $offset);

                return [$run, $this->emitTupleField(substr($this->input, $offset, $run), (bool) $keep)];
            }

            return $this->consumeTupleDelimiter($char);
        }

        throw new RuntimeException('INSERT literal 词法状态无效');
    }

    private function emitTupleField(string $text, bool $keep): string
    {
        if (! $keep) {
            return '';
        }
        $prefix = '';
        if (! $this->tupleFieldOutputStarted) {
            $prefix = $this->tupleKeptFields > 0 ? ',' : '';
            $this->tupleFieldOutputStarted = true;
            $this->tupleKeptFields++;
        }

        return $prefix.$text;
    }

    /** @return array{int, string} */
    private function finishUnquotedLiteral(int $offset, int $length): array
    {
        if ($this->literalState === 'word' && strtoupper($this->literalToken) !== 'NULL') {
            throw new RuntimeException("INSERT literal 不允许 token: $this->literalToken");
        }
        if ($this->literalState === 'number'
            && preg_match('/^[+-]?(?:(?:[0-9]+(?:\.[0-9]*)?)|(?:\.[0-9]+))(?:[eE][+-]?[0-9]+)?$/D', $this->literalToken) !== 1) {
            throw new RuntimeException("INSERT literal 数值格式无效: $this->literalToken");
        }
        if ($this->literalState === 'hex' && $this->literalDigits === 0) {
            throw new RuntimeException('INSERT literal 十六进制值为空');
        }

        $this->literalState = 'after';

        return $this->consumeInsertTuple($offset, $length) ?? throw new RuntimeException('INSERT literal 读取中断');
    }

    /** @return array{int, string} */
    private function consumeTupleDelimiter(string $char): array
    {
        if ($char !== ',' && $char !== ')') {
            throw new RuntimeException('INSERT literal 后包含不受支持的表达式或注释');
        }
        if ($char === ',') {
            $this->tupleField++;
            if ($this->tupleField >= count($this->insertColumns)) {
                throw new RuntimeException('INSERT tuple 字段数多于列清单');
            }
            $this->tupleFieldOutputStarted = false;
            $this->resetLiteral();

            return [1, ''];
        }
        if ($this->tupleField + 1 !== count($this->insertColumns)) {
            throw new RuntimeException('INSERT tuple 字段数少于列清单');
        }
        $this->state = 'insert_after_tuple';

        return [1, ')'];
    }

    private function resetLiteral(): void
    {
        $this->literalState = 'start';
        $this->literalToken = '';
        $this->literalDigits = 0;
    }

    private function appendLiteralText(string $text): void
    {
        $this->literalToken .= $text;
        if (strlen($this->literalToken) > self::MAX_TOKEN_BYTES) {
            throw new RuntimeException('INSERT literal token 超过安全上限');
        }
    }

    /** @return array{int, string}|null */
    private function consumeInsertAfterTuple(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($char === ',') {
            if (($this->input[$offset + 1] ?? '') === '(') {
                $this->beginInsertTuple();

                return [2, ',('];
            }
            $this->state = 'insert_values_wait';

            return [1, ','];
        }

        return $this->consumeStatementEnd($offset, $length);
    }

    /** @return array{int, string} */
    private function consumeCreateBeforeOpen(string $char): array
    {
        if ($this->isWhitespace($char)) {
            return [1, $char];
        }
        if ($char === '.') {
            throw new RuntimeException('不允许库限定表名');
        }
        if ($char !== '(') {
            throw new RuntimeException('CREATE TABLE 缺少列定义');
        }
        if ($this->plan->inferSchema) {
            $table = $this->requireCurrentTable();
            if (isset($this->inferredTables[$table])) {
                throw new RuntimeException("备份 SQL 重复定义表: $table");
            }
            $this->createClauses = [];
            $this->createClauseCapture = '';
        }
        $this->createDepth = 1;
        $this->createKeptClauses = 0;
        $this->resetCreatePrefix();
        $this->state = 'create_clause_start';

        return [1, '('];
    }

    /** @return array{int, string} */
    private function consumeCreateClauseStart(string $char): array
    {
        $this->appendCreatePrefix($char);
        if ($this->isWhitespace($char)) {
            return [1, ''];
        }
        if ($char === '`') {
            $this->createPrefix = (string) substr($this->createPrefix, 0, -1);
            $this->identifier = '';
            $this->state = 'create_clause_backtick';

            return [1, ''];
        }
        if ($this->isWordStart($char)) {
            $this->createPrefixWord = $char;
            $this->state = 'create_clause_word';

            return [1, ''];
        }

        throw new RuntimeException('CREATE TABLE 包含无法识别的定义');
    }

    /** @return array{int, string} */
    private function consumeCreateClauseWord(string $char): array
    {
        if ($this->isWordChar($char)) {
            $this->createPrefixWord .= $char;
            $this->appendCreatePrefix($char);

            return [1, ''];
        }

        $word = strtoupper($this->createPrefixWord);
        $this->createPrefixWord = '';
        if ($word === 'CONSTRAINT') {
            $this->state = 'create_constraint_scan';

            return [0, ''];
        }

        $keep = $word !== 'FOREIGN' || ! $this->plan->omitForeignKeys;

        return [0, $this->decideCreateClause($keep)];
    }

    /** @return array{int, string} */
    private function consumeCreateConstraintScan(string $char): array
    {
        $this->appendCreatePrefix($char);
        if ($this->isWhitespace($char)) {
            return [1, ''];
        }
        if ($char === '`') {
            $this->createPrefix = (string) substr($this->createPrefix, 0, -1);
            $this->identifier = '';
            $this->state = 'create_constraint_backtick';

            return [1, ''];
        }
        if ($this->isWordStart($char)) {
            $this->createPrefixWord = $char;
            $this->state = 'create_constraint_word';

            return [1, ''];
        }

        throw new RuntimeException('CREATE TABLE 的 CONSTRAINT 无法安全识别');
    }

    /** @return array{int, string} */
    private function consumeCreateConstraintWord(string $char): array
    {
        if ($this->isWordChar($char)) {
            $this->createPrefixWord .= $char;
            $this->appendCreatePrefix($char);

            return [1, ''];
        }

        $word = strtoupper($this->createPrefixWord);
        $this->createPrefixWord = '';
        if ($word === 'FOREIGN') {
            return [0, $this->decideCreateClause(! $this->plan->omitForeignKeys)];
        }
        if (in_array($word, ['CHECK', 'PRIMARY', 'UNIQUE'], true)) {
            return [0, $this->decideCreateClause(true)];
        }
        $this->state = 'create_constraint_scan';

        return [0, ''];
    }

    private function decideCreateClause(bool $keep): string
    {
        $this->createClauseKeep = $keep;
        $this->token = '';
        $this->lex = 'normal';
        $this->escaped = false;
        $this->state = 'create_clause_body';
        $prefix = $this->createPrefix;
        $this->resetCreatePrefix();
        if ($this->plan->inferSchema) {
            $this->createClauseCapture = $prefix;
        }
        if (! $keep) {
            return '';
        }

        $delimiter = $this->createKeptClauses > 0 ? ',' : '';
        $this->createKeptClauses++;

        return $delimiter.$prefix;
    }

    /** @return array{int, string}|null */
    private function consumeCreateClauseBody(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];
        if ($this->lex !== 'normal') {
            $result = $this->consumeQuoted(
                $offset,
                $length,
                $this->lex,
                $this->escaped,
                $this->createClauseKeep || $this->plan->inferSchema,
            );
            if ($result === null) {
                return null;
            }

            return [$result[0], $this->captureCreateClauseText($result[1])];
        }
        if ($this->token !== '') {
            if ($this->isWordChar($char)) {
                $this->appendToken($char);

                return [1, ''];
            }
            $word = $this->token;
            $this->token = '';
            if ($this->createClauseKeep && strtoupper($word) === 'REFERENCES') {
                throw new RuntimeException('CREATE clause 不允许 active REFERENCES');
            }

            return [0, $this->captureCreateClauseText($word)];
        }
        if ($this->isWordStart($char)) {
            $this->token = $char;

            return [1, ''];
        }
        if (($char === '/' || $char === '-') && $offset + 1 >= $length && ! $this->finishing) {
            return null;
        }
        if (($char === '/' && substr($this->input, $offset, 2) === '/*')
            || $char === '#'
            || ($char === '-' && substr($this->input, $offset, 2) === '--')) {
            throw new RuntimeException('CREATE clause 不允许注释');
        }
        if ($char === ';') {
            throw new RuntimeException('CREATE clause 不允许分号');
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $this->lex = match ($char) {
                "'" => 'single',
                '"' => 'double',
                default => 'backtick',
            };

            return [1, $this->captureCreateClauseText($char)];
        }
        if ($char === '(') {
            $this->createDepth++;

            return [1, $this->captureCreateClauseText('(')];
        }
        if ($char === ')' && $this->createDepth > 1) {
            $this->createDepth--;

            return [1, $this->captureCreateClauseText(')')];
        }
        if ($char === ',' && $this->createDepth === 1) {
            $this->finishCreateClauseCapture();
            $this->resetCreatePrefix();
            $this->state = 'create_clause_start';

            return [1, ''];
        }
        if ($char === ')' && $this->createDepth === 1) {
            if ($this->createKeptClauses === 0) {
                throw new RuntimeException('CREATE TABLE 删除外键后没有定义');
            }
            $this->createDepth = 0;
            $this->finishCreateClauseCapture();
            $this->controlBuffer = '';
            $this->lex = 'normal';
            $this->escaped = false;
            $this->state = 'create_tail';

            return [1, ')'];
        }

        return [1, $this->captureCreateClauseText($char)];
    }

    /** @return array{int, string}|null */
    private function consumeCreateTail(int $offset, int $length): ?array
    {
        $char = $this->input[$offset];
        if ($this->lex !== 'normal') {
            $result = $this->consumeQuoted($offset, $length, $this->lex, $this->escaped, true);
            if ($result !== null) {
                $this->appendControlBuffer($result[1]);
            }

            return $result === null ? null : [$result[0], ''];
        }
        if ($char === "'" || $char === '"') {
            $this->lex = $char === "'" ? 'single' : 'double';
            $this->escaped = false;
            $this->appendControlBuffer($char);

            return [1, ''];
        }
        if ($char === ';') {
            $tail = $this->validateCreateTail();
            if ($this->plan->inferSchema) {
                $this->finishInferredTable($tail);
            }
            [$consumed, $terminator] = $this->completeStatement(1);

            return [$consumed, $tail.$terminator];
        }
        $this->appendControlBuffer($char);

        return [1, ''];
    }

    private function validateCreateTail(): string
    {
        $tail = $this->controlBuffer;
        $this->controlBuffer = '';
        $word = '[A-Za-z0-9_+-]+';
        $string = "(?:'(?:\\\\.|''|[^'\\\\])*'|\"(?:\\\\.|\"\"|[^\"\\\\])*\")";
        $option = '(?:ENGINE\s*=\s*'.$word
            .'|AUTO_INCREMENT\s*=\s*[0-9]+'
            .'|(?:DEFAULT\s+)?(?:CHARSET|COLLATE)\s*=\s*'.$word
            .'|(?:DEFAULT\s+)?CHARACTER\s+SET\s*=\s*'.$word
            .'|ROW_FORMAT\s*=\s*'.$word
            .'|COMMENT\s*=\s*'.$string.')';
        if (preg_match('~\A\s*(?:'.$option.'(?:\s+'.$option.')*)?\s*\z~iD', $tail) !== 1) {
            throw new RuntimeException('CREATE TABLE 尾部只允许受控 mysqldump 表选项');
        }

        return $tail;
    }

    private function beginControl(string $keyword): string
    {
        $this->controlKeyword = $keyword;
        $this->controlBuffer = $keyword;

        return 'control';
    }

    /** @return array{int, string}|null */
    private function consumeControl(int $offset, int $length): ?array
    {
        if ($this->inVersionComment && $this->input[$offset] === ';') {
            throw new RuntimeException('版本条件注释内部不允许分号或第二条语句');
        }

        $end = $this->statementEndLength($offset, $length);
        if ($end > 0) {
            $rewritten = $this->rewriteControlStatement();
            [$consumed, $terminator] = $this->completeStatement($end);

            return [$consumed, $rewritten.$terminator];
        }
        if ($end < 0) {
            return null;
        }

        $this->appendControlBuffer($this->input[$offset]);

        return [1, ''];
    }

    private function appendControlBuffer(string $text): void
    {
        $this->controlBuffer .= $text;
        if (strlen($this->controlBuffer) > self::MAX_TOKEN_BYTES) {
            throw new RuntimeException('受控 SQL 语句超过安全上限');
        }
    }

    private function rewriteControlStatement(): string
    {
        $statement = $this->controlBuffer;
        $keyword = $this->controlKeyword;
        $this->controlBuffer = '';
        $this->controlKeyword = '';

        if (preg_match('~`(?:``|[^`])*`\s*\.~', $statement) === 1) {
            throw new RuntimeException('不允许库限定表名');
        }

        return match ($keyword) {
            'DROP' => $this->rewriteSingleTableControl(
                $statement,
                '~\ADROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?<table>`(?:``|[^`])*`)\s*\z~iD',
                'DROP TABLE 只允许映射表和可选 IF EXISTS',
            ),
            'ALTER' => $this->rewriteSingleTableControl(
                $statement,
                '~\AALTER\s+TABLE\s+(?<table>`(?:``|[^`])*`)\s+(?:ENABLE|DISABLE)\s+KEYS\s*\z~iD',
                'ALTER TABLE 只允许 ENABLE KEYS 或 DISABLE KEYS',
            ),
            'LOCK' => $this->rewriteLockControl($statement),
            'UNLOCK' => preg_match('~\AUNLOCK\s+TABLES\s*\z~iD', $statement) === 1
                ? $statement
                : throw new RuntimeException('UNLOCK TABLES 语法无效'),
            'SET' => $this->rewriteSetControl($statement),
            default => throw new RuntimeException('未知受控 SQL 语句'),
        };
    }

    private function rewriteSingleTableControl(string $statement, string $pattern, string $message): string
    {
        if (preg_match($pattern, $statement, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException($message);
        }
        [$quoted, $offset] = $matches['table'];

        return substr_replace(
            $statement,
            $this->mappedTableIdentifier($quoted),
            $offset,
            strlen($quoted),
        );
    }

    private function rewriteLockControl(string $statement): string
    {
        $identifier = '`(?:``|[^`])*`';
        $item = $identifier.'\s+(?:READ|WRITE)';
        if (preg_match('~\ALOCK\s+TABLES\s+'.$item.'(?:\s*,\s*'.$item.')*\s*\z~iD', $statement) !== 1) {
            throw new RuntimeException('LOCK TABLES 只允许已映射表和 READ/WRITE 模式');
        }

        return preg_replace_callback(
            '~`(?:``|[^`])*`~',
            fn (array $matches): string => $this->mappedTableIdentifier($matches[0]),
            $statement,
        ) ?? throw new RuntimeException('LOCK TABLES 表名改写失败');
    }

    private function rewriteSetControl(string $statement): string
    {
        $canonical = preg_replace('/\s+/', ' ', trim($statement));
        if (! is_string($canonical)) {
            throw new RuntimeException('SET 归一化失败');
        }
        $normalized = self::SET_NORMALIZATIONS[$this->versionCode.' '.$canonical] ?? null;
        if ($normalized === null) {
            throw new RuntimeException(
                'SET 只允许真实 mysqldump 固定语句: '.$this->versionCode.' '.$canonical,
            );
        }

        return $normalized;
    }

    private function mappedTableIdentifier(string $quoted): string
    {
        $source = str_replace('``', '`', substr($quoted, 1, -1));
        $mapped = $this->tableMap[$source] ?? null;
        if ((! is_string($mapped) || $mapped === '') && $this->plan->inferSchema) {
            $this->assertInferredIdentifier($source);
            $mapped = $source;
            $this->tableMap[$source] = $source;
        }
        if (! is_string($mapped) || $mapped === '') {
            throw new RuntimeException("备份包含未知表: $source");
        }

        $this->encounteredTables[$source] = true;

        return $this->quoteIdentifier($mapped);
    }

    /** @return array{int, string}|null */
    private function consumeQuoted(
        int $offset,
        int $length,
        string &$lex,
        bool &$escaped,
        bool $emit,
    ): ?array {
        $char = $this->input[$offset];
        $quote = match ($lex) {
            'single' => "'",
            'double' => '"',
            'backtick' => '`',
            default => throw new RuntimeException('未知引号词法状态'),
        };
        if (! $escaped) {
            $special = $lex === 'backtick' ? '`' : '\\'.$quote;
            $run = strcspn($this->input, $special, $offset);
            if ($run > 0) {
                return [$run, $emit ? substr($this->input, $offset, $run) : ''];
            }
        }
        if ($escaped) {
            $escaped = false;

            return [1, $emit ? $char : ''];
        }
        if ($char === '\\' && $lex !== 'backtick') {
            $escaped = true;

            return [1, $emit ? '\\' : ''];
        }
        if ($char !== $quote) {
            return [1, $emit ? $char : ''];
        }
        if ($offset + 1 >= $length && ! $this->finishing) {
            return null;
        }
        if (($this->input[$offset + 1] ?? '') === $quote) {
            return [2, $emit ? $quote.$quote : ''];
        }
        $lex = 'normal';

        return [1, $emit ? $quote : ''];
    }

    /** @return array{int, string}|null */
    private function consumeStatementEnd(int $offset, int $length): ?array
    {
        $end = $this->statementEndLength($offset, $length);
        if ($end < 0) {
            return null;
        }
        if ($end === 0) {
            throw new RuntimeException('SQL 语句尾部包含无法安全改写的内容');
        }

        return $this->completeStatement($end);
    }

    private function statementEndLength(int $offset, int $length): int
    {
        if (! $this->inVersionComment) {
            return $this->input[$offset] === ';' ? 1 : 0;
        }
        if ($this->input[$offset] !== '*') {
            return 0;
        }
        if ($offset + 1 >= $length && ! $this->finishing) {
            return -1;
        }

        return substr($this->input, $offset, 2) === '*/' ? 2 : 0;
    }

    /** @return array{int, string} */
    private function completeStatement(int $length): array
    {
        $this->currentTable = null;
        $this->insertColumns = [];
        $this->insertKeep = [];
        $this->insertColumnRules = [];
        $this->seenInsertColumns = [];
        if ($this->inVersionComment) {
            $this->inVersionComment = false;
            $this->versionCode = '';
            $this->state = 'version_after';

            return [$length, '*/'];
        }
        $this->state = 'between';

        return [$length, ';'];
    }

    private function resetCreatePrefix(): void
    {
        $this->createPrefix = '';
        $this->createPrefixWord = '';
    }

    private function captureCreateClauseText(string $text): string
    {
        if ($this->plan->inferSchema) {
            $this->createClauseCapture .= $text;
            if (strlen($this->createClauseCapture) > self::MAX_CREATE_CLAUSE_BYTES) {
                throw new RuntimeException('CREATE 单项定义超过安全上限');
            }
        }

        return $this->createClauseKeep ? $text : '';
    }

    private function finishCreateClauseCapture(): void
    {
        if (! $this->plan->inferSchema) {
            return;
        }
        $clause = trim($this->createClauseCapture);
        if ($clause === '') {
            throw new RuntimeException('CREATE TABLE 包含空定义');
        }
        $this->createClauses[] = $clause;
        $this->createClauseCapture = '';
    }

    private function finishInferredTable(string $tail): void
    {
        $table = $this->requireCurrentTable();
        $columns = [];
        $indexes = [];
        $foreignKeys = [];
        foreach ($this->createClauses as $clause) {
            if (str_starts_with(ltrim($clause), '`')) {
                [$name, $definition] = $this->splitInferredColumn($clause);
                if (isset($columns[$name])) {
                    throw new RuntimeException("备份 SQL 重复定义列: $table.$name");
                }
                $columns[$name] = $this->inferredColumnDefinition(count($columns) + 1, $definition);

                continue;
            }
            if (preg_match('/(?:^|\s)FOREIGN\s+KEY(?:\s|\()/i', $clause) === 1) {
                [$name, $foreignKey] = $this->inferredForeignKey($clause);
                if (isset($foreignKeys[$name])) {
                    throw new RuntimeException("备份 SQL 重复定义外键: $name");
                }
                $foreignKeys[$name] = $foreignKey;

                continue;
            }
            $index = $this->inferredIndex($clause);
            if ($index !== null) {
                [$name, $definition] = $index;
                if (isset($indexes[$name])) {
                    throw new RuntimeException("备份 SQL 重复定义索引: $table.$name");
                }
                $indexes[$name] = $definition;

                continue;
            }
            if (preg_match('/\A(?:CONSTRAINT\s+`(?:``|[^`])+`\s+)?CHECK\s*\(/i', trim($clause)) !== 1) {
                throw new RuntimeException("无法从 CREATE TABLE 推导结构项: $table");
            }
        }
        if ($columns === []) {
            throw new RuntimeException("备份 SQL 表没有可推导列: $table");
        }

        $engine = preg_match('/(?:^|\s)ENGINE\s*=\s*([A-Za-z0-9_+-]+)/i', $tail, $matches) === 1
            ? $matches[1]
            : null;
        $collation = preg_match('/(?:^|\s)(?:DEFAULT\s+)?COLLATE\s*=\s*([A-Za-z0-9_+-]+)/i', $tail, $matches) === 1
            ? $matches[1]
            : null;
        $autoIncrement = preg_match('/(?:^|\s)AUTO_INCREMENT\s*=\s*([0-9]+)/i', $tail, $matches) === 1
            ? $matches[1]
            : null;

        $this->inferredTables[$table] = [
            'engine' => $engine,
            'collation' => $collation,
            'comment' => '',
            'auto_increment' => $autoIncrement,
            'data_length' => 0,
            'index_length' => 0,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];
        $this->columnOrder[$table] = array_keys($columns);
        $this->generatedColumns[$table] = array_keys(array_filter(
            $columns,
            fn (array $column): bool => $column['generation_expression'] !== '',
        ));
        $this->createClauses = [];
    }

    /** @return array{string, string} */
    private function splitInferredColumn(string $clause): array
    {
        if (preg_match('/\A`(?<name>(?:``|[^`])+)`\s+(?<definition>.+)\z/sD', trim($clause), $matches) !== 1) {
            throw new RuntimeException('无法从 CREATE TABLE 推导列定义');
        }
        $name = str_replace('``', '`', $matches['name']);
        $this->assertInferredIdentifier($name);

        return [$name, trim($matches['definition'])];
    }

    /** @return array<string, mixed> */
    private function inferredColumnDefinition(int $position, string $definition): array
    {
        $typeOffset = $this->inferredColumnAttributeOffset($definition);
        $type = strtolower(trim(substr($definition, 0, $typeOffset)));
        $type = preg_replace('/\s+/', ' ', $type);
        if (! is_string($type) || $type === '') {
            throw new RuntimeException('无法从 CREATE TABLE 推导列类型');
        }
        $generated = preg_match('/\bGENERATED\s+ALWAYS\s+AS\s*\(/i', $definition) === 1;
        $extra = '';
        if (preg_match('/\bAUTO_INCREMENT\b/i', $definition) === 1) {
            $extra = 'auto_increment';
        } elseif ($generated) {
            $extra = preg_match('/\bSTORED\b/i', $definition) === 1
                ? 'STORED GENERATED'
                : 'VIRTUAL GENERATED';
        }
        $characterSet = preg_match('/\bCHARACTER\s+SET\s+([A-Za-z0-9_]+)/i', $definition, $matches) === 1
            ? strtolower($matches[1])
            : null;

        return [
            'position' => $position,
            'type' => $type,
            'nullable' => preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1,
            'default' => null,
            'extra' => $extra,
            'comment' => '',
            'character_set' => $characterSet,
            'generation_expression' => $generated ? 'inferred-from-sql-ddl' : '',
        ];
    }

    private function inferredColumnAttributeOffset(string $definition): int
    {
        $attributes = array_fill_keys([
            'NOT', 'NULL', 'DEFAULT', 'GENERATED', 'AS', 'AUTO_INCREMENT', 'UNIQUE', 'PRIMARY',
            'COMMENT', 'COLLATE', 'CHARACTER', 'REFERENCES', 'CHECK', 'ON', 'VISIBLE', 'INVISIBLE',
            'COLUMN_FORMAT', 'STORAGE', 'SRID',
        ], true);
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($definition);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $definition[$offset];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($char === '\\' && $quote !== '`') {
                    $escaped = true;

                    continue;
                }
                if ($char === $quote) {
                    if (($definition[$offset + 1] ?? '') === $quote) {
                        $offset++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }
            if ($char === '(') {
                $depth++;

                continue;
            }
            if ($char === ')') {
                $depth--;

                continue;
            }
            if ($depth !== 0 || ! $this->isWordStart($char)) {
                continue;
            }
            $end = $offset + 1;
            while ($end < $length && $this->isWordChar($definition[$end])) {
                $end++;
            }
            $word = strtoupper(substr($definition, $offset, $end - $offset));
            if ($offset > 0 && isset($attributes[$word])) {
                return $offset;
            }
            $offset = $end - 1;
        }

        return $length;
    }

    /** @return array{string, array<string, mixed>} */
    private function inferredForeignKey(string $clause): array
    {
        $identifier = '`(?:``|[^`])+`';
        $pattern = '/\ACONSTRAINT\s+(?<name>'.$identifier.')\s+FOREIGN\s+KEY\s*'
            .'\((?<columns>[^)]*)\)\s+REFERENCES\s+(?<table>'.$identifier.')\s*'
            .'\((?<references>[^)]*)\)(?<actions>.*)\z/isD';
        if (preg_match($pattern, trim($clause), $matches) !== 1) {
            throw new RuntimeException('无法从 CREATE TABLE 安全推导外键');
        }
        $name = $this->unquoteInferredIdentifier($matches['name']);
        $referencedTable = $this->unquoteInferredIdentifier($matches['table']);
        $columns = $this->inferredIdentifierList($matches['columns']);
        $referencedColumns = $this->inferredIdentifierList($matches['references']);
        if (count($columns) !== count($referencedColumns)) {
            throw new RuntimeException("备份 SQL 外键列数不一致: $name");
        }
        $actions = ['DELETE' => 'RESTRICT', 'UPDATE' => 'RESTRICT'];
        $remaining = trim($matches['actions']);
        while ($remaining !== '') {
            if (preg_match('/\AON\s+(DELETE|UPDATE)\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)(?:\s+|\z)/i', $remaining, $action) !== 1) {
                throw new RuntimeException("备份 SQL 外键动作无法安全推导: $name");
            }
            $kind = strtoupper($action[1]);
            $actions[$kind] = preg_replace('/\s+/', ' ', strtoupper($action[2]));
            $remaining = ltrim(substr($remaining, strlen($action[0])));
        }

        return [$name, [
            'columns' => $columns,
            'references' => ['table' => $referencedTable, 'columns' => $referencedColumns],
            'on_delete' => $actions['DELETE'],
            'on_update' => $actions['UPDATE'],
        ]];
    }

    /** @return array{string, array<string, mixed>}|null */
    private function inferredIndex(string $clause): ?array
    {
        $identifier = '`(?:``|[^`])+`';
        $patterns = [
            ['PRIMARY', true, 'BTREE', '/\APRIMARY\s+KEY(?:\s+USING\s+(?<before>BTREE|HASH))?\s*\((?<columns>.+)\)(?:\s+USING\s+(?<after>BTREE|HASH))?\z/isD'],
            [null, true, 'BTREE', '/\AUNIQUE\s+(?:KEY|INDEX)\s+(?<name>'.$identifier.')(?:\s+USING\s+(?<before>BTREE|HASH))?\s*\((?<columns>.+)\)(?:\s+USING\s+(?<after>BTREE|HASH))?\z/isD'],
            [null, false, 'BTREE', '/\A(?:KEY|INDEX)\s+(?<name>'.$identifier.')(?:\s+USING\s+(?<before>BTREE|HASH))?\s*\((?<columns>.+)\)(?:\s+USING\s+(?<after>BTREE|HASH))?\z/isD'],
            [null, false, 'FULLTEXT', '/\AFULLTEXT\s+(?:KEY|INDEX)\s+(?<name>'.$identifier.')\s*\((?<columns>.+)\)\z/isD'],
            [null, false, 'SPATIAL', '/\ASPATIAL\s+(?:KEY|INDEX)\s+(?<name>'.$identifier.')\s*\((?<columns>.+)\)\z/isD'],
        ];
        foreach ($patterns as [$fixedName, $unique, $defaultType, $pattern]) {
            if (preg_match($pattern, trim($clause), $matches) !== 1) {
                continue;
            }
            $name = $fixedName ?? $this->unquoteInferredIdentifier($matches['name']);
            [$columns, $subParts] = $this->inferredIndexColumns($matches['columns']);
            $type = $matches['after'] ?? '';
            if ($type === '') {
                $type = $matches['before'] ?? '';
            }
            if ($type === '') {
                $type = $defaultType;
            }
            $type = strtoupper($type);

            return [$name, [
                'unique' => $unique,
                'type' => $type,
                'columns' => $columns,
                'sub_parts' => $subParts,
            ]];
        }

        return null;
    }

    /** @return array{list<string>, list<?int>} */
    private function inferredIndexColumns(string $list): array
    {
        $parts = preg_split('/\s*,\s*/', trim($list));
        if (! is_array($parts) || $parts === []) {
            throw new RuntimeException('备份 SQL 索引列清单为空');
        }
        $columns = [];
        $subParts = [];
        foreach ($parts as $part) {
            if (preg_match('/\A(?<column>`(?:``|[^`])+`)(?:\s*\((?<length>[0-9]+)\))?(?:\s+(?:ASC|DESC))?\z/iD', trim($part), $matches) !== 1) {
                throw new RuntimeException('无法从 CREATE TABLE 安全推导索引列');
            }
            $columns[] = $this->unquoteInferredIdentifier($matches['column']);
            $subParts[] = isset($matches['length'])
                ? (int) $matches['length']
                : null;
        }

        return [$columns, $subParts];
    }

    /** @return list<string> */
    private function inferredIdentifierList(string $list): array
    {
        $parts = preg_split('/\s*,\s*/', trim($list));
        if (! is_array($parts) || $parts === []) {
            throw new RuntimeException('备份 SQL 外键列清单为空');
        }

        return array_map($this->unquoteInferredIdentifier(...), $parts);
    }

    private function unquoteInferredIdentifier(string $quoted): string
    {
        if (preg_match('/\A`(?:``|[^`])+`\z/D', trim($quoted)) !== 1) {
            throw new RuntimeException('备份 SQL 外键包含无效标识符');
        }
        $identifier = str_replace('``', '`', substr(trim($quoted), 1, -1));
        $this->assertInferredIdentifier($identifier);

        return $identifier;
    }

    private function assertInferredIdentifier(string $identifier): void
    {
        if (strlen($identifier) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException('备份 SQL 包含无法安全推导的标识符');
        }
    }

    private function appendCreatePrefix(string $text): void
    {
        $this->createPrefix .= $text;
        if (strlen($this->createPrefix) > self::MAX_TOKEN_BYTES) {
            throw new RuntimeException('CREATE 定义前缀超过安全上限');
        }
    }

    private function appendToken(string $char): void
    {
        $this->token .= $char;
        if (strlen($this->token) > self::MAX_TOKEN_BYTES) {
            throw new RuntimeException('SQL token 超过安全上限');
        }
    }

    private function appendIdentifier(string $char): void
    {
        $this->identifier .= $char;
        if (strlen($this->identifier) > self::MAX_TOKEN_BYTES) {
            throw new RuntimeException('SQL 标识符超过安全上限');
        }
    }

    private function requireCurrentTable(): string
    {
        if ($this->currentTable === null) {
            throw new RuntimeException('SQL 改写缺少当前表');
        }

        return $this->currentTable;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t" || $char === "\f";
    }

    private function isWordStart(string $char): bool
    {
        return ($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z') || $char === '_';
    }

    private function isWordChar(string $char): bool
    {
        return $this->isWordStart($char) || ($char >= '0' && $char <= '9');
    }

    private function isHexDigit(string $char): bool
    {
        return ($char >= '0' && $char <= '9')
            || ($char >= 'A' && $char <= 'F')
            || ($char >= 'a' && $char <= 'f');
    }
}
