<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;

class DeployRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required_without:target_ids|integer',
            'target_ids' => 'required_without:order_id|array|max:100',
            'target_ids.*' => 'integer',
            'force' => 'sometimes|boolean',
        ];
    }
}
