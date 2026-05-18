<?php

namespace App\Services\EnterpriseLookup;

class LookupManager
{
    public function driver(): LookupInterface
    {
        return app(AliyunDriver::class);
    }

    public function enabled(): bool
    {
        $url = (string) get_system_setting('enterprise_lookup', 'url', '');
        $appCode = (string) get_system_setting('enterprise_lookup', 'appCode', '');
        $queryField = (string) get_system_setting('enterprise_lookup', 'queryField', '');
        $fieldMap = (array) get_system_setting('enterprise_lookup', 'fieldMap', []);

        if ($url === '' || $appCode === '' || $queryField === '') {
            return false;
        }

        // fieldMap 至少 name / registration_number / address 三个标准字段已配置 source
        foreach (['name', 'registration_number', 'address'] as $required) {
            if (empty($fieldMap[$required])) {
                return false;
            }
        }

        return true;
    }
}
