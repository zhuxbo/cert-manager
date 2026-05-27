<?php

namespace App\Services\Notification\DTOs;

class NotificationPayload
{
    public function __construct(
        public array $data = []
    ) {}
}
