<?php

namespace App\Services\EnterpriseLookup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AliyunDriver implements LookupInterface
{
    private const int HTTP_TIMEOUT = 10;

    public function lookup(string $name): array
    {
        // cacheKey 含 fieldMap 指纹:fieldMap 变更后旧缓存自动失效,
        // 避免"配置改了但 24h 缓存返回旧 schema 数据"导致的字段缺失
        $fieldMap = (array) get_system_setting('enterprise', 'fieldMap', []);
        $fpHash = substr(md5((string) json_encode($fieldMap)), 0, 8);
        $cacheKey = "enterprise:$fpHash:".md5("aliyun:$name");

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if (empty($cached['name']) && empty($cached['registration_number']) && empty($cached['address'])) {
                throw new LookupException('未查询到企业（缓存）', 404);
            }

            return $cached;
        }

        try {
            $result = $this->call($name);
            Cache::put($cacheKey, $result, now()->addHours(24));

            return $result;
        } catch (LookupException $e) {
            // 429(今日额度已用完)不写失败缓存,否则次日重置后同一企业仍命中失败缓存
            if ($e->getCode() !== 429) {
                $this->cacheFailure($cacheKey);
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->cacheFailure($cacheKey);
            throw new LookupException('工商查询服务不可用', 502, $e);
        }
    }

    private function call(string $name): array
    {
        $url = (string) get_system_setting('enterprise', 'url', '');
        $appCode = (string) get_system_setting('enterprise', 'appCode', '');
        $fieldMap = (array) get_system_setting('enterprise', 'fieldMap', []);
        $queryField = (string) get_system_setting('enterprise', 'queryField', 'name') ?: 'name';

        if ($url === '' || $appCode === '') {
            throw new LookupException('工商查询未配置', 400);
        }

        $this->enforceAndIncrementDailyQuota();

        $resp = Http::timeout(self::HTTP_TIMEOUT)
            ->withHeaders(['Authorization' => "APPCODE $appCode"])
            ->get($url, [$queryField => $name]);

        if (! $resp->successful()) {
            throw new LookupException("供应商返回 {$resp->status()}", 502);
        }

        $body = $resp->json() ?? [];
        $out = $this->emptyResult();
        foreach ($fieldMap as $std => $src) {
            if ($src === '' || $src === null) {
                continue;
            }
            $out[$std] = data_get($body, $src);
        }

        if (empty($out['name']) && empty($out['registration_number']) && empty($out['address'])) {
            throw new LookupException('未查询到企业', 404);
        }

        return $out;
    }

    /**
     * 全局每日上限:实际调用上游前 +1 并校验,缓存命中不计数。
     * dailyLimit <= 0 视为无限制(运维灵活)。
     */
    private function enforceAndIncrementDailyQuota(): void
    {
        $limit = (int) get_system_setting('enterprise', 'dailyLimit', 100);
        if ($limit <= 0) {
            return;
        }

        $today = now()->format('Y-m-d');
        $key = "enterprise:daily:$today";
        $ttl = max(60, now()->diffInSeconds(now()->endOfDay(), false));

        Cache::add($key, 0, $ttl);
        $count = Cache::increment($key);

        if ($count > $limit) {
            Cache::decrement($key);
            throw new LookupException("今日工商查询额度已用完（上限 $limit 次）", 429);
        }
    }

    private function cacheFailure(string $cacheKey): void
    {
        Cache::put($cacheKey, $this->emptyResult(), now()->addHour());
    }

    private function emptyResult(): array
    {
        return [
            'name' => null,
            'registration_number' => null,
            'address' => null,
            'state' => null,
            'city' => null,
            'regionname' => null,
            'legal_person' => null,
        ];
    }
}
