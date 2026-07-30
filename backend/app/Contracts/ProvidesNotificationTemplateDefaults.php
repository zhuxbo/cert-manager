<?php

namespace App\Contracts;

interface ProvidesNotificationTemplateDefaults
{
    /**
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     content: string,
     *     variables: array<int, string>,
     *     example?: string|null,
     *     status?: int
     * }>
     */
    public function notificationTemplateDefaults(): array;
}
