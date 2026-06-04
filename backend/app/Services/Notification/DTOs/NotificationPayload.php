<?php

namespace App\Services\Notification\DTOs;

class NotificationPayload
{
    /**
     * @param  array  $data  持久化到 notifications.data 的载荷（对所有通道通用）
     * @param  array  $transient  仅渲染期注入、绝不持久化的字段（如初始密码等敏感信息）；
     *                            由 NotificationJob 在发送前合入内存数据、发送后还原，不入库
     */
    public function __construct(
        public array $data = [],
        public array $transient = []
    ) {}
}
