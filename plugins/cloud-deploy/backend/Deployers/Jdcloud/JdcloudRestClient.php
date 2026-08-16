<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * 京东云 REST 薄客户端（SSL 证书中心 / CDN / 直播 / 点播 / 负载均衡 / WAF）。
 *
 * 京东云无模块化官方 PHP SDK，照 certimate 引用的 jdcloud-sdk-go core（Signer.go / JdcloudClient.go /
 * ParameterBuilder.go）用 GuzzleHttp（来自主系统 vendor）直调 REST，并手写 JDCLOUD2-HMAC-SHA256 签名
 * （AWS SigV4 改）。逐字节对齐 Go 实现：
 *   - URL = https://{endpoint}/{version}{path}（version=v1，path 含已替换的 path 参数）。
 *   - GET/DELETE：参数进 query string（url.Values.Encode 风格：按 key 排序、RFC3986 编码、空格 %20），
 *     body 为空 → bodyDigest = 空串 SHA256。
 *   - POST/PUT/PATCH：参数进 JSON body，无 query → bodyDigest = body SHA256。
 *   - 签名头：x-jdcloud-date(Ymd\THis\Z UTC) + x-jdcloud-nonce(uuidv4) 参与签名（仅 Authorization /
 *     User-Agent / X-Jdcloud-Request-Id 不参与）。host 头取 endpoint host。
 *   - key chain：HMAC("JDCLOUD2"+secret, shortdate) → region → service → "jdcloud2_request" → stringToSign。
 *   - region 缺省 "jdcloud-api"（与 Go 一致，使网关能验签）。
 *
 * 鉴权放 Authorization 请求头（非 URL 查询串、非 body），故 endpoint host / URL / body 均不含 AK/SK，
 * 签名值也不进异常。错误归一：HTTP 非 2xx 或 响应体 error.code 非空 → 抛 JdcloudApiException（携京东云
 * 错误码 + 响应体 error.message，均来自响应体、不含凭证）。网络/解码失败由调用方 guardSdk + sanitizer 兜底。
 *
 * 此类是 deployer 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock（按方法名打桩）即
 * 覆盖全部 REST 调用，无需打真实 HTTP。签名纯函数 buildAuthorization 另有固定输入→输出单测。
 */
class JdcloudRestClient
{
    private const ALGORITHM = 'JDCLOUD2-HMAC-SHA256';

    private const SDK_VERSION = '1.x';

    /** SHA256 of an empty string（对齐 Go core emptyStringSHA256，GET/DELETE bodyDigest 用）。 */
    private const EMPTY_PAYLOAD_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private ClientInterface $http;

    /**
     * 测试钩子：返回当前签名时间（DateTimeImmutable，UTC）。默认 now。签名单测注入固定时间。
     *
     * @var Closure():\DateTimeImmutable
     */
    private Closure $now;

    /**
     * 测试钩子：返回 nonce（uuidv4 字符串）。默认随机。签名单测注入固定 nonce。
     *
     * @var Closure():string
     */
    private Closure $nonce;

    /**
     * @param  string  $endpoint  服务 endpoint host（如 ssl.jdcloud-api.com）
     * @param  string  $serviceName  服务名（签名 credential scope 用，如 ssl/lb/cdn/live/vod/waf）
     * @param  string  $revision  服务 SDK Revision（User-Agent 用，对齐 certimate 各 client 声明）
     * @param  array{access_key_id?:string,access_key_secret?:string}  $credentials
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $serviceName,
        private readonly string $revision,
        private readonly array $credentials,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            // socket 超时上限（避免上游慢/挂时 worker 长期阻塞）
            'connect_timeout' => 10,
            'timeout' => 30,
            'http_errors' => false,
        ]);
        $this->now = static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->nonce = static fn (): string => self::uuidV4();
    }

    // ===================== SSL 证书中心 =====================

    /**
     * 上传证书到京东云 SSL 证书中心。
     * REF: certimate jdcloud-ssl Upload —— POST /v1/sslCert:upload {certName, certFile, keyFile} → {certId}
     *
     * @return string 云端证书 certId
     */
    public function uploadCert(string $certName, string $certFile, string $keyFile): string
    {
        $result = $this->send('POST', '/sslCert:upload', body: [
            'certName' => $certName,
            'certFile' => $certFile,
            'keyFile' => $keyFile,
        ]);

        $certId = $result['result']['certId'] ?? null;

        return is_string($certId) ? $certId : '';
    }

