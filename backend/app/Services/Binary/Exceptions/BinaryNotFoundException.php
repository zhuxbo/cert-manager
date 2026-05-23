<?php

namespace App\Services\Binary\Exceptions;

use RuntimeException;

/**
 * BinaryLocator 在所有候选路径中均未找到可执行二进制时抛出。
 * 携带工具名、试过的路径列表与诊断信息，便于上层呈现给运维。
 */
class BinaryNotFoundException extends RuntimeException
{
    /**
     * @param  string  $tool  二进制工具名（如 openssl/certbot）
     * @param  string[]  $triedPaths  已尝试过的绝对路径
     * @param  string[]  $diagnose  诊断信息（多行字符串，如 open_basedir/disable_functions 状态）
     */
    public function __construct(
        protected string $tool,
        protected array $triedPaths = [],
        protected array $diagnose = [],
    ) {
        parent::__construct("未找到可执行的 {$tool}（试过：".implode(', ', $triedPaths).'）');
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    /**
     * @return string[]
     */
    public function getTriedPaths(): array
    {
        return $this->triedPaths;
    }

    /**
     * @return string[]
     */
    public function diagnose(): array
    {
        return $this->diagnose;
    }
}
