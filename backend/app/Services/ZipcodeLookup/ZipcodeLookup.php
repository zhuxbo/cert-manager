<?php

namespace App\Services\ZipcodeLookup;

class ZipcodeLookup
{
    /**
     * 单次脚本执行内的缓存,结构:['list'=>array, 'cityRep'=>array]。
     *
     * userland static 的生命周期 = 一次脚本执行:FPM 单请求内复用(每个新请求重新解析,
     * static 随请求结束销毁,不跨 FPM 请求)、CLI/queue worker 单进程内整段复用。
     * 邮编是全国静态数据,即便 worker 常驻跨多 job 复用也无陈旧问题(无需像 Cert::chainMap
     * 那样按 job 边界清),故用 static 而非 app()->scoped。
     * 单进程内多次 find() 只解析一次 JSON(loaded() 首查命中即返回)。
     */
    private static ?array $cache = null;

    /**
     * 根据 regionname(完整行政区划串,如"河南省南阳市卧龙区")+ 可选公司名 反查邮编
     *
     * 策略:
     *   1. 在数据里寻找最长的 fullPath = province+city+name 匹配 regionname(直辖市同时
     *      尝试两段拼接 city+name)— 这是区/县/县级市精度的命中
     *   2. 如果有公司名 + regionname 仅到地级市(未命中区/县),则在 regionname 锁定的省市
     *      范围下找县级市,看公司名是否含县级市名 → 命中则把 city 改填县级市
     *   3. 否则尝试 province+city 匹配,返回该地级市的代表邮编(min zipcode)
     *
     * @return array{zipcode:string,province:string,city:string,district:string}|null
     */
    public function find(?string $regionname, ?string $companyName = null): ?array
    {
        $regionname = trim((string) $regionname);
        $companyName = trim((string) $companyName);

        if ($regionname === '') {
            return null;
        }

        $loaded = self::loaded();
        $list = $loaded['list'];

        // 步骤 1:最长 fullPath 匹配
        $best = null;
        $bestLen = 0;
        foreach ($list as $r) {
            $full = $r['province'].$r['city'].$r['name'];
            $fullLen = mb_strlen($full);
            if ($fullLen > $bestLen && str_contains($regionname, $full)) {
                $best = $r;
                $bestLen = $fullLen;

                continue;
            }
            // 直辖市:数据 province == city,工商响应通常只出现一次,所以试 city+name
            if ($r['province'] === $r['city']) {
                $short = $r['city'].$r['name'];
                $shortLen = mb_strlen($short);
                if ($shortLen > $bestLen && str_contains($regionname, $short)) {
                    $best = $r;
                    $bestLen = $shortLen;
                }
            }
        }

        if ($best !== null) {
            // 命中是县级市?直接用县级市
            if ($this->isCountyLevelCity($best)) {
                return $this->shapeCountyLevel($best);
            }

            // 命中区/县 — 如果公司名能识别同 city 下的县级市,用县级市覆盖
            if ($companyName !== '') {
                $sub = $this->findSubByCompanyName($list, $best['province'], $best['city'], $companyName);
                if ($sub !== null) {
                    return $this->shapeCountyLevel($sub);
                }
            }

            return $this->shapeDistrict($best);
        }

        // 步骤 2:regionname 未命中区/县,公司名兜底识别县级市
        // 兼容工商响应字段不全的场景(如县级市公司只返回 city、无 province/regionname)
        // 放宽:regionname 含 province 或 city 任一即可锁定县级市候选(地级市基本无重名)
        if ($companyName !== '') {
            foreach ($list as $r) {
                if (! $this->isCountyLevelCity($r)) {
                    continue;
                }
                $inProv = str_contains($regionname, $r['province']);
                $inCity = str_contains($regionname, $r['city']);
                if (! $inProv && ! $inCity) {
                    continue;
                }
                if ($this->companyMatchesSub($companyName, $r['name'])) {
                    return $this->shapeCountyLevel($r);
                }
            }
        }

        // 步骤 3:province+city 匹配,返回市级代表邮编。优先级:
        //   ① province + city 同时命中(最严格)
        //   ② 仅 city 命中(地级市基本无重名,可信)
        //   ③ 仅 province 命中(兜底)
        $bothMatch = null;
        $cityOnly = null;
        $provOnly = null;
        foreach ($loaded['cityRep'] as $rep) {
            $inProv = str_contains($regionname, $rep['province']);
            $inCity = str_contains($regionname, $rep['city']);
            if ($inProv && $inCity) {
                $bothMatch = $rep;
                break;
            }
            if ($inCity && $cityOnly === null) {
                $cityOnly = $rep;
            } elseif ($inProv && $provOnly === null) {
                $provOnly = $rep;
            }
        }
        $rep = $bothMatch ?? $cityOnly ?? $provOnly;
        if ($rep !== null) {
            return [
                'zipcode' => (string) $rep['zipcode'],
                'province' => (string) $rep['province'],
                'city' => (string) $rep['city'],
                'district' => '',
            ];
        }

        return null;
    }