    // ===================== CDN =====================

    /**
     * 查询 CDN 域名配置（取 httpsJumpType）。
     * REF: certimate jdcloud-cdn QueryDomainConfig —— GET /v1/domain/{domain}/config
     *
     * @return string httpsJumpType（沿用到 SetHttpType）
     */
    public function queryCdnDomainHttpsJumpType(string $domain): string
    {
        $result = $this->send('GET', '/domain/'.$domain.'/config');
        $jump = $result['result']['httpsJumpType'] ?? null;

        return is_string($jump) ? $jump : '';
    }

    /**
     * 设置 CDN 域名 HTTPS 协议 + 绑定 SSL 证书中心证书。
     * REF: certimate jdcloud-cdn SetHttpType —— POST /v1/domain/{domain}/httpType
     *   {httpType=https, certFrom=ssl, sslCertId, jumpType}
     */
    public function setCdnHttpType(string $domain, string $sslCertId, string $jumpType): void
    {
        $this->send('POST', '/domain/'.$domain.'/httpType', body: [
            'httpType' => 'https',
            'certFrom' => 'ssl',
            'sslCertId' => $sslCertId,
            'jumpType' => $jumpType,
        ]);
    }

    /** @return list<string> */
    public function listCdnDomains(): array
    {
        $domains = [];
        $page = 1;
        $pageSize = 50;
        do {
            $result = $this->send('GET', '/domains', query: ['pageNumber' => $page, 'pageSize' => $pageSize]);
            $items = is_array($result['result']['domains'] ?? null) ? $result['result']['domains'] : [];
            foreach ($items as $item) {
                if (! is_array($item) || ($item['status'] ?? null) === 'offline') {
                    continue;
                }
                $domain = $item['domain'] ?? null;
                if (is_string($domain) && $domain !== '') {
                    $domains[] = $domain;
                }
            }
            $page++;
        } while (count($items) === $pageSize);

        return array_values(array_unique($domains));
    }

    // ===================== 直播 Live（内联 PEM） =====================

    /**
     * 设置直播流域名证书（直灌 PEM）。
     * REF: certimate jdcloud-live SetLiveDomainCertificate —— POST /v1/liveDomainCertificate
     *   {playDomain, certStatus=on, cert, key}
     */
    public function setLiveDomainCertificate(string $playDomain, string $certPem, string $keyPem): void
    {
        $this->send('POST', '/liveDomainCertificate', body: [
            'playDomain' => $playDomain,
            'certStatus' => 'on',
            'cert' => $certPem,
            'key' => $keyPem,
        ]);
    }

    /** @return list<string> */
    public function listLiveDomains(): array
    {
        $domains = [];
        $page = 1;
        $pageSize = 100;
        do {
            $result = $this->send('GET', '/liveDomains', query: ['pageNum' => $page, 'pageSize' => $pageSize]);
            $details = $result['result']['domainDetails'] ?? [];
            $details = is_array($details) ? $details : [];
            foreach ($details as $detail) {
                foreach (is_array($detail['playDomains'] ?? null) ? $detail['playDomains'] : [] as $play) {
                    if (! is_array($play) || in_array($play['domainStatus'] ?? '', ['offline', 'checking', 'check_failed'], true)) {
                        continue;
                    }
                    if (is_string($play['playDomain'] ?? null) && $play['playDomain'] !== '') {
                        $domains[] = $play['playDomain'];
                    }
                }
            }
            $page++;
        } while (count($details) === $pageSize);

        return array_values(array_unique($domains));
    }

