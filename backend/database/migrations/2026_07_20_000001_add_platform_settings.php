<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('setting_groups') || ! Schema::hasTable('settings')) {
            return;
        }

        // 幂等守卫：enum 已含 image 时跳过 DDL（DDL 隐式提交，重复执行也应无副作用）
        $typeColumn = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['settings', 'type'],
        );
        if ($typeColumn !== null && ! str_contains($typeColumn->column_type, "'image'")) {
            DB::statement("ALTER TABLE `settings` MODIFY COLUMN `type` ENUM('string','integer','float','boolean','select','array','base64','image') NOT NULL DEFAULT 'string' COMMENT '类型: string=字符串,integer=整数,float=浮点数,boolean=布尔值,select=选择框,array=数组,base64=Base64编码,image=图片'");
        }

        // 升级链路（upgrade.sh / PackageExtractor）在替换前端前把旧 platform-config.json
        // 暂存到 storage/app/legacy-platform-config/{admin,user}.json；此处导入历史定制值，
        // 避免存量部署的备案号/站点名/品牌裁剪被默认值静默替换。migrate 后暂存由升级链路清理。
        $legacy = $this->readLegacyConfigs();

        $now = now();
        $siteId = DB::table('setting_groups')->where('name', 'site')->value('id');
        if ($siteId) {
            $legacyTitle = $this->legacyString($legacy, ['user', 'admin'], 'Title');
            DB::table('settings')
                ->where('group_id', $siteId)
                ->where('key', 'name')
                ->whereNull('value')
                ->update(['value' => $legacyTitle !== '' ? $legacyTitle : 'SSL', 'updated_at' => $now]);

            $this->insertSetting($siteId, 'logo', 'image', '', '站点 Logo', 3);
            $this->insertSetting($siteId, 'qrcode', 'image', '', '客服微信二维码', 4);
            $this->insertSetting($siteId, 'beian', 'string', $this->legacyString($legacy, ['user'], 'Beian'), '网站备案号', 5);
            DB::table('settings')
                ->where('group_id', $siteId)
                ->whereIn('key', ['logo', 'qrcode'])
                ->update(['type' => 'image', 'updated_at' => $now]);
        }

        $brandId = DB::table('setting_groups')->where('name', 'brand')->value('id');
        if (! $brandId) {
            $brandId = DB::table('setting_groups')->insertGetId([
                'name' => 'brand',
                'title' => '品牌设置',
                'description' => null,
                'weight' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $defaultBrands = [
            'cnssl' => 'Cnssl',
            'certum' => 'Certum',
            'gogetssl' => 'GoGetSSL',
            'positive' => 'Positive',
            'keeptrust' => '环安信',
            'ssltrus' => '锐安信',
            'rapid' => 'Rapid',
            'geotrust' => 'GeoTrust',
            'digicert' => 'DigiCert',
        ];

        $this->insertSetting($brandId, 'admin', 'array', $this->legacyBrands($legacy['admin'] ?? null) ?? $defaultBrands, '管理端品牌选项', 1);
        $this->insertSetting($brandId, 'user', 'array', $this->legacyBrands($legacy['user'] ?? null) ?? $defaultBrands, '用户端品牌选项', 2);
    }

    /** @return array{admin: array<string, mixed>|null, user: array<string, mixed>|null} */
    private function readLegacyConfigs(): array
    {
        $result = ['admin' => null, 'user' => null];
        foreach (['admin', 'user'] as $side) {
            $path = storage_path("app/legacy-platform-config/$side.json");
            if (! is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $result[$side] = $decoded;
            }
        }

        return $result;
    }

    /**
     * @param  array{admin: array<string, mixed>|null, user: array<string, mixed>|null}  $legacy
     * @param  list<string>  $sides
     */
    private function legacyString(array $legacy, array $sides, string $key): string
    {
        foreach ($sides as $side) {
            $value = $legacy[$side][$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * 旧静态配置 Brands 为品牌值字符串数组；映射为「值 => 显示名」，未知值原样作显示名。
     *
     * @return array<string, string>|null 非法或为空时返回 null（回落默认品牌表）
     */
    private function legacyBrands(?array $config): ?array
    {
        $legacyLabels = [
            'cnssl' => 'Cnssl',
            'certum' => 'Certum',
            'gogetssl' => 'GoGetSSL',
            'positive' => 'Positive',
            'ssltrus' => '锐安信',
            'keeptrust' => '环安信',
            'rapid' => 'Rapid',
            'geotrust' => 'GeoTrust',
            'sectigo' => 'Sectigo',
            'alpha' => 'Alpha',
            'globalsign' => 'GlobalSign',
            'digicert' => 'DigiCert',
            'trustasia' => 'TrustAsia',
            'wotrus' => '沃通',
            'sheca' => '上海CA',
            'cfca' => 'CFCA',
        ];

        $brands = $config['Brands'] ?? null;
        if (! is_array($brands)) {
            return null;
        }

        $result = [];
        foreach ($brands as $brand) {
            if (! is_string($brand) || trim($brand) === '') {
                continue;
            }
            $value = mb_strtolower(trim($brand));
            $result[$value] = $legacyLabels[$value] ?? trim($brand);
        }

        return $result === [] ? null : $result;
    }

    private function insertSetting(
        int $groupId,
        string $key,
        string $type,
        mixed $value,
        string $description,
        int $weight,
        ?array $options = null,
        bool $isMultiple = false,
    ): void {
        if (DB::table('settings')->where('group_id', $groupId)->where('key', $key)->exists()) {
            return;
        }

        $now = now();
        DB::table('settings')->insert([
            'group_id' => $groupId,
            'key' => $key,
            'type' => $type,
            'options' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'is_multiple' => $isMultiple,
            'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value,
            'description' => $description,
            'weight' => $weight,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作。
    }
};
