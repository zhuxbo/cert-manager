<?php

declare(strict_types=1);

namespace Tests\Compat;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

/**
 * Pest 测试 HTTP 调用拦截器（API 兼容性快照对照）。
 *
 * 工作模式：
 * - capture（COMPAT_CAPTURE=true）：监听 RequestHandled 事件 → 抽取 request/response schema → 测试结束时 flush 到 fixture 文件
 * - compare（COMPAT_COMPARE=true）：监听同样事件 → 实时与已有 fixture 对比 → 测试结束时若有 diff 则汇总报错
 *
 * 关键设计：
 * - 仅监听 /api/* 路径下的请求（业务接口；忽略静态资源/路由探测调用）
 * - capture 时把每个测试的所有 calls 累积到 self::$captures[testName]，flushFixture 时一次性写盘（确定性）
 * - 测试间隔离用 setCurrentTest() 切换 key
 * - expectsBreakingChange() 标记后跳过 compare（capture 模式下仍然刷新 fixture）
 *
 * 注册位置：tests/Pest.php 的 beforeAll/afterEach 钩子。
 */
final class SnapshotListener
{
    /** @var array<string, array<int, array<string, mixed>>> testName → calls */
    private static array $captures = [];

    /** @var array<string, string> testName → reason */
    private static array $breakingChanges = [];

    /** @var array<string, array<int, array<string, mixed>>> testName → diffs (compare 模式累积) */
    private static array $diffs = [];

    /** @var array<string, array<string, true>> testName → seen-call keys (compare 模式仅比首次) */
    private static array $seenCalls = [];

    private static ?string $currentTest = null;