    // ===================== 点播 VOD（内联 PEM） =====================

    /**
     * 按域名查 VOD 域名 ID（分页扫 ListDomains）。
     * REF: certimate jdcloud-vod findDomainIdByDomain —— GET /v1/domains?pageNumber&pageSize
     *
     * @return int|null 命中返回 domainId（int），未命中 null
     */
    public function findVodDomainId(string $domain): ?int
    {
        $pageNumber = 1;
        $pageSize = 100;
        while (true) {
            $result = $this->send('GET', '/domains', query: [
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
            ]);
            $content = $result['result']['content'] ?? null;
            $content = is_array($content) ? $content : [];

            foreach ($content as $item) {
                if (is_array($item) && ($item['name'] ?? null) === $domain) {
                    return (int) ($item['id'] ?? 0);
                }
            }

            if (count($content) < $pageSize) {
                break;
            }
            $pageNumber++;
        }

        return null;
    }

    /** @return list<array{id:int,name:string}> */
    public function listVodDomains(): array
    {
        $domains = [];
        $page = 1;
        $pageSize = 100;
        do {
            $result = $this->send('GET', '/domains', query: ['pageNumber' => $page, 'pageSize' => $pageSize]);
            $content = $result['result']['content'] ?? [];
            $content = is_array($content) ? $content : [];
            foreach ($content as $item) {
                if (! is_array($item) || in_array($item['status'] ?? '', ['init', 'stopped'], true)) {
                    continue;
                }
                $name = $item['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $domains[] = ['id' => (int) ($item['id'] ?? 0), 'name' => $name];
                }
            }
            $page++;
        } while (count($content) === $pageSize);

        return $domains;
    }

    /**
     * 查询 VOD 域名 SSL 配置（取 jumpType）。
     * REF: certimate jdcloud-vod GetHttpSsl —— GET /v1/domains/{domainId}:getHttpSsl
     */
    public function getVodHttpSslJumpType(int $domainId): string
    {
        $result = $this->send('GET', '/domains/'.$domainId.':getHttpSsl');
        $jump = $result['result']['jumpType'] ?? null;

        return is_string($jump) ? $jump : '';
    }

    /**
     * 设置 VOD 域名 SSL 配置（直灌 PEM）。
     * REF: certimate jdcloud-vod SetHttpSsl —— POST /v1/domains/{domainId}:setHttpSsl
     *   {source=default, title, sslCert, sslKey, jumpType, enabled=true}
     */
    public function setVodHttpSsl(int $domainId, string $title, string $certPem, string $keyPem, string $jumpType): void
    {
        $this->send('POST', '/domains/'.$domainId.':setHttpSsl', body: [
            'source' => 'default',
            'title' => $title,
            'sslCert' => $certPem,
            'sslKey' => $keyPem,
            'jumpType' => $jumpType,
            'enabled' => true,
        ]);
    }

    // ===================== WAF =====================

    /**
     * 绑定证书到 WAF 防护域名。
     * REF: certimate jdcloud-waf BindCert —— POST /v1/regions/{regionId}/wafInstanceIds/{wafInstanceId}/cert:bindCert
     *   body {req:{wafInstanceId, domain, certId}}（regionId/wafInstanceId 为 path 参数）
     */
    public function bindWafCert(string $regionId, string $wafInstanceId, string $domain, string $certId): void
    {
        $path = '/regions/'.$regionId.'/wafInstanceIds/'.$wafInstanceId.'/cert:bindCert';
        $this->send('POST', $path, body: [
            'req' => [
                'wafInstanceId' => $wafInstanceId,
                'domain' => $domain,
                'certId' => $certId,
            ],
        ]);
    }

    // ===================== 负载均衡 ALB（lb 服务） =====================

