#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php sync-certimate-catalog.php /path/to/certimate [output.json]\n");
    exit(1);
}

$root = rtrim($argv[1], '/');
$output = $argv[2] ?? null;

if (! is_dir($root.'/pkg/core/deployer/providers')) {
    fwrite(STDERR, "Invalid Certimate checkout: missing pkg/core/deployer/providers\n");
    exit(1);
}

$fixture = [
    'source' => [
        'repo' => 'certimate-go/certimate',
        'sha' => git($root, ['rev-parse', 'HEAD']),
        'time' => git($root, ['show', '-s', '--format=%cI', 'HEAD']),
    ],
    'core_dirs' => listCoreDirs($root),
    'deployment_provider_values' => parseDeploymentProviderValues($root),
    'sp_files' => parseSpFiles($root),
    'unsupported' => ['ftp', 'local', 'ssh'],
    'provider_aliases' => [
        'baidu' => 'baiducloud',
        'onepanel' => '1panel',
        'tencent' => 'tencentcloud',
    ],
    'plugin_slug_aliases' => [
        'aliyun.casdeploy' => 'aliyun-cas-deploy',
        'aliyun.esasaas' => 'aliyun-esa-saas',
        'apisix.certificate' => 'apisix',
        'axisnow.certificate' => 'axisnow',
        'baotapanel.site' => 'baotapanel',
        'baotapanelgo.site' => 'baotapanelgo',
        'baotawaf.site' => 'baotawaf',
        'cachefly.certificate' => 'cachefly',
        'cdnfly.cdn' => 'cdnfly',
        'cpanel.cpanel' => 'cpanel',
        'dokploy.certificate' => 'dokploy',
        'flexcdn.flexcdn' => 'flexcdn',
        'flyio.certificate' => 'flyio',
        'goedge.goedge' => 'goedge',
        'kong.certificate' => 'kong',
        'lecdn.lecdn' => 'lecdn',
        'netlify.website' => 'netlify',
        'nginxproxymanager.certificate' => 'nginxproxymanager',
        'onepanel.site' => '1panel',
        'onepanel.console' => '1panel-console',
        'huaweiibmc.console' => 'huaweiibmc',
        'proxmoxbs.node' => 'proxmoxbs',
        'proxmoxve.node' => 'proxmoxve',
        'ratpanel.site' => 'ratpanel',
        's3.s3' => 's3',
        'safeline.safeline' => 'safeline',
        'samwaf.samwaf' => 'samwaf',
        'synologydsm.certificate' => 'synologydsm',
        'tencent.eo-makers' => 'tencentcloud-eomakers',
        'ucloud.pathx' => 'ucloud-upathx',
        'vercel.certificate' => 'vercel',
        'webhook.webhook' => 'webhook',
    ],
    'source_slug_aliases' => [
        'provider_go' => [
            'aliyun-casdeploy' => 'aliyun-cas-deploy',
            'aliyun-esasaas' => 'aliyun-esa-saas',
            'tencentcloud-ssldeploy' => 'tencentcloud-ssl-deploy',
            'tencentcloud-sslupdate' => 'tencentcloud-ssl-update',
            'ucloud-pathx' => 'ucloud-upathx',
        ],
        'sp_files' => [
            'aliyun-casdeploy' => 'aliyun-cas-deploy',
            'aliyun-esasaas' => 'aliyun-esa-saas',
            'kubernetes-secret' => 'k8s-secret',
            'tencentcloud-ssldeploy' => 'tencentcloud-ssl-deploy',
            'tencentcloud-sslupdate' => 'tencentcloud-ssl-update',
        ],
    ],
];

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
if ($output === null) {
    echo $json;
} else {
    file_put_contents($output, $json);
}

/** @return list<string> */
function listCoreDirs(string $root): array
{
    $dirs = [];
    foreach (glob($root.'/pkg/core/deployer/providers/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $dirs[] = basename($dir);
    }
    sort($dirs);

    return $dirs;
}

/** @return list<string> */
function parseDeploymentProviderValues(string $root): array
{
    $file = $root.'/internal/domain/provider.go';
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException("Failed to read $file");
    }

    $access = [];
    if (preg_match_all('/(AccessProviderType[A-Za-z0-9_]+)\s*=\s*AccessProviderType\("([^"]+)"\)/', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $access[$m[1]] = $m[2];
        }
    }

    $values = [];
    if (preg_match_all('/DeploymentProviderType[A-Za-z0-9_]+\s*=\s*DeploymentProviderType\(([^)]+)\)/', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $values[] = evalGoStringExpression($m[1], $access);
        }
    }
    sort($values);

    return array_values(array_unique($values));
}

/** @return list<string> */
function parseSpFiles(string $root): array
{
    $values = [];
    foreach (glob($root.'/internal/certmgmt/deployers/sp_*.go') ?: [] as $file) {
        $name = basename($file, '.go');
        $name = preg_replace('/^sp_/', '', $name);
        $values[] = str_replace('_', '-', (string) $name);
    }
    sort($values);

    return $values;
}

/** @param array<string,string> $access */
function evalGoStringExpression(string $expr, array $access): string
{
    $value = '';
    foreach (explode('+', $expr) as $part) {
        $part = trim($part);
        if (preg_match('/^"([^"]*)"$/', $part, $m)) {
            $value .= $m[1];
        } elseif (isset($access[$part])) {
            $value .= $access[$part];
        } else {
            throw new RuntimeException("Unsupported Go expression token: $part");
        }
    }

    return $value;
}

/** @param list<string> $args */
function git(string $root, array $args): string
{
    $cmd = array_merge(['git', '-C', $root], $args);
    $escaped = array_map('escapeshellarg', $cmd);
    $output = [];
    $code = 0;
    exec(implode(' ', $escaped), $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('git command failed: '.implode(' ', $cmd));
    }

    return trim(implode("\n", $output));
}
