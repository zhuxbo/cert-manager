<?php

namespace App\Services\Notification;

use App\Models\NotificationTemplate;

class TemplateSelection
{
    public function __construct(protected ?NotificationTemplate $template = null) {}

    public function isEmpty(): bool
    {
        return $this->template === null;
    }

    public function template(): ?NotificationTemplate
    {
        return $this->template;
    }
}
