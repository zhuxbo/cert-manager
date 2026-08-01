<?php

namespace App\Services\Notification;

use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationTemplateResetService
{
    public function __construct(
        private readonly NotificationTemplateSeederRegistry $seederRegistry
    ) {}

    /**
     * @param  array<int, int>  $ids
     */
    public function reset(array $ids): void
    {
        $defaults = $this->seederRegistry->defaults();

        DB::transaction(function () use ($ids, $defaults) {
            $templates = NotificationTemplate::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($templates->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'ids' => ['部分通知模板已不存在，请刷新后重试'],
                ]);
            }

            $unmanagedIds = $templates
                ->reject(fn (NotificationTemplate $template) => isset($defaults[$template->code]))
                ->pluck('id')
                ->sort()
                ->values()
                ->all();
            if ($unmanagedIds !== []) {
                throw ValidationException::withMessages([
                    'ids' => [
                        '无法重置的模板 ID：'.implode('、', $unmanagedIds)
                        .'。这些模板不是系统或已安装插件管理的模板',
                    ],
                ]);
            }

            foreach ($templates as $template) {
                $template->update($defaults[$template->code]);
            }
        });
    }
}
