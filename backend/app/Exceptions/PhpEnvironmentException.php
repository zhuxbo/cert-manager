<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * PHP 运行环境不满足新版本需求清单时抛出。
 * 携带 EnvironmentChecker::summarize() 产出的结构化 details，
 * 由 UpgradeService 的 catch 块传给 status.json 的 error_details 字段。
 */
class PhpEnvironmentException extends RuntimeException
{
    /**
     * @param  array  $details  结构化失败上下文（type/current_php/missing_extensions/disabled_functions/remediation/...）
     */
    public function __construct(string $message, protected array $details = [])
    {
        parent::__construct($message);
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
