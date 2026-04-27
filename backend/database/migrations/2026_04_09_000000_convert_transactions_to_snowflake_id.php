<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    const int EPOCH = 1696152000000;

    const int MAX_41_BIT = 1099511627775;

    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        // 1. 将存量自增 ID 替换为基于 created_at 的雪花 ID
        $hasAutoIncrementIds = DB::table('transactions')->where('id', '<', 4294967295)->exists();
        if ($hasAutoIncrementIds) {
            $this->convertIds();
        }

        // 2. 去掉 AUTO_INCREMENT（仅在仍为自增时执行）
        $column = collect(Schema::getColumns('transactions'))->firstWhere('name', 'id');
        if ($column && $column['auto_increment']) {
            DB::statement('ALTER TABLE `transactions` MODIFY `id` BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE `transactions` AUTO_INCREMENT = 1');
        }
    }

    private function convertIds(): void
    {
        $lastSecond = 0;
        $msOffset = 0;

        // 每次取最小的 500 条，转换后 ID 变大自然排到末尾
        // 不能用 chunk()：修改排序列会导致分页偏移错乱
        while (true) {
            $rows = DB::table('transactions')->orderBy('id')->limit(500)->get();

            if ($rows->isEmpty() || $rows->first()->id > 4294967295) {
                break;
            }

            foreach ($rows as $row) {
                $unix = $row->created_at ? strtotime($row->created_at) : 0;

                if ($unix === $lastSecond) {
                    $msOffset++;
                } else {
                    $lastSecond = $unix;
                    $msOffset = 0;
                }

                $offsetTime = ($unix * 1000 + $msOffset) - self::EPOCH;
                $base = decbin(self::MAX_41_BIT + $offsetTime);
                $newId = bindec($base.'000000');

                DB::table('transactions')->where('id', $row->id)->update(['id' => $newId]);
            }
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};