    /**
     * 查询负载均衡监听器详情（取 extensionCertificateSpecs）。
     * REF: certimate jdcloud-alb DescribeListener —— GET /v1/regions/{regionId}/listeners/{listenerId}
     *
     * @return array{extensionCertificateSpecs: list<array{certificateBindId?:string,domain?:string}>}
     */
    public function describeAlbListener(string $regionId, string $listenerId): array
    {
        $result = $this->send('GET', '/regions/'.$regionId.'/listeners/'.$listenerId);
        $listener = $result['result']['listener'] ?? null;
        $extSpecs = is_array($listener) && is_array($listener['extensionCertificateSpecs'] ?? null)
            ? $listener['extensionCertificateSpecs']
            : [];

        return ['extensionCertificateSpecs' => array_values($extSpecs)];
    }

    /**
     * 分页查询负载均衡器下监听器，返回 https/tls 协议的 listenerId 列表。
     * REF: certimate jdcloud-alb DescribeListeners —— GET /v1/regions/{regionId}/listeners/?filters...
     *   filters.1.name=loadBalancerId & filters.1.values.1={id}
     *
     * @return list<string> https/tls 监听器 ID
     */
    public function listAlbHttpsListenerIds(string $regionId, string $loadBalancerId): array
    {
        $ids = [];
        $pageNumber = 1;
        $pageSize = 100;
        while (true) {
            $result = $this->send('GET', '/regions/'.$regionId.'/listeners/', query: [
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
                'filters.1.name' => 'loadBalancerId',
                'filters.1.values.1' => $loadBalancerId,
            ]);
            $listeners = $result['result']['listeners'] ?? null;
            $listeners = is_array($listeners) ? $listeners : [];

            foreach ($listeners as $listener) {
                if (! is_array($listener)) {
                    continue;
                }
                $protocol = strtolower((string) ($listener['protocol'] ?? ''));
                $listenerId = $listener['listenerId'] ?? null;
                if (($protocol === 'https' || $protocol === 'tls') && is_string($listenerId) && $listenerId !== '') {
                    $ids[] = $listenerId;
                }
            }

            if (count($listeners) < $pageSize) {
                break;
            }
            $pageNumber++;
        }

        return $ids;
    }

    /**
     * 修改监听器默认证书（无 SNI）。
     * REF: certimate jdcloud-alb UpdateListener —— PATCH /v1/regions/{regionId}/listeners/{listenerId}
     *   {certificateSpecs:[{certificateId}]}
     */
    public function updateAlbListenerCertificate(string $regionId, string $listenerId, string $certId): void
    {
        $this->send('PATCH', '/regions/'.$regionId.'/listeners/'.$listenerId, body: [
            'certificateSpecs' => [
                ['certificateId' => $certId],
            ],
        ]);
    }

    /**
     * 批量修改监听器扩展证书（SNI）。
     * REF: certimate jdcloud-alb UpdateListenerCertificates ——
     *   POST /v1/regions/{regionId}/listeners/{listenerId}:updateListenerCertificates
     *   {certificates:[{certificateBindId, certificateId, domain}]}
     *
     * @param  list<array{certificateBindId:string,certificateId:string,domain:string}>  $certificates
     */
    public function updateAlbListenerCertificates(string $regionId, string $listenerId, array $certificates): void
    {
        $path = '/regions/'.$regionId.'/listeners/'.$listenerId.':updateListenerCertificates';
        $this->send('POST', $path, body: ['certificates' => $certificates]);
    }

    // ===================== 内部：发送 + 签名 + 错误归一 =====================

