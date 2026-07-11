<?php

declare(strict_types=1);

namespace App\Services\Order\Api\default;

use App\Models\CaLog;
use App\Services\LogBuffer;
use App\Utils\LogScrubber;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

class Sdk
{
    /**
     * 获取产品
     */
    public function getProducts(string $brand = '', string $code = ''): array
    {
        // 30s：FPM 同步入口（Admin 导入产品），防上游挂时 worker 永久 hang
        return $this->call('get-products', ['brand' => $brand, 'code' => $code], 'get', 30);
    }

    /**
     * 获取订单
     */
    public function getOrders(int $page = 1, int $pageSize = 100, $status = 'active'): array
    {
        // 30s：防上游挂时 worker 永久 hang
        return $this->call('get-orders', ['page' => $page, 'page_size' => $pageSize, 'status' => $status], 'get', 30);
    }

    /**
     * 申请证书
     */
    public function new(array $params): array
    {
        // 45s：commit() 在 orders 行锁内同步调上游，锁内仅此一个调用，45 < innodb_lock_wait_timeout(50s)，详见 call() 取值说明
        return $this->call('new', $params, 'post', 45);
    }

    /**
     * 续费证书
     */
    public function renew(array $params): array
    {
        return $this->call('renew', $params, 'post', 45);
    }

    /**
     * 重新颁发
     */
    public function reissue(array $params): array
    {
        return $this->call('reissue', $params, 'post', 45);
    }

    /**
     * 取消证书
     */
    public function cancel(string|int $apiId): array
    {
        return $this->call('cancel', ['order_id' => $apiId], 'post', 45);
    }

    /**
     * 重新验证
     */
    public function revalidate(string|int $apiId): array
    {
        // 30s：FPM 同步入口（用户重新验证），防上游挂拖死 worker
        return $this->call('revalidate', ['order_id' => $apiId], 'post', 30);
    }

    /**
     * 修改验证方法
     */
    public function updateDCV(string|int $apiId, string $method): array
    {
        // 30s：FPM 同步入口（用户改验证方法），防上游挂拖死 worker
        return $this->call('update-dcv', ['order_id' => $apiId, 'method' => $method], 'post', 30);
    }

    /**
     * 获取订单信息
     */
    public function get(string|int $apiId, ?int $timeout = 30): array
    {
        // $timeout：默认 30s（sync 等锁外 FPM 入口，防上游挂拖死 worker；参数保留以备其它锁外调用按需收紧）
        return $this->call('get', ['order_id' => $apiId], 'get', $timeout);
    }

    /**
     * 上传文档（使用 JSON 发送，避免 base64 被 URL-encoded 二次膨胀）
     */
    public function uploadDocument(string|int $apiId, array $data): array
    {
        // 上游按 order_id 定位订单（= 本系统下发给下游的 api_id），线协议字段名不变
        // 55s：单文件 ≤5MB（控制器 max:5120 + base64 硬拒），base64 ~6.7MB 正常网络 <10s，55s 绰绰有余。
        // 取 55（< worker --timeout 60）：让 Guzzle 自己在 55s 干净断（catch→backoff 重试），
        // 而非被 worker 的 SIGALRM 在 60s 硬杀进程（靠 retry_after 300s 才复活）。原 120s 永远吃不满、且会误导。
        return $this->call('upload-document', ['order_id' => $apiId] + $data, 'json', 55);
    }

