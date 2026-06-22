<?php

return [
    // ca（小写）→ {委托前缀 prefix, 是否精确匹配子域 exact}
    // exact=true：精确到子域、查找拒绝回落根域；exact=false：可回落根域（一条覆盖子域）
    // exact 默认全 false（当前对接 CA 均允许回落）；每家可被独立 env 覆盖为 true
    'ca_map' => [
        'sectigo' => ['prefix' => '_pki-validation', 'exact' => env('DELEGATION_SECTIGO_EXACT', false)],
        'certum' => ['prefix' => '_certum', 'exact' => env('DELEGATION_CERTUM_EXACT', false)],
        'digicert' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_DIGICERT_EXACT', false)],
        'globalsign' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_GLOBALSIGN_EXACT', false)],
        'trustasia' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_TRUSTASIA_EXACT', false)],
        'sheca' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_SHECA_EXACT', false)],
        'cfca' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_CFCA_EXACT', false)],
        'wotrus' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_WOTRUS_EXACT', false)],
    ],

    'default' => ['prefix' => '_dnsauth', 'exact' => env('DELEGATION_DEFAULT_EXACT', false)],
];
