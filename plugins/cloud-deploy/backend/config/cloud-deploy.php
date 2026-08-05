<?php

$privateTargets = preg_split('/\s*,\s*/', (string) env('CLOUD_DEPLOY_PRIVATE_TARGETS', ''), -1, PREG_SPLIT_NO_EMPTY);

return [
    'outbound' => [
        'private_targets' => array_values($privateTargets ?: []),
    ],
];
