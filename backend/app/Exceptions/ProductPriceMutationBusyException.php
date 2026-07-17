<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ProductPriceMutationBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('产品价格正在变更，请稍后重试');
    }
}
