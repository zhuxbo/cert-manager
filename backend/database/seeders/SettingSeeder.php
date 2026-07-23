<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class SettingSeeder extends Seeder
{
    /**
     * Seed the settings and setting_groups tables.
     */
    public function run(): void
    {
        // 定义 setting groups
        $settingGroupsData = [
            ['name' => 'site', 'title' => '站点设置', 'description' => null, 'weight' => 1],
            ['name' => 'ca', 'title' => '证书接口', 'description' => null, 'weight' => 2],
            ['name' => 'callback', 'title' => '回调设置', 'description' => null, 'weight' => 3],
            ['name' => 'mail', 'title' => '邮件设置', 'description' => null, 'weight' => 4],
            ['name' => 'sms', 'title' => '短信设置', 'description' => null, 'weight' => 5],
            ['name' => 'alipay', 'title' => '支付宝设置', 'description' => null, 'weight' => 6],
            ['name' => 'wechat', 'title' => '微信支付设置', 'description' => null, 'weight' => 7],
            ['name' => 'bankAccount', 'title' => '银行账户设置', 'description' => null, 'weight' => 8],
            ['name' => 'enterprise', 'title' => '工商信息查询', 'description' => null, 'weight' => 9],
            ['name' => 'brand', 'title' => '品牌设置', 'description' => null, 'weight' => 10],
        ];

        // 创建 setting groups 并保存到数组中，用 name 作为 key
        $groups = [];
        foreach ($settingGroupsData as $groupData) {
            $group = SettingGroup::firstOrCreate(
                ['name' => $groupData['name']],
                $groupData
            );
            $groups[$groupData['name']] = $group;
        }

        // 升级时先导入旧静态配置，再由下方默认值补齐其余缺失项。
        // 已有非空设置不覆盖，保证 Seeder 可幂等重跑。
        $this->importLegacyPlatformSettings($groups['site'], $groups['brand']);

        // 定义 settings，按 group name 分组
        $settings = [
            'site' => [
                ['key' => 'url', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '用户URL', 'weight' => 1],
                ['key' => 'name', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => 'SSL', 'description' => '站点名称', 'weight' => 2],
                ['key' => 'logo', 'type' => 'image', 'options' => null, 'is_multiple' => 0, 'value' => '', 'description' => '站点 Logo', 'weight' => 3],
                ['key' => 'logoExpanded', 'type' => 'image', 'options' => null, 'is_multiple' => 0, 'value' => '', 'description' => '展开版 Logo', 'weight' => 4],
                ['key' => 'qrcode', 'type' => 'image', 'options' => null, 'is_multiple' => 0, 'value' => '', 'description' => '客服微信二维码', 'weight' => 5],
                ['key' => 'beian', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => '', 'description' => '网站备案号', 'weight' => 6],
                ['key' => 'dnsTools', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['https://dns-tools-cn.cnssl.com', 'https://dns-tools-us.cnssl.com'], 'description' => 'DNS工具', 'weight' => 7],
                ['key' => 'delegation', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['proxyZone' => '', 'secretId' => '', 'secretKey' => ''], 'description' => 'CNAME委托', 'weight' => 8],
                ['key' => 'autoRefundOnSync', 'type' => 'boolean', 'options' => null, 'is_multiple' => 0, 'value' => false, 'description' => '上游已取消的未签发订单是否退款', 'weight' => 9],
            ],
            'ca' => [
                ['key' => 'sources', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['default' => 'Default'], 'description' => '来源', 'weight' => 1],
                ['key' => 'url', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'Default接口URL', 'weight' => 2],
                ['key' => 'token', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'Default接口令牌', 'weight' => 3],
            ],
            'mail' => [
                ['key' => 'server', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'SMTP 服务器', 'weight' => 1],
                ['key' => 'port', 'type' => 'integer', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'SMTP 端口', 'weight' => 2],
                ['key' => 'user', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'SMTP 用户', 'weight' => 3],
                ['key' => 'password', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'SMTP 密码', 'weight' => 4],
                ['key' => 'senderMail', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '发件人邮箱', 'weight' => 5],
                ['key' => 'senderName', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '发件人名称', 'weight' => 6],
            ],
            'sms' => [
                ['key' => 'gateway', 'type' => 'select', 'options' => [['label' => '阿里云', 'value' => 'aliyun'], ['label' => '腾讯云', 'value' => 'tencent'], ['label' => '华为云', 'value' => 'huawei']], 'is_multiple' => 0, 'value' => null, 'description' => '网关', 'weight' => 0],
                ['key' => 'aliyun', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['access_key_id' => null, 'access_key_secret' => null, 'sign_name' => null, 'register_template_id' => null, 'bind_template_id' => null, 'reset_template_id' => null], 'description' => '阿里云配置', 'weight' => 2],
                ['key' => 'tencent', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['sdk_app_id' => null, 'secret_id' => null, 'secret_key' => null, 'sign_name' => null, 'register_template_id' => null, 'bind_template_id' => null, 'reset_template_id' => null], 'description' => '腾讯云配置', 'weight' => 3],
                ['key' => 'huawei', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['endpoint' => null, 'app_key' => null, 'app_secret' => null, 'sender' => null, 'signature' => null, 'register_template_id' => null, 'bind_template_id' => null, 'reset_template_id' => null], 'description' => '华为云配置', 'weight' => 4],
                ['key' => 'expire', 'type' => 'integer', 'options' => null, 'is_multiple' => 0, 'value' => 600, 'description' => '验证码过期时间(秒)', 'weight' => 9],
            ],
            'alipay' => [
                ['key' => 'app_id', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '应用ID', 'weight' => 0],
                ['key' => 'app_secret_cert', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '应用私钥', 'weight' => 0],
                ['key' => 'appCertPublicKey', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '应用公钥', 'weight' => 0],
                ['key' => 'certPublicKeyRSA2', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '支付宝公钥RSA2', 'weight' => 0],
                ['key' => 'rootCert', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '支付宝根证书', 'weight' => 0],
            ],
            'wechat' => [
                ['key' => 'mch_id', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '商户号', 'weight' => 0],
                ['key' => 'mch_secret_key', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => 'v3 商户秘钥', 'weight' => 0],
                ['key' => 'apiclientKey', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '商户私钥', 'weight' => 0],
                ['key' => 'apiclientCert', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '商户公钥证书', 'weight' => 0],
                ['key' => 'publicKeyId', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '微信支付公钥ID', 'weight' => 0],
                ['key' => 'publicKey', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '微信支付公钥', 'weight' => 0],
                ['key' => 'mp_app_id', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '关联的 APP 公众号 小程序 的ID', 'weight' => 0],
            ],
            'bankAccount' => [
                ['key' => 'name', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '户名', 'weight' => 0],
                ['key' => 'account', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '账号', 'weight' => 0],
                ['key' => 'bank', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '开户行', 'weight' => 0],
            ],
            'callback' => [
                ['key' => 'default', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['sources' => 'default', 'token' => '', 'id_field' => 'id', 'allowed_ips' => ''], 'description' => '默认回调配置', 'weight' => 1],
            ],
            'enterprise' => [
                ['key' => 'url', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => '', 'description' => '接口URL', 'weight' => 1],
                ['key' => 'appCode', 'type' => 'base64', 'options' => null, 'is_multiple' => 0, 'value' => '', 'description' => 'AppCode（base64 编码存储，非加密）', 'weight' => 2],
                ['key' => 'queryField', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => 'company', 'description' => '请求参数名', 'weight' => 3],
                ['key' => 'fieldMap', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['name' => 'result.basic.name', 'registration_number' => 'result.basic.creditno', 'address' => 'result.basic.regaddress', 'state' => 'result.basic.province', 'city' => 'result.basic.city', 'regionname' => 'result.basic.regionname', 'legal_person' => 'result.basic.legalperson'], 'description' => '字段映射', 'weight' => 4],
                ['key' => 'dailyLimit', 'type' => 'integer', 'options' => null, 'is_multiple' => 0, 'value' => 100, 'description' => '全局每日查询接口上限（0 为无限）', 'weight' => 5],
            ],
            'brand' => [
                ['key' => 'admin', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => $this->defaultAdminBrands(), 'description' => '管理端品牌选项', 'weight' => 1],
                ['key' => 'user', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => $this->defaultUserBrands(), 'description' => '用户端品牌选项', 'weight' => 2],
                ['key' => 'all', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => $this->brandLabels($this->defaultAdminBrands()), 'description' => '全部品牌', 'weight' => 3],
            ],
        ];

        // 创建 settings
        foreach ($settings as $groupName => $groupSettings) {
            foreach ($groupSettings as $setting) {
                $setting['group_id'] = $groups[$groupName]->id;
                Setting::firstOrCreate(
                    ['group_id' => $setting['group_id'], 'key' => $setting['key']],
                    $setting
                );
            }
        }

        Setting::where('group_id', $groups['site']->id)
            ->whereIn('key', ['logo', 'logoExpanded', 'qrcode'])
            ->update(['type' => 'image']);

        Setting::where('group_id', $groups['site']->id)
            ->where('key', 'name')
            ->whereNull('value')
            ->update(['value' => 'SSL']);

        $groups['brand']->update(['description' => null]);

        // 迁移 site.callbackToken → callback.default.token
        $oldToken = Setting::where('group_id', $groups['site']->id)
            ->where('key', 'callbackToken')
            ->first();

        if ($oldToken) {
            $defaultSetting = Setting::where('group_id', $groups['callback']->id)
                ->where('key', 'default')
                ->first();

            if ($defaultSetting && $oldToken->value) {
                $config = $defaultSetting->value;
                if (empty($config['token'])) {
                    $config['token'] = $oldToken->value;
                    $defaultSetting->update(['value' => $config]);
                }
            }

            $oldToken->delete();
        }
    }

    private function importLegacyPlatformSettings(SettingGroup $siteGroup, SettingGroup $brandGroup): void
    {
        $legacy = $this->readLegacyConfigs();

        $legacyTitle = $this->legacyString($legacy, ['user', 'admin'], 'Title');
        if ($legacyTitle !== '') {
            $name = Setting::firstOrNew(['group_id' => $siteGroup->id, 'key' => 'name']);
            // 旧版 Seeder 会预先写入默认值 SSL；它不代表运营商定制，允许旧静态 Title 接管。
            // 其他非空值视为已迁入后台或人工修改，不再被旧文件覆盖。
            if (! $name->exists || in_array($name->getRawOriginal('value'), [null, '', 'SSL'], true)) {
                $name->fill([
                    'type' => 'string',
                    'options' => null,
                    'is_multiple' => false,
                    'value' => $legacyTitle,
                    'description' => '站点名称',
                    'weight' => 2,
                ])->save();
            }
        }

        $legacyBeian = $this->legacyString($legacy, ['user'], 'Beian');
        if ($legacyBeian !== '') {
            $beian = Setting::firstOrNew(['group_id' => $siteGroup->id, 'key' => 'beian']);
            if (! $beian->exists || in_array($beian->getRawOriginal('value'), [null, ''], true)) {
                $beian->fill([
                    'type' => 'string',
                    'options' => null,
                    'is_multiple' => false,
                    'value' => $legacyBeian,
                    'description' => '网站备案号',
                    'weight' => 6,
                ])->save();
            }
        }

        foreach (['admin', 'user'] as $side) {
            $brands = $this->legacyBrandValues($legacy[$side] ?? null);
            if ($brands === null) {
                continue;
            }

            if ($side === 'admin') {
                Setting::firstOrCreate(
                    ['group_id' => $brandGroup->id, 'key' => 'all'],
                    [
                        'type' => 'array',
                        'options' => null,
                        'is_multiple' => false,
                        'value' => $this->brandLabels($brands),
                        'description' => '全部品牌',
                        'weight' => 3,
                    ],
                );
            }

            Setting::firstOrCreate(
                ['group_id' => $brandGroup->id, 'key' => $side],
                [
                    'type' => 'array',
                    'options' => null,
                    'is_multiple' => false,
                    'value' => $brands,
                    'description' => $side === 'admin' ? '管理端品牌选项' : '用户端品牌选项',
                    'weight' => $side === 'admin' ? 1 : 2,
                ],
            );
        }
    }

    /** @return array{admin: array<string, mixed>|null, user: array<string, mixed>|null} */
    private function readLegacyConfigs(): array
    {
        $result = ['admin' => null, 'user' => null];
        foreach (['admin', 'user'] as $side) {
            $path = storage_path("app/legacy-platform-config/$side.json");
            if (! File::isFile($path)) {
                continue;
            }

            $decoded = json_decode(File::get($path), true);
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

    /** @return list<string>|null */
    private function legacyBrandValues(?array $config): ?array
    {
        if (! is_array($config) || ! array_key_exists('Brands', $config) || ! is_array($config['Brands'])) {
            return null;
        }

        $result = [];
        $seen = [];
        foreach ($config['Brands'] as $brand) {
            if (! is_string($brand)) {
                continue;
            }

            $value = mb_strtolower(trim($brand));
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $result[] = $value;
        }

        return $result;
    }

    /** @return array<string, string> */
    private function brandDictionary(): array
    {
        return [
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
    }

    /**
     * @param  list<string>  $brands
     * @return array<string, string>
     */
    private function brandLabels(array $brands): array
    {
        $dictionary = $this->brandDictionary();
        $result = [];
        foreach ($brands as $brand) {
            $value = mb_strtolower(trim($brand));
            if ($value === '' || isset($result[$value]) || ! isset($dictionary[$value])) {
                continue;
            }

            $result[$value] = $dictionary[$value];
        }

        return $result;
    }

    /** @return list<string> */
    private function defaultAdminBrands(): array
    {
        return [
            'cnssl',
            'certum',
            'gogetssl',
            'positive',
            'keeptrust',
            'rapid',
            'geotrust',
            'digicert',
            'ssltrus',
        ];
    }

    /** @return list<string> */
    private function defaultUserBrands(): array
    {
        return [
            'cnssl',
            'certum',
            'gogetssl',
            'positive',
            'keeptrust',
            'ssltrus',
            'rapid',
            'geotrust',
            'digicert',
        ];
    }
}