    /**
     * 注册 RequestHandled 监听器。
     *
     * 每次测试 setUp 都重新注册 — Laravel\Foundation\TestCase / RefreshDatabase
     * 会重建 app 与 EventDispatcher，旧 listener 失效。所以无需"防重"。
     */
    public static function register(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            self::onRequestHandled($event);
        });
    }

    /**
     * 设置当前正在跑的测试名（afterEach 之前的 beforeEach 调用）。
     */
    public static function setCurrentTest(?string $testName): void
    {
        self::$currentTest = $testName;
    }

    /**
     * 标记当前测试为预期破坏性变更（compare 模式下跳过校验）。
     */
    public static function markBreakingChange(string $reason): void
    {
        if (self::$currentTest === null) {
            return;
        }
        self::$breakingChanges[self::$currentTest] = $reason;
    }

    /**
     * 测试结束时调用：capture 模式下写 fixture；compare 模式下汇总并报错。
     */
    public static function finalizeTest(string $testName): void
    {
        if (Helpers::isCaptureMode()) {
            self::flushFixture($testName);
            // 记录 breaking change 日志
            if (isset(self::$breakingChanges[$testName])) {
                self::appendBreakingChangeLog($testName, self::$breakingChanges[$testName]);
            }
        }

        if (Helpers::isCompareMode()) {
            // breaking change 跳过校验
            if (isset(self::$breakingChanges[$testName])) {
                self::clearTest($testName);

                return;
            }
            self::assertNoDiff($testName);
        }

        self::clearTest($testName);
    }

    /**
     * RequestHandled 回调。
     */
    private static function onRequestHandled(RequestHandled $event): void
    {
        if (self::$currentTest === null) {
            return;
        }
        if (! Helpers::isCaptureMode() && ! Helpers::isCompareMode()) {
            return;
        }

        $request = $event->request;
        $response = $event->response;

        // 仅关心 /api/* 路径
        $path = '/'.ltrim($request->getPathInfo(), '/');
        if (! str_starts_with($path, '/api/')) {
            return;
        }

        // 优先用 Laravel route pattern（"/api/foo/{id}"），fallback 到正则归一化
        $uriPattern = self::resolveRoutePattern($request, $path);

        // 抽 request schema（仅 keys，不含值）
        $requestKeys = self::extractRequestKeys($request);

        // 抽 response schema
        $responseSchema = self::extractResponseSchema($response);

        $call = [
            'method' => $request->getMethod(),
            'uri_pattern' => $uriPattern,
            'request_keys' => $requestKeys,
            'response_status' => self::normalizeStatus($response),
            'response_schema' => $responseSchema,
        ];

        if (Helpers::isCaptureMode()) {
            // 同一测试内对 (method, uri_pattern) 仅记录首次，避免同端点多次调用造成 fixture 噪声
            $key = $call['method'].' '.$call['uri_pattern'];
            self::$captures[self::$currentTest] ??= [];
            self::$captures[self::$currentTest][$key] ??= $call;
        }

        if (Helpers::isCompareMode()) {
            // 与 capture 一致：每个 (method, uri_pattern) 仅比首次（避免同测试内多次调用造成噪声）
            $key = $call['method'].' '.$call['uri_pattern'];
            if (! isset(self::$seenCalls[self::$currentTest][$key])) {
                self::$seenCalls[self::$currentTest][$key] = true;
                self::compareCall(self::$currentTest, $call);
            }
        }
    }

    /**
     * @return list<string>|null
     */
    private static function extractRequestKeys(Request $request): ?array
    {
        // 仅记录顶层 key，不递归（请求结构通常扁平；嵌套字段太多会让 fixture 噪声大）
        $payload = $request->all();
        if ($payload === []) {
            return null;
        }
        $keys = array_keys($payload);
        sort($keys);

        return array_map('strval', $keys);
    }

    /**
     * @return mixed schema
     */
    private static function extractResponseSchema(mixed $response): mixed
    {
        if (! is_object($response)) {
            return null;
        }

        // JsonResponse 保留原始数据，取 getData(true) 可拿到关联数组
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (! is_array($data)) {
                return null;
            }

            return SchemaDiffer::extractSchema(self::stripDebugFields($data));
        }

        if (! method_exists($response, 'getContent')) {
            return null;
        }
        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }

        return SchemaDiffer::extractSchema(self::stripDebugFields($decoded));
    }

    /**
     * 剥离仅在 APP_DEBUG=true 时输出的调试字段。
     *
     * ApiExceptions 在 debug 模式下塞入 errors.exception_type / errors.exception_trace —
     * 这些是栈跟踪信息，不属于 API 公共契约，不应固化到快照（否则本地 capture 与
     * CI compare 因 APP_DEBUG 差异产生 false positive）。
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function stripDebugFields(array $data): array
    {
        if (isset($data['errors']) && is_array($data['errors'])) {
            unset($data['errors']['exception_type'], $data['errors']['exception_trace']);
            if ($data['errors'] === []) {
                unset($data['errors']);
            }
        }

        return $data;
    }

    /**
     * 优先使用 Laravel router 解析的 route pattern（"/api/admin/order/show/{id}"），
     * 这样动态参数（id / token / hash 等）自动归一化。
     *
     * fallback 到正则简化（数字段 → {id}）。
     */
    private static function resolveRoutePattern(Request $request, string $path): string
    {
        try {
            $route = $request->route();
            // $route 可能是 Illuminate\Routing\Route 或 Closure 或 null（具体取决于请求阶段）
            if ($route instanceof Route) {
                $uri = $route->uri();
                if ($uri !== '') {
                    return self::normalizeUri('/'.ltrim($uri, '/'));
                }
            }
        } catch (\Throwable) {
            // 忽略，走 fallback
        }

        return self::normalizeUri($path);
    }

    /**
     * fallback 归一化：数字段 → {id}；长 token 段（≥ 32 字符的字母数字）→ {token}。
     */
    private static function normalizeUri(string $path): string
    {
        // 数字段 → {id}
        $path = preg_replace('@/\d+(?=/|$)@', '/{id}', $path) ?? $path;

        // 长 token / hash 段 → {token}（避免随机 token 占位污染 fixture）
        $path = preg_replace('@/[A-Za-z0-9_-]{32,}(?=/|$)@', '/{token}', $path) ?? $path;

        return $path;
    }

    /**
     * 把 HTTP status code 归类（避免 fixture 因 200/201 抖动）。
     */
    private static function normalizeStatus(mixed $response): string
    {
        if (! is_object($response) || ! method_exists($response, 'getStatusCode')) {
            return 'unknown';
        }
        $code = (int) $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            return '2xx';
        }
        if ($code >= 300 && $code < 400) {
            return '3xx';
        }
        if ($code >= 400 && $code < 500) {
            return '4xx';
        }
        if ($code >= 500) {
            return '5xx';
        }

        return 'unknown';
    }

    /**
     * capture 模式：写 fixture 文件。
     */
    private static function flushFixture(string $testName): void
    {
        if (! isset(self::$captures[$testName]) || self::$captures[$testName] === []) {
            return;
        }

        $path = Helpers::fixturePath($testName);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // captures 是按 "METHOD URI" 聚合的关联数组（首次记录），写盘时拍平 + 排序
        $calls = array_values(self::$captures[$testName]);
        usort($calls, function ($a, $b) {
            $cmp = strcmp((string) $a['method'], (string) $b['method']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['uri_pattern'], (string) $b['uri_pattern']);
        });

        $fixture = [
            'test' => $testName,
            'version' => self::appVersion(),
            'calls' => $calls,
        ];

        file_put_contents(
            $path,
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    /**
     * compare 模式：实时与 fixture 对比当前 call。
     *
     * @param  array<string, mixed>  $call
     */
    private static function compareCall(string $testName, array $call): void
    {
        $path = Helpers::fixturePath($testName);
        if (! is_file($path)) {
            // fixture 不存在 → 视作新增 call，记 diff
            self::$diffs[$testName][] = [
                'path' => '$.fixture',
                'kind' => 'fixture_missing',
                'expected' => 'fixture file at '.$path,
                'actual' => 'no fixture',
            ];

            return;
        }
        $fixture = json_decode((string) file_get_contents($path), true);
        if (! is_array($fixture) || ! isset($fixture['calls']) || ! is_array($fixture['calls'])) {
            self::$diffs[$testName][] = [
                'path' => '$.fixture',
                'kind' => 'fixture_corrupt',
                'expected' => 'array of calls',
                'actual' => 'corrupt',
            ];

            return;
        }

        // 找匹配 call
        $match = null;
        foreach ($fixture['calls'] as $expectedCall) {
            if (! is_array($expectedCall)) {
                continue;
            }
            if (
                ($expectedCall['method'] ?? null) === $call['method']
                && ($expectedCall['uri_pattern'] ?? null) === $call['uri_pattern']
            ) {
                $match = $expectedCall;
                break;
            }
        }
        if ($match === null) {
            self::$diffs[$testName][] = [
                'path' => '$.calls['.$call['method'].' '.$call['uri_pattern'].']',
                'kind' => 'call_added',
                'expected' => null,
                'actual' => $call,
            ];

            return;
        }

        // 对比 response_schema
        $diffs = SchemaDiffer::diff(
            $match['response_schema'] ?? null,
            $call['response_schema'] ?? null,
            '$.response_schema'
        );

        // 对比 request_keys：入参契约增删同样算 break（旧格式 fixture 无此键时跳过）
        if (array_key_exists('request_keys', $match)) {
            $expectedKeys = is_array($match['request_keys']) ? array_values(array_map('strval', $match['request_keys'])) : null;
            $diffs = array_merge($diffs, SchemaDiffer::diffRequestKeys($expectedKeys, $call['request_keys']));
        }

        foreach ($diffs as $d) {
            self::$diffs[$testName][] = $d + [
                'call' => $call['method'].' '.$call['uri_pattern'],
            ];
        }

        // status 变化也算 break
        if (($match['response_status'] ?? null) !== $call['response_status']) {
            self::$diffs[$testName][] = [
                'path' => '$.response_status',
                'kind' => 'status_changed',
                'expected' => $match['response_status'] ?? null,
                'actual' => $call['response_status'],
                'call' => $call['method'].' '.$call['uri_pattern'],
            ];
        }
    }

    /**
     * compare 模式：测试结束时若有 diff 则触发断言失败。
     */
    private static function assertNoDiff(string $testName): void
    {
        if (! isset(self::$diffs[$testName]) || self::$diffs[$testName] === []) {
            return;
        }

        $report = "API snapshot diff for [{$testName}]:\n";
        foreach (self::$diffs[$testName] as $d) {
            $report .= sprintf(
                "  - %s: %s @ %s (expected=%s actual=%s)\n",
                $d['kind'] ?? 'unknown',
                $d['call'] ?? '-',
                $d['path'] ?? '?',
                self::shortRepr($d['expected'] ?? null),
                self::shortRepr($d['actual'] ?? null)
            );
        }
        $report .= "\n如属预期破坏性变更，请在用例顶部加 expectsBreakingChange('reason')。";

        // 写一份 diff-report
        $reportPath = __DIR__.'/diff-report.md';
        @file_put_contents(
            $reportPath,
            "# Snapshot Diff Report\n\n".date('c')."\n\n".$report.PHP_EOL,
            FILE_APPEND
        );

        // 用 PHPUnit 断言失败（必须先有 PHPUnit 上下文，afterEach 内调）
        Assert::fail($report);
    }

    private static function shortRepr(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v) || is_null($v)) {
            return var_export($v, true);
        }
        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return gettype($v);
        }

        return strlen($json) > 200 ? substr($json, 0, 200).'…' : $json;
    }

    private static function clearTest(string $testName): void
    {
        unset(
            self::$captures[$testName],
            self::$breakingChanges[$testName],
            self::$diffs[$testName],
            self::$seenCalls[$testName],
        );
    }

    private static function appVersion(): string
    {
        try {
            return (string) (config('app.version') ?? '1.0.0');
        } catch (\Throwable) {
            return '1.0.0';
        }
    }

    private static function appendBreakingChangeLog(string $testName, string $reason): void
    {
        $logPath = __DIR__.'/BREAKING_CHANGES.md';
        $entry = sprintf(
            "- [%s] %s\n  - test: %s\n  - reason: %s\n\n",
            date('c'),
            self::appVersion(),
            $testName,
            $reason
        );
        @file_put_contents($logPath, $entry, FILE_APPEND);
    }
}
