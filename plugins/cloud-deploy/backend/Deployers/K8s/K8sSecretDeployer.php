<?php

namespace Plugins\CloudDeploy\Deployers\K8s;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Kubernetes Secret（内联型）。
 *
 * 对齐 certimate k8s-secret：把证书写入指定命名空间的 TLS Secret。
 * - GET Secret：存在则合并 data/annotations/labels 后 PUT 覆盖；404 则 POST 新建。
 * - data 各键值（tls.crt / tls.key 等）以 base64 编码写入（K8s Secret.data 约定 base64）。
 * - annotations 自动注入证书 common-name / subject-alt-names（取自叶子证书解析）。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。
 * - tls.crt（secretDataKeyForCrt）写完整链（叶子 cert + 中间 chain，对齐 certimate certPEM）。
 * - tls.key（secretDataKeyForKey）写私钥。
 * - secretDataKeyForCrtOnlyServer / secretDataKeyForCrtOnlyIntermedia（选填）分别写仅服务器证书 / 仅中间证书。
 *
 * 鉴权 Bearer Token（provider 凭证 server + token + 可选 ca_cert）。
 * config：namespace（必填）/ secret_name（必填）/ secret_type（必填）
 *   / data_key_crt（默认 tls.crt）/ data_key_key（默认 tls.key）
 *   / data_key_crt_server（选填）/ data_key_crt_intermedia（选填）
 *   / secret_annotations、secret_labels（选填 JSON 对象）。
 */