    /**
     * 提交接口请求
     */
    protected function call(string $uri, array $data = [], $method = 'post', ?int $timeout = null): array
    {
        // ca.url 未配置时 get_system_setting 返回 null；(string) 强转为 '' 走下方优雅兜底，避免 strict_types 下 rtrim(null) 抛 TypeError（对齐 Acme Sdk）
        $apiUrl = rtrim((string) get_system_setting('ca', 'url'), '/');
        $apiToken = get_system_setting('ca', 'token');

        if (! $apiUrl || ! $apiToken) {
            return ['code' => 0, 'msg' => 'Api url or token is not set'];
        }

        $url = $apiUrl.'/'.$uri;

        // 锁内调用（commit 下单 new/renew/reissue / cancel）传入 $timeout，限制持锁时长 < innodb_lock_wait_timeout(默认 50s)，
        // 否则上游慢/挂时持锁无限，并发访问同一订单行的 for update 会报 1205 锁等待超时。
        // 取值：commit 锁内**只有一个**上游调用（下单或 cancel），设 45s < 50（留 5s 裕度 + 锁内 save 开销）。
        // 锁外调用也设超时上限防 FPM worker 被上游挂死永久占用（max_execution_time 不计 socket 阻塞）：
        // 文档上传 55s（对齐 worker --timeout 60，让 Guzzle 先干净断）、其他查询/操作（sync get / getProducts /
        // getOrders / revalidate / updateDCV）30s。$timeout=null 才不限时（当前已无此调用，留作扩展通道）。
        // connect_timeout 统一封顶 10s（连接子阶段，总时长仍受 $timeout 限）。
        // manager 是多级代理：上游可能是任意深度的另一个 manager，网络路径/DNS/地域全不可控，
        // 按"不可控上游"处理，从早期 3s 放宽到 10s（对齐 callback 的 connectTimeout(10)）。
        // 10s < innodb_lock_wait_timeout(50s)，不破坏 1205 防护（总 timeout 45s 封顶不变，connect 不叠加）；
        // 黑洞上游失败慢一点，换多级链路的连接宽容，是有意取舍。
        $clientConfig = [];
        if ($timeout !== null) {
            $clientConfig['connect_timeout'] = min(10, $timeout);
            $clientConfig['timeout'] = $timeout;
        }
        $client = $this->makeClient($clientConfig);
        $startTime = microtime(true);
        try {
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$apiToken,
                ],
                'http_errors' => false,
            ];
            if ($method === 'get') {
                $options['query'] = $data;
            } elseif ($method === 'json') {
                $method = 'post';
                $options['json'] = $data;
            } else {
                $options['form_params'] = $data;
            }
            $response = $client->request($method, $url, $options);
        } catch (ConnectException) {
            // 连接失败 / 超时（含 cURL 28）：写 ca_logs 供排障，对外只给通用文案，避免再刷 error_logs。
            $result = ['code' => 0, 'msg' => '上游连接超时，请稍后重试'];
            // 超时也记一条 ca_logs（status_code=0）——超时正是「耗时」最有诊断价值的场景，
            // 否则上游变慢/挂起在 ca_logs 里完全不可见（duration≈timeout 秒）
            $this->logCall($apiUrl, $uri, $data, $result, 0, $startTime);

            return $result;
        } catch (GuzzleException) {
            // 其余 Guzzle 异常同样进入 ca_logs，避免同一次上游失败在 error_logs 重复出现。
            $result = ['code' => 0, 'msg' => '上游请求失败，请稍后重试'];
            $this->logCall($apiUrl, $uri, $data, $result, 0, $startTime);

            return $result;
        }

        $result = json_decode($response->getBody()->getContents(), true);

        $httpStatusCode = $response->getStatusCode();

        $this->logCall($apiUrl, $uri, $data, $result, $httpStatusCode, $startTime);

        // Http 状态码 200 为成功
        if ($httpStatusCode == 200) {
            if (! isset($result['code'])) {
                return ['code' => 0, 'msg' => 'No return code'];
            }

            // cancel 时，如果订单已取消，则返回成功
            if ($uri === 'cancel' && isset($result['msg']) && $result['msg'] == '订单已取消') {
                return ['code' => 1];
            }

            // 错误信息为余额不足时，返回系统内部错误
            if ($result['code'] === 0 && str_contains($result['msg'], '余额不足')) {
                $result['msg'] = '系统内部错误，请联系管理员';
            }

            if ($result['code'] != 1) {
                return ['code' => 0, 'msg' => $result['msg'] ?? 'Unknown error', 'errors' => $result['errors'] ?? null];
            }
        } else {
            return ['code' => 0, 'msg' => 'Http status code '.$httpStatusCode];
        }

        return $result;
    }

    /**
     * 写入一条 ca_logs（含请求耗时）。成功 / 超时 / 失败各路径统一经此写入，
     * duration = now - $startTime（秒，与 ACME Sdk 一致）；params/response 经 LogScrubber 脱敏（不泄露内部 URL）。
     */
    private function logCall(string $apiUrl, string $uri, array $data, ?array $result, int $httpStatusCode, float $startTime): void
    {
        LogBuffer::add(CaLog::class, [
            'url' => $apiUrl,
            'api' => $uri,
            'params' => LogScrubber::scrub($data),
            'response' => LogScrubber::scrubResponse($result),
            'status_code' => $httpStatusCode,
            'status' => intval($result['code'] ?? 0) === 1 ? 1 : 0,
            'duration' => round(microtime(true) - $startTime, 3),
        ]);
    }

    /**
     * 创建 Guzzle 客户端（注入缝：测试可覆盖以捕获 config / 注入 MockHandler）。
     */
    protected function makeClient(array $config = []): Client
    {
        return new Client($config);
    }
}