    private function findSubByCompanyName(array $list, string $province, string $city, string $companyName): ?array
    {
        foreach ($list as $r) {
            if (! $this->isCountyLevelCity($r)) {
                continue;
            }
            if ($r['province'] !== $province || $r['city'] !== $city) {
                continue;
            }
            if ($this->companyMatchesSub($companyName, $r['name'])) {
                return $r;
            }
        }

        return null;
    }

    private function companyMatchesSub(string $companyName, string $subCityName): bool
    {
        if ($subCityName === '') {
            return false;
        }
        if (str_contains($companyName, $subCityName)) {
            return true;
        }
        // 去掉"市"后缀再匹配("义乌"在"义乌XX有限公司"里)。
        // 必须按字符剥离尾部"市"：rtrim 的 charlist 是字节集 {E5,B8,82}，会贪婪吃掉
        // 相邻汉字的字节（如 '五常市'→'五'），导致单字误匹配污染县级市识别。
        $stripped = (string) preg_replace('/市$/u', '', $subCityName);
        if ($stripped !== '' && $stripped !== $subCityName) {
            return str_contains($companyName, $stripped);
        }

        return false;
    }

    private function isCountyLevelCity(array $r): bool
    {
        return str_ends_with((string) $r['name'], '市') && $r['name'] !== $r['city'];
    }

    /** 命中县级市时:city 填县级市名,district 留空 */
    private function shapeCountyLevel(array $r): array
    {
        return [
            'zipcode' => (string) $r['zipcode'],
            'province' => (string) $r['province'],
            'city' => (string) $r['name'],
            'district' => '',
        ];
    }

    /** 命中普通区/县时:city 是地级市,district 填 name */
    private function shapeDistrict(array $r): array
    {
        return [
            'zipcode' => (string) $r['zipcode'],
            'province' => (string) $r['province'],
            'city' => (string) $r['city'],
            'district' => (string) $r['name'],
        ];
    }

    private static function loaded(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = resource_path('data/china_city_zipcode.json');
        $list = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $list = $decoded;
            }
        }

        // 每地级市的代表邮编:取该 (province, city) 内 zipcode 最小者
        $cityRep = [];
        foreach ($list as $r) {
            $key = ($r['province'] ?? '').'|'.($r['city'] ?? '');
            $z = (string) ($r['zipcode'] ?? '');
            if ($z === '') {
                continue;
            }
            if (! isset($cityRep[$key]) || $z < $cityRep[$key]['zipcode']) {
                $cityRep[$key] = $r;
            }
        }

        self::$cache = ['list' => $list, 'cityRep' => array_values($cityRep)];

        return self::$cache;
    }

    /**
     * 仅供测试使用:重置进程内缓存
     */
    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