    /**
     * 发起一次京东云 REST 请求并返回解析后的 JSON（数组）。
     * GET/DELETE：参数走 query；POST/PUT/PATCH：参数走 JSON body。两者互斥（对齐 Go ParameterBuilder）。
     *
     * @param  array<string,mixed>  $query  GET/DELETE 的查询参数（标量值）
     * @param  array<string,mixed>|null  $body  POST/PUT/PATCH 的请求体
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null): array
    {
        // URL：https://{endpoint}/v1{path}；GET query 以 url.Values.Encode 风格编码后拼到 RawQuery
        // path 用 Go EscapePath（keep / + unreserved，其余含 ':' 转 %3A）转义，签名与发送同串。
        $rawQuery = $this->encodeQuery($query);
        $uriPath = self::escapePath('/v1'.$path);
        $url = 'https://'.$this->endpoint.$uriPath.($rawQuery !== '' ? '?'.$rawQuery : '');

        // body：写请求 json_encode 一次（喂签名与发送同串）；GET/DELETE 无 body
        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $date = ($this->now)();
        $nonce = ($this->nonce)();

        // 参与签名的请求头（与发送头一致）。注意：host 用 endpoint，与 Guzzle 自动设的 Host 一致。
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'CloudDeploy/'.self::SDK_VERSION.' '.$this->serviceName.'/'.$this->revision,
            'x-jdcloud-date' => $date->format('Ymd\THis\Z'),
            'x-jdcloud-nonce' => $nonce,
        ];

        $authorization = self::buildAuthorization(
            method: $method,
            host: $this->endpoint,
            uriPath: $uriPath,
            rawQuery: $rawQuery,
            headers: $headers,
            payload: $payload,
            serviceName: $this->serviceName,
            accessKeyId: (string) ($this->credentials['access_key_id'] ?? ''),
            accessKeySecret: (string) ($this->credentials['access_key_secret'] ?? ''),
            date: $date,
        );

        $sendHeaders = $headers + ['Authorization' => $authorization];

        $options = ['headers' => $sendHeaders, 'http_errors' => false];
        if ($body !== null) {
            $options['body'] = $payload;
        }

        $resp = $this->http->request($method, $url, $options);

        return $this->ensureOk($resp->getStatusCode(), (string) $resp->getBody());
    }

    /**
     * HTTP 非 2xx 或 响应体 error.code 非空 → 抛 JdcloudApiException（仅响应体来源 code + 描述，无凭证/URL）。
     *
     * @return array<string,mixed>
     */
    private function ensureOk(int $status, string $rawBody): array
    {
        $json = json_decode($rawBody, true);
        $json = is_array($json) ? $json : [];

        // 京东云业务错误：响应体 error.code 非空（HTTP 200 也可能带）
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $errCode = $error['code'] ?? null;
        if ($errCode !== null && $errCode !== '' && $errCode !== 0) {
            $msg = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : '京东云接口返回错误';
            throw new JdcloudApiException((string) $errCode, $msg);
        }

        if ($status < 200 || $status >= 300) {
            $msg = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : '京东云接口返回 HTTP '.$status;
            $code = $status > 0 ? (string) $status : 'JdcloudError';
            throw new JdcloudApiException($code, $msg);
        }

        return $json;
    }

    /**
     * 构造 JDCLOUD2-HMAC-SHA256 Authorization 头（纯函数，逐字节对齐 jdcloud-sdk-go Signer.go）。
     *
     * @param  array<string,string>  $headers  参与签名的请求头（host 单独由 $host 注入，不在此 map）
     */
    public static function buildAuthorization(
        string $method,
        string $host,
        string $uriPath,
        string $rawQuery,
        array $headers,
        string $payload,
        string $serviceName,
        string $accessKeyId,
        string $accessKeySecret,
        \DateTimeImmutable $date,
        string $region = 'jdcloud-api',
    ): string {
        $formattedTime = $date->format('Ymd\THis\Z');
        $shortTime = $date->format('Ymd');

        // canonical headers：host + 全部请求头（忽略 Authorization/User-Agent/X-Jdcloud-Request-Id），
        // key 小写、按 key 排序、值去多余空格。对齐 Go buildCanonicalHeaders。
        $ignored = ['authorization', 'user-agent', 'x-jdcloud-request-id'];
        $canon = ['host' => $host];
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            if (in_array($lk, $ignored, true)) {
                continue;
            }
            $canon[$lk] = self::stripExcessSpaces((string) $v);
        }
        ksort($canon);
        $signedHeaders = implode(';', array_keys($canon));
        $canonicalHeaders = '';
        foreach ($canon as $k => $v) {
            $canonicalHeaders .= $k.':'.$v."\n";
        }

