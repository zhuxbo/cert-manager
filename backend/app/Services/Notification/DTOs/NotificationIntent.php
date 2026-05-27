<?php

namespace App\Services\Notification\DTOs;

readonly class NotificationIntent
{
    public function __construct(
        public string $code,
        public string $notifiableType,
        public int $notifiableId,
        public array $context = []
    ) {}
}
