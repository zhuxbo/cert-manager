<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Requests\Concerns\ValidatesAgainstSchema;

class AccessStoreRequest extends BaseRequest
{
    use ValidatesAgainstSchema;

    public function rules(): array
    {
        return [
            'user_id' => 'sometimes|integer|min:1',
            'name' => 'required|string|max:100',
            'provider' => 'required|string|max:30',
            'credentials' => 'required|array',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['provider', 'credentials'])) {
                return; // 基础规则已失败，schema 校验无意义
            }
            $provider = (string) $this->input('provider');
            if (! app(Registry::class)->hasProvider($provider)) {
                $validator->errors()->add('provider', "未注册的云平台：$provider");

                return;
            }

            $this->validateCredentialsSchema(
                $validator,
                $provider,
                (array) $this->input('credentials', []),
            );
        });
    }
}
