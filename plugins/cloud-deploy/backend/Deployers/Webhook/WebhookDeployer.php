<?php

namespace Plugins\CloudDeploy\Deployers\Webhook;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Webhook 回调（内联型）。
 *
 * 对齐 certimate webhook：证书签发后回调用户自定义 HTTP 地址，把证书内容通过变量替换注入请求。
 * - 谓词：GET/POST/PUT/PATCH/DELETE（默认 POST，来自 credentials.method）。
 * - 内容类型：由 headers 的 Content-Type 决定（默认 application/json；支持 form / multipart）。
 * - 请求数据：credentials.data 或 config.webhook_data（JSON）；未配置时默认 {name, cert, privkey}。
 * - 变量替换（递归扫描字符串值 + URL path）：
 *     ${CERTIMATE_DEPLOYER_CERTIFICATE}            完整链（叶子 + 中间）
 *     ${CERTIMATE_DEPLOYER_CERTIFICATE_SERVER}     仅服务器证书
 *     ${CERTIMATE_DEPLOYER_CERTIFICATE_INTERMEDIA} 仅中间证书
 *     ${CERTIMATE_DEPLOYER_PRIVATEKEY}             私钥
 *     ${CERTIMATE_DEPLOYER_SUBJECTALTNAMES}        SAN（; 分隔）
 *     ${CERTIMATE_DEPLOYER_COMMONNAME}             CN
 *   兼容旧版：${CERTIFICATE} ${SERVER_CERTIFICATE} ${INTERMEDIA_CERTIFICATE} ${PRIVATE_KEY}
 *             ${DOMAIN} ${DOMAINS}
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，full = cert + chain（= certimate certPEM）。
 * config：webhook_data（选填，覆盖 credentials.data）/ headers（选填，多行 Key: Value，与 credentials.headers 合并）
 *   / timeout（选填，秒）。
 */