        $bodyDigest = $payload === '' ? self::EMPTY_PAYLOAD_SHA256 : hash('sha256', $payload);

        // canonicalString = method\nURI\nRawQuery\ncanonHeaders(末尾已含\n)+\nsignedHeaders\nbodyDigest
        // 对齐 Go：join 时 canonicalHeaders 元素是 "k:v\n...k:v"（无尾\n），再 + "\n" → 这里 $canonicalHeaders
        // 已含每行尾 \n，故等价于 Go 的 "headers\n"。
        $canonicalString = implode("\n", [
            $method,
            $uriPath,
            $rawQuery,
            $canonicalHeaders, // 已含末行 \n，作为 join 的一段后会再接 \n 分隔，等价 Go 的 headers+"\n"
            $signedHeaders,
            $bodyDigest,
        ]);

        $credentialScope = implode('/', [$shortTime, $region, $serviceName, 'jdcloud2_request']);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $formattedTime,
            $credentialScope,
            hash('sha256', $canonicalString),
        ]);

        // key chain
        $kDate = hash_hmac('sha256', $shortTime, 'JDCLOUD2'.$accessKeySecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        $kCred = hash_hmac('sha256', 'jdcloud2_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kCred);

        return self::ALGORITHM
            .' Credential='.$accessKeyId.'/'.$credentialScope
            .', SignedHeaders='.$signedHeaders
            .', Signature='.$signature;
    }

    /**
     * 把参数 map 编码为 query string，对齐 Go url.Values.Encode() + ('+'→'%20')：
     * 按 key 排序，key/value RFC3986 编码，'='/'&' 连接。值转字符串（bool→1/0 不出现，调用方仅传标量）。
     *
     * @param  array<string,mixed>  $query
     */
    private function encodeQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        ksort($query);
        $parts = [];
        foreach ($query as $k => $v) {
            $parts[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
        }

        return implode('&', $parts);
    }

    /** 去掉首尾空格并把连续多空格压成单个（对齐 Go stripExcessSpaces，用于 canonical header 值）。 */
    private static function stripExcessSpaces(string $value): string
    {
        return (string) preg_replace('/ +/', ' ', trim($value));
    }

    /**
     * 转义 URL path（逐字节对齐 jdcloud-sdk-go core EscapePath(path, encodeSep=false)）：
     * 保留 A-Z a-z 0-9 - . _ ~ 与 '/'，其余字节（含 ':' → %3A，'*' → %2A，中文等多字节）转大写百分号编码。
     * 京东云的操作型 path 含 ':'（/sslCert:upload、/domains/{id}:setHttpSsl 等），必须转义且签名/发送同串。
     */
    private static function escapePath(string $path): string
    {
        $out = '';
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            $c = $path[$i];
            $o = ord($c);
            $unreserved = ($o >= 0x41 && $o <= 0x5A) // A-Z
                || ($o >= 0x61 && $o <= 0x7A) // a-z
                || ($o >= 0x30 && $o <= 0x39) // 0-9
                || $c === '-' || $c === '.' || $c === '_' || $c === '~';
            if ($unreserved || $c === '/') {
                $out .= $c;
            } else {
                $out .= '%'.strtoupper(bin2hex($c));
            }
        }

        return $out;
    }

    /** 生成 RFC 4122 v4 UUID（对齐 Go core buildNonce 的 uuid.NewV4()）。 */
    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 测试钩子：注入固定签名时间 + nonce，用于签名固定输入→输出单测。
     *
     * @param  Closure():\DateTimeImmutable  $now
     * @param  Closure():string  $nonce
     */
    public function withClockForTesting(Closure $now, Closure $nonce): void
    {
        $this->now = $now;
        $this->nonce = $nonce;
    }
}
