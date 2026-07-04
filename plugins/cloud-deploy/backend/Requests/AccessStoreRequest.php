<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;
use Plugins\CloudDeploy\Requests\Concerns\ValidatesAgainstSchema;

class AccessStoreRequest extends BaseRequest
{
    use ValidatesAgainstSchema;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'provider' => 'required|string|max:30|in:aliyun,tencent',
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
            $this->validateCredentialsSchema(
                $validator,
                (string) $this->input('provider'),
                (array) $this->input('credentials', []),
            );
        });
    }
}
