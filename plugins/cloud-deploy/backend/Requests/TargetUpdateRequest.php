<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Requests\Concerns\ValidatesAgainstSchema;

class TargetUpdateRequest extends BaseRequest
{
    use ValidatesAgainstSchema;

    public function rules(): array
    {
        return [
            'access_id' => 'sometimes|integer',
            'product' => 'sometimes|string|max:30',
            'config' => 'sometimes|array',
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
            // 仅当本次请求改动 config 才校验 schema —— 仅切 enabled / 改名的请求放行，
            // 存量 {domain} target 在不重提 config 时不被新 required 字段拦截（向后兼容读取/启停）。
            if (! $this->has('config')) {
                return;
            }

            $target = CloudDeployTarget::withoutGlobalScopes()
                ->whereKey($this->route('id'))->first();
            if ($target === null) {
                return; // 记录不存在，交给控制器 find 报错
            }

            $product = (string) ($this->input('product') ?? $target->product);
            $accessId = $this->input('access_id') ?? $target->access_id;
            $provider = CloudDeployAccess::withoutGlobalScopes()->whereKey($accessId)->value('provider');
            if ($provider === null) {
                return; // 改了 access 但不存在 → 控制器 TenantConsistency 拒绝
            }

            $this->validateConfigSchema($validator, (string) $provider, $product, (array) $this->input('config', []));
        });
    }
}
