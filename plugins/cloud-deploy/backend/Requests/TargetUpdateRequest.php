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
            'order_id' => 'sometimes|integer',
            'product' => 'sometimes|string|max:30',
            'config' => 'sometimes|array',
            'enabled' => 'sometimes|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['access_id', 'order_id', 'product', 'config'])) {
                return;
            }
            // 仅启停不重校验 schema；改 access/product/order/config 任一结构字段时，按最终组合校验。
            if (! $this->hasAny(['access_id', 'order_id', 'product', 'config'])) {
                return;
            }

            $target = CloudDeployTarget::withoutGlobalScopes()
                ->whereKey($this->route('id'))->first();
            if ($target === null) {
                return; // 记录不存在，交给控制器 find 报错
            }

            $product = (string) ($this->input('product') ?? $target->product);
            $accessId = $this->input('access_id') ?? $target->access_id;
            $config = $this->has('config') ? (array) $this->input('config', []) : (array) $target->config;
            $provider = CloudDeployAccess::withoutGlobalScopes()->whereKey($accessId)->value('provider');
            if ($provider === null) {
                return; // 改了 access 但不存在 → 控制器 TenantConsistency 拒绝
            }

            $this->validateConfigSchema($validator, (string) $provider, $product, $config);
        });
    }
}