class WebhookDeployer extends AbstractDeployer
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const ALLOWED_CONTENT_TYPES = [
        WebhookClient::CONTENT_TYPE_JSON,
        WebhookClient::CONTENT_TYPE_FORM,
        WebhookClient::CONTENT_TYPE_MULTIPART,
    ];

    public function provider(): string
    {
        return 'webhook';
    }

    public function product(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Webhook';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'webhook_data', 'label' => '回调数据（JSON，覆盖凭证默认数据）', 'type' => 'string', 'required' => false, 'secret' => true],
            ['key' => 'headers', 'label' => '附加请求标头（多行 Key: Value）', 'type' => 'string', 'required' => false, 'secret' => true],
            ['key' => 'timeout', 'label' => '请求超时（秒，默认 30）', 'type' => 'number', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{url:string,method?:string,headers?:string,data?:string,allow_insecure?:mixed}  $credentials
     * @param  array{webhook_data?:string,headers?:string,timeout?:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $url = (string) ($credentials['url'] ?? '');
        if ($url === '') {
            $this->fail('缺少 Webhook 回调地址 url');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $this->fail("不支持的 Webhook 地址协议: $scheme");
        }

        // 谓词
        $method = strtoupper(trim((string) ($credentials['method'] ?? '')));
        if ($method === '') {
            $method = 'POST';
        } elseif (! in_array($method, self::ALLOWED_METHODS, true)) {
            $this->fail("不支持的 Webhook 请求谓词: $method");
        }

        // 标头：credentials.headers + config.headers 合并（config 覆盖同名）
        $headers = array_merge(
            $this->parseHeaders((string) ($credentials['headers'] ?? '')),
            $this->parseHeaders(isset($config['headers']) ? (string) $config['headers'] : ''),
        );

        // 内容类型（由 Content-Type 头决定；缺省 json 并补头）
        $contentType = $this->resolveHeader($headers, 'Content-Type');
        if ($contentType === '') {
            $contentType = WebhookClient::CONTENT_TYPE_JSON;
            $headers['Content-Type'] = WebhookClient::CONTENT_TYPE_JSON;
        } else {
            $mediaType = trim(explode(';', $contentType)[0]);
            if (! in_array($mediaType, self::ALLOWED_CONTENT_TYPES, true)) {
                $this->fail("不支持的 Webhook 内容类型: $contentType");
            }
            $contentType = $mediaType;
        }

        // 证书材料
        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);
        $privkeyPEM = $certRef['key'];
        [$commonName, $sans] = $this->parseCertNames($serverCertPEM);
        $sanJoined = implode(';', $sans);

        // 请求数据
        $rawData = $config['webhook_data'] ?? ($credentials['data'] ?? '');
        $rawData = is_string($rawData) ? $rawData : '';
        if ($rawData === '') {
            $data = [
                'name' => $sanJoined,
                'cert' => $fullChainPEM,
                'privkey' => $privkeyPEM,
            ];
        } else {
            $decoded = json_decode($rawData, true);
            if (! is_array($decoded)) {
                $this->fail('Webhook 回调数据不是合法 JSON');
            }
            $data = $decoded;
            // GET / form / multipart 需扁平 map<string,string>（与 certimate 一致：JSON 重新摊平）
            if ($method === 'GET' || $contentType === WebhookClient::CONTENT_TYPE_FORM || $contentType === WebhookClient::CONTENT_TYPE_MULTIPART) {
                $data = $this->flattenToStringMap($data);
            }
        }

        // 变量替换（新版 + 旧版兼容）
        $replacements = [
            '${CERTIMATE_DEPLOYER_COMMONNAME}' => $commonName,
            '${CERTIMATE_DEPLOYER_SUBJECTALTNAMES}' => $sanJoined,
            '${CERTIMATE_DEPLOYER_CERTIFICATE}' => $fullChainPEM,
            '${CERTIMATE_DEPLOYER_CERTIFICATE_SERVER}' => $serverCertPEM,
            '${CERTIMATE_DEPLOYER_CERTIFICATE_INTERMEDIA}' => $intermediaPEM,
            '${CERTIMATE_DEPLOYER_PRIVATEKEY}' => $privkeyPEM,
            // 兼容旧版变量
            '${DOMAIN}' => $commonName,
            '${DOMAINS}' => $sanJoined,
            '${CERTIFICATE}' => $fullChainPEM,
            '${SERVER_CERTIFICATE}' => $serverCertPEM,
            '${INTERMEDIA_CERTIFICATE}' => $intermediaPEM,
            '${PRIVATE_KEY}' => $privkeyPEM,
        ];
        $data = $this->replaceRecursively($data, $replacements);
        // URL path 仅替换 CN/DOMAIN（与 certimate 一致），并做 path 转义
        $url = str_replace(
            ['${CERTIMATE_DEPLOYER_COMMONNAME}', '${DOMAIN}'],
            [rawurlencode($commonName), rawurlencode($commonName)],
            $url,
        );

        $timeout = isset($config['timeout']) && (int) $config['timeout'] > 0 ? (int) $config['timeout'] : 30;
        $url = $this->authorizedOutboundUrl($url);
        $credentials['url'] = $url;

        $this->guardSdk(function () use ($credentials, $method, $url, $headers, $contentType, $data, $timeout) {
            /** @var WebhookClient $client */
            $client = $this->makeClient('http', $credentials);
            $client->send($method, $url, $headers, $contentType, $data, $timeout);
        });
    }

    /**
     * 解析多行 `Key: Value` 标头为 map。空行跳过；无冒号的行跳过。
     *
     * @return array<string,string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key !== '') {
                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    /**
     * 大小写不敏感取标头值。
     *
     * @param  array<string,string>  $headers
     */
    private function resolveHeader(array $headers, string $name): string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return '';
    }

    /**
     * 解析叶子证书 → [commonName, sans[]]。解析失败回 ['', []]（best-effort，对齐 certimate 容错）。
     *
     * @return array{0:string,1:list<string>}
     */
    private function parseCertNames(string $serverCertPEM): array
    {
        $parsed = @openssl_x509_parse($serverCertPEM);
        if (! is_array($parsed)) {
            return ['', []];
        }
        $cn = $parsed['subject']['CN'] ?? '';
        $cn = is_string($cn) ? $cn : '';

        $sans = [];
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $name = trim(preg_replace('/^\s*DNS:/i', '', $entry) ?? $entry);
                if ($name !== '') {
                    $sans[] = $name;
                }
            }
        }

        return [$cn, $sans];
    }

    /**
     * 把任意 JSON 结构摊平为 map<string,string>（嵌套值 JSON 序列化为字符串），用于 GET/form/multipart。
     *
     * @param  array<mixed>  $data
     * @return array<string,string>
     */
    private function flattenToStringMap(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $out[(string) $k] = $v;
            } elseif (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            } else {
                $out[(string) $k] = (string) json_encode($v);
            }
        }

        return $out;
    }

    /**
     * 递归替换字符串值里的变量（对齐 certimate replaceJsonValueRecursively）。
     *
     * @param  array<string,string>  $replacements
     */
    private function replaceRecursively(mixed $data, array $replacements): mixed
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->replaceRecursively($v, $replacements);
            }

            return $data;
        }
        if (is_string($data)) {
            return strtr($data, $replacements);
        }

        return $data;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'http' => new WebhookClient($this->outboundAbsoluteHttpClient((string) ($credentials['url'] ?? ''), [
                'verify' => ! $this->truthy($credentials['allow_insecure'] ?? null),
            ])),
        };
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    protected function sanitize(Throwable $e): string
    {
        return WebhookErrorSanitizer::sanitize($e);
    }
}
