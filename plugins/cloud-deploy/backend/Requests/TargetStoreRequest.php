<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Requests\Concerns\ValidatesAgainstSchema;

class TargetStoreRequest extends BaseRequest
{
    use ValidatesAgainstSchema;

    public function rules(): array
    {
        return [
            'access_id' => 'required|integer',
            'order_id' => 'required|integer',
            'product' => 'required|string|max:30',
            'config' => 'required|array',
            'enabled' => 'sometimes|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['access_id', 'product', 'config'])) {
                return;
            }
            // provider 由 access 决定（同一 product 在不同 provider 下 schema 可能不同）。
            // access 不存在 → 归属校验（TenantConsistency）会在控制器拒绝，这里跳过 schema 校验。
            $provider = CloudDeployAccess::withoutGlobalScopes()
                ->whereKey($this->input('access_id'))->value('provider');
            if ($provider === null) {
                return;
            }
            $this->validateConfigSchema(
                $validator,
                (string) $provider,
                (string) $this->input('product'),
                (array) $this->input('config', []),
            );
        });
    }
}
