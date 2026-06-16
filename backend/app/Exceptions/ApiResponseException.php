<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class ApiResponseException extends HttpResponseException
{
    protected array $apiResponse;

    /**
     * 创建一个新的异常实例
     */
    public function __construct(string $msg = '', ?array $errors = null, ?array $data = null, int $code = 0)
    {
        $this->apiResponse['code'] = $code;

        // 错误响应
        if ($code === 0) {
            $this->apiResponse['msg'] = $msg;
            $errors !== null && $this->apiResponse['errors'] = $errors;
        }

        // 成功响应
        if ($code === 1) {
            $data !== null && $this->apiResponse['data'] = $data;
            // 如果有消息，也添加到响应中
            ! empty($msg) && $this->apiResponse['msg'] = $msg;
        }

        // ApiResponseException extends HttpResponseException，真实 HTTP 下其 response 被 Laravel
        // 直接返回（不经 ApiExceptions 的 render callback），故必须在构造时就设 JSON_UNESCAPED_UNICODE，
        // 让业务响应（success/error/response 的唯一载体）中文直出 UTF-8 而非 \uXXXX。
        // JsonResponse 第 4 参 $options = encodingOptions（Laravel 默认 0）。
        parent::__construct(new JsonResponse($this->apiResponse, 200, [], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取响应
     */
    public function getApiResponse(): array
    {
        return $this->apiResponse;
    }
}
