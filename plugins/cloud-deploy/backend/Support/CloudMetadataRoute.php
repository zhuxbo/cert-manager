<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

/**
 * 云 metadata 只允许代码内建 enum 实现路线；调用方不能传 method、URL 或 path。
 */
interface CloudMetadataRoute
{
    public function baseUri(): string;

    public function method(): string;

    /** @param array<string,string> $parameters */
    public function path(array $parameters = []): string;

    /**
     * @param  array<string,string>  $context
     * @return array<string,string>
     */
    public function headers(array $context = []): array;
}
