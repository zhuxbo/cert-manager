<?php

namespace App\Services\Notification;

use App\Models\NotificationTemplate;

class TemplateSelector
{
    public function select(string $code): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('code', $code)
            ->where('status', 1)
            ->first();
    }
}
