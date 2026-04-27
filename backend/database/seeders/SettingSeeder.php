<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Database\Seeder;

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

        // 定义 settings，按 group name 分组
        $settings = [
            'site' => [
                ['key' => 'url', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '用户URL', 'weight' => 1],
                ['key' => 'name', 'type' => 'string', 'options' => null, 'is_multiple' => 0, 'value' => null, 'description' => '站点名称', 'weight' => 2],
                ['key' => 'dnsTools', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['cn' => 'https://dns-tools-cn.cnssl.com', 'us' => 'https://dns-tools-us.cnssl.com'], 'description' => 'DNS工具', 'weight' => 6],
                ['key' => 'delegation', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['proxyZone' => '', 'secretId' => '', 'secretKey' => ''], 'description' => 'CNAME委托', 'weight' => 7],
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
                ['key' => 'default', 'type' => 'array', 'options' => null, 'is_multiple' => 0, 'value' => ['sources' => '', 'token' => '', 'id_field' => 'id', 'allowed_ips' => ''], 'description' => '默认回调配置', 'weight' => 1],
            ],
        ];

        // 创建 settings
        foreach ($settings as $groupName => $groupSettings) {
            if (isset($groups[$groupName])) {
                foreach ($groupSettings as $setting) {
                    $setting['group_id'] = $groups[$groupName]->id;
                    Setting::firstOrCreate(
                        ['group_id' => $setting['group_id'], 'key' => $setting['key']],
                        $setting
                    );
                }
            }
        }

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
}
