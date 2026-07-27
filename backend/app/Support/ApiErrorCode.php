<?php

namespace App\Support;

/**
 * API 错误响应的机器可读标识
 *
 * 全站错误响应固定 HTTP 200 + `code=0`（统一契约，前端与所有下游都基于它），
 * 故客户端无法靠状态码区分"确定性失败"与"网络错误"，只能一律当网络错误重试——
 * 本该停止的失败（限流 / token 被禁用 / 订单不存在）被降级成无限每日重试。
 * 这些取值经 `error($msg, $errors)` 的 errors 数组下发：
 *
 *     {"code":0,"msg":"...","errors":{"error_code":"rate_limited","retry_after":100}}
 *
 * **取值一旦发布不得改动**（下游按字符串分类判定），只允许新增。
 */
final class ApiErrorCode
{
    /** 触发限流；伴随 `retry_after`（睡满即可重试的保守秒数，见 RateLimiter::checkLimit） */
    public const RATE_LIMITED = 'rate_limited';

    /** 请求未携带 token */
    public const TOKEN_MISSING = 'token_missing';

    /** token 不存在或已失效 */
    public const TOKEN_INVALID = 'token_invalid';

    /** token 本身被禁用 */
    public const TOKEN_DISABLED = 'token_disabled';

    /** token 所属账号被禁用 */
    public const ACCOUNT_DISABLED = 'account_disabled';

    /** 来源 IP 不在 token 白名单内 */
    public const IP_NOT_ALLOWED = 'ip_not_allowed';

    /** order 参数缺失或形态非法（Deploy 查询仅接受订单 ID，多个用英文逗号分隔） */
    public const INVALID_ORDER = 'invalid_order';

    /** 订单不存在或不在当前 token 的可见范围内 */
    public const ORDER_NOT_FOUND = 'order_not_found';

    /** 订单存在但没有可用证书 */
    public const CERT_NOT_FOUND = 'cert_not_found';

    /** 订单在途（unpaid/pending/processing/approving，签发进行中），不接受变更 CSR / 域名 */
    public const ORDER_IN_PROGRESS = 'order_in_progress';

    /** 订单当前状态（cancelling 与各终态）不接受变更 CSR / 域名，且不会自行回到 active，需人工介入 */
    public const ORDER_NOT_ACTIVE = 'order_not_active';

    /** 产品不支持请求的验证方式（delegation / file 系） */
    public const VALIDATION_METHOD_UNSUPPORTED = 'validation_method_unsupported';

    /** 订单未开启自动续费，续费窗口内拒绝续费 */
    public const AUTO_RENEW_DISABLED = 'auto_renew_disabled';

    /** 余额不足以支付本次续费 */
    public const INSUFFICIENT_BALANCE = 'insufficient_balance';
}
