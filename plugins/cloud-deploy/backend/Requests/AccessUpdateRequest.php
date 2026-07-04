<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Requests\Concerns\ValidatesAgainstSchema;

class AccessUpdateRequest extends BaseRequest
{
    use ValidatesAgainstSchema;

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'provider' => 'sometimes|string|max:30|in:aliyun,tencent',
            'credentials' => 'sometimes|nullable|array',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['provider', 'credentials'])) {
                return;
            }
            $credentials = $this->input('credentials');
            // 留空 / null = 保留原凭证（控制器 unset），无新凭证可校验
            if (! is_array($credentials) || $credentials === []) {
                return;
            }

            $provider = $this->input('provider') ?? $this->resolveExistingProvider();
            if ($provider === null) {
                return; // 记录不存在，交给控制器 find 报错
            }
            $this->validateCredentialsSchema($validator, (string) $provider, $credentials);
        });
    }

    /** 从被更新记录取原 provider（未传 provider 时校验需要）。 */
    private function resolveExistingProvider(): ?string
    {
        $id = $this->route('id');
        if ($id === null) {
            return null;
        }

        return CloudDeployAccess::withoutGlobalScopes()->whereKey($id)->value('provider');
    }
}