class K8sSecretDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'k8s';
    }

    public function product(): string
    {
        return 'secret';
    }

    public function label(): string
    {
        return 'Kubernetes Secret';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'namespace', 'label' => '命名空间', 'type' => 'string', 'required' => true],
            ['key' => 'secret_name', 'label' => 'Secret 名称', 'type' => 'string', 'required' => true],
            ['key' => 'secret_type', 'label' => 'Secret 类型', 'type' => 'string', 'required' => true],
            ['key' => 'data_key_crt', 'label' => '证书数据键（默认 tls.crt）', 'type' => 'string', 'required' => false],
            ['key' => 'data_key_key', 'label' => '私钥数据键（默认 tls.key）', 'type' => 'string', 'required' => false],
            ['key' => 'data_key_crt_server', 'label' => '仅服务器证书数据键（选填）', 'type' => 'string', 'required' => false],
            ['key' => 'data_key_crt_intermedia', 'label' => '仅中间证书数据键（选填）', 'type' => 'string', 'required' => false],
            ['key' => 'secret_annotations', 'label' => 'Secret 注解（JSON 对象，选填）', 'type' => 'string', 'required' => false],
            ['key' => 'secret_labels', 'label' => 'Secret 标签（JSON 对象，选填）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server:string,token:string,ca_cert?:string}  $credentials
     * @param  array{namespace:string,secret_name:string,secret_type?:string,data_key_crt?:string,data_key_key?:string,data_key_crt_server?:string,data_key_crt_intermedia?:string,secret_annotations?:string|array<string,mixed>,secret_labels?:string|array<string,mixed>}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $namespace = (string) $this->requireConfig($config, 'namespace');
        $secretName = (string) $this->requireConfig($config, 'secret_name');
        // schema 对新目标标必填；存量 target 可能没有该键，继续兼容旧默认值。
        $secretType = isset($config['secret_type']) && (string) $config['secret_type'] !== ''
            ? (string) $config['secret_type']
            : 'kubernetes.io/tls';
        $dataKeyCrt = isset($config['data_key_crt']) && (string) $config['data_key_crt'] !== ''
            ? (string) $config['data_key_crt']
            : 'tls.crt';
        $dataKeyKey = isset($config['data_key_key']) && (string) $config['data_key_key'] !== ''
            ? (string) $config['data_key_key']
            : 'tls.key';
        $dataKeyCrtServer = isset($config['data_key_crt_server']) ? (string) $config['data_key_crt_server'] : '';
        $dataKeyCrtIntermedia = isset($config['data_key_crt_intermedia']) ? (string) $config['data_key_crt_intermedia'] : '';
        $customAnnotations = $this->parseStringMap($config['secret_annotations'] ?? null, 'secret_annotations');
        $customLabels = $this->parseStringMap($config['secret_labels'] ?? null, 'secret_labels');

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        // 完整链（叶子 + 中间）= certimate Deploy 入参 certPEM
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);
        $privkeyPEM = $certRef['key'];

        // 解析叶子证书，注入 annotations（common-name / subject-alt-names）
        $annotations = array_merge($this->buildAnnotations($serverCertPEM), $customAnnotations);

        // 组装 Secret.data（base64 编码）。data_key_key / data_key_crt 恒有非空默认值，直接写入。
        $data = [
            $dataKeyKey => base64_encode($privkeyPEM),
            $dataKeyCrt => base64_encode($fullChainPEM),
        ];
        if ($dataKeyCrtServer !== '') {
            $data[$dataKeyCrtServer] = base64_encode($serverCertPEM);
        }
        if ($dataKeyCrtIntermedia !== '') {
            $data[$dataKeyCrtIntermedia] = base64_encode($intermediaPEM);
        }

        $this->guardSdk(function () use ($credentials, $namespace, $secretName, $secretType, $annotations, $customLabels, $data) {
            /** @var K8sClient $client */
            $client = $this->makeClient('api', $credentials);

            $existing = $client->getSecret($namespace, $secretName);
            if ($existing === null) {
                $client->createSecret($namespace, [
                    'apiVersion' => 'v1',
                    'kind' => 'Secret',
                    'type' => $secretType,
                    'metadata' => [
                        'name' => $secretName,
                        'namespace' => $namespace,
                        'annotations' => $annotations,
                        'labels' => $customLabels,
                    ],
                    'data' => $data,
                ]);

                return;
            }

            // 合并到既有 Secret：保留原 metadata，覆盖 type + 合并 annotations/data
            $metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : [];
            $metadata['name'] = $secretName;
            $metadata['namespace'] = $namespace;
            $metadata['annotations'] = array_merge(
                is_array($metadata['annotations'] ?? null) ? $metadata['annotations'] : [],
                $annotations,
            );
            $metadata['labels'] = array_merge(
                is_array($metadata['labels'] ?? null) ? $metadata['labels'] : [],
                $customLabels,
            );

            $existing['apiVersion'] = 'v1';
            $existing['kind'] = 'Secret';
            $existing['type'] = $secretType;
            $existing['metadata'] = $metadata;
            $existing['data'] = array_merge(
                is_array($existing['data'] ?? null) ? $existing['data'] : [],
                $data,
            );

            $client->replaceSecret($namespace, $secretName, $existing);
        });
    }

    /**
     * 解析叶子证书 → annotations（common-name / subject-alt-names）。解析失败时不注入（best-effort，对齐
     * certimate 仅作元信息标注）。
     *
     * @return array<string,string>
     */
    private function buildAnnotations(string $serverCertPEM): array
    {
        $annotations = [];
        $parsed = @openssl_x509_parse($serverCertPEM);
        if (is_array($parsed)) {
            $cn = $parsed['subject']['CN'] ?? '';
            if (is_string($cn) && $cn !== '') {
                $annotations['certimate/common-name'] = $cn;
            }
            $san = $parsed['extensions']['subjectAltName'] ?? '';
            if (is_string($san) && $san !== '') {
                // "DNS:a.com, DNS:b.com" → "a.com,b.com"
                $names = array_map(
                    fn (string $s): string => trim(preg_replace('/^\s*DNS:/i', '', $s) ?? $s),
                    explode(',', $san),
                );
                $annotations['certimate/subject-alt-names'] = implode(',', array_filter($names));
            }
            $subjectSerial = $parsed['subject']['serialNumber'] ?? '';
            if (is_string($subjectSerial)) {
                $annotations['certimate/subject-sn'] = $subjectSerial;
            }
            $issuerSerial = $parsed['issuer']['serialNumber'] ?? '';
            if (is_string($issuerSerial)) {
                $annotations['certimate/issuer-sn'] = $issuerSerial;
            }
            $issuerOrg = $parsed['issuer']['O'] ?? '';
            if (is_array($issuerOrg)) {
                $issuerOrg = implode(',', array_map('strval', $issuerOrg));
            }
            if (is_string($issuerOrg)) {
                $annotations['certimate/issuer-org'] = $issuerOrg;
            }
        }

        return $annotations;
    }

    /**
     * @return array<string,string>
     */
    private function parseStringMap(mixed $value, string $key): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            $this->fail("配置 $key 必须是 JSON 对象");
        }

        $result = [];
        foreach ($value as $mapKey => $mapValue) {
            if (! is_string($mapKey) || (! is_string($mapValue) && ! is_numeric($mapValue) && ! is_bool($mapValue))) {
                $this->fail("配置 $key 必须是字符串键值对象");
            }
            $result[$mapKey] = is_bool($mapValue) ? ($mapValue ? 'true' : 'false') : (string) $mapValue;
        }

        return $result;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $connection = $this->resolveConnection($credentials);
        $options = [
            'timeout' => 30,
            'verify' => $connection['ca_cert'] !== '' ? $this->credentialFile($connection['ca_cert'], 'k8s_ca_') : false,
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($connection['token'] !== '') {
            $options['headers']['Authorization'] = 'Bearer '.$connection['token'];
        }
        if ($connection['client_cert'] !== '') {
            $options['cert'] = $this->credentialFile($connection['client_cert'], 'k8s_cert_');
        }
        if ($connection['client_key'] !== '') {
            $options['ssl_key'] = $this->credentialFile($connection['client_key'], 'k8s_key_');
        }

        return match ($kind) {
            'api' => new K8sClient($this->outboundHttpClient(rtrim($connection['server'], '/').'/api/v1/', $options)),
        };
    }

    /** @param array<string,mixed> $credentials @return array{server:string,token:string,ca_cert:string,client_cert:string,client_key:string} */
    protected function resolveConnection(array $credentials): array
    {
        $kubeConfig = trim((string) ($credentials['kube_config'] ?? ''));
        if ($kubeConfig !== '') {
            $parsed = Yaml::parse($kubeConfig);
            if (! is_array($parsed)) {
                $this->fail('kubeconfig 格式无效');
            }
            $contextName = (string) ($parsed['current-context'] ?? '');
            $context = $this->namedEntry((array) ($parsed['contexts'] ?? []), $contextName, 'context');
            $cluster = $this->namedEntry((array) ($parsed['clusters'] ?? []), (string) ($context['cluster'] ?? ''), 'cluster');
            $user = $this->namedEntry((array) ($parsed['users'] ?? []), (string) ($context['user'] ?? ''), 'user');

            return [
                'server' => (string) ($cluster['server'] ?? ''),
                'token' => (string) ($user['token'] ?? ''),
                'ca_cert' => $this->decodeKubeData((string) ($cluster['certificate-authority-data'] ?? '')),
                'client_cert' => $this->decodeKubeData((string) ($user['client-certificate-data'] ?? '')),
                'client_key' => $this->decodeKubeData((string) ($user['client-key-data'] ?? '')),
            ];
        }

        $server = (string) ($credentials['server'] ?? '');
        $token = (string) ($credentials['token'] ?? '');
        $ca = (string) ($credentials['ca_cert'] ?? '');
        if ($server === '' && getenv('KUBERNETES_SERVICE_HOST') !== false) {
            $host = (string) getenv('KUBERNETES_SERVICE_HOST');
            $port = (string) (getenv('KUBERNETES_SERVICE_PORT_HTTPS') ?: '443');
            $server = 'https://'.$host.':'.$port;
            $token = $this->readServiceAccountFile('token');
            $ca = $this->readServiceAccountFile('ca.crt');
        }
        if ($server === '') {
            $this->fail('Kubernetes 凭证缺少 kube_config 或 server');
        }

        return ['server' => $server, 'token' => $token, 'ca_cert' => $ca, 'client_cert' => '', 'client_key' => ''];
    }

    /** @param array<int,mixed> $entries @return array<string,mixed> */
    private function namedEntry(array $entries, string $name, string $payloadKey): array
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && (string) ($entry['name'] ?? '') === $name && is_array($entry[$payloadKey] ?? null)) {
                return $entry[$payloadKey];
            }
        }
        $this->fail("kubeconfig 未找到 $payloadKey '$name'");
    }

    private function decodeKubeData(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            $this->fail('kubeconfig 包含无效的 base64 证书数据');
        }

        return $decoded;
    }

    private function readServiceAccountFile(string $name): string
    {
        $contents = @file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/'.$name);

        return is_string($contents) ? trim($contents) : '';
    }

    /**
     * 把 CA PEM 落临时文件供 Guzzle `verify` 使用（Guzzle 仅接受文件路径，不接受内联 PEM）。
     * 临时文件随进程退出由系统清理；内容仅 CA 公钥、非敏感。
     */
    private function credentialFile(string $contents, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            return '';
        }
        file_put_contents($path, $contents);
        chmod($path, 0600);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    protected function sanitize(Throwable $e): string
    {
        return K8sErrorSanitizer::sanitize($e);
    }
}
