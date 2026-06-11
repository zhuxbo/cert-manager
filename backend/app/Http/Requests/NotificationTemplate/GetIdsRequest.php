<?php

namespace App\Http\Requests\NotificationTemplate;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:notification_templates,id');
    }
}
