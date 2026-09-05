<?php

namespace App\Services\Upgrade;

use Illuminate\Cache\Console\ClearCommand;

class CutoverCacheClearCommand extends ClearCommand
{
    public function handle(): int
    {
        if (RuntimeSessionCutover::isPending()) {
            $this->components->warn('会话切库尚未完成，暂时保留应用缓存中的旧 JWT 黑名单。');

            return self::SUCCESS;
        }

        return parent::handle();
    }
}
