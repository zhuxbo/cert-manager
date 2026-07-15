<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;
use App\Services\Order\Utils\ValidatorUtil;
use Closure;
use Illuminate\Validation\Validator;

class UpdateApplicantRequest extends BaseRequest
{
    public function rules(): array
    {
        $sectionValueRule = static function (string $attribute, mixed $value, Closure $fail): void {
            if (str_ends_with($attribute, '.phone')) {
                if (! is_string($value) && ! is_int($value)) {
                    $fail(':attribute必须是字符串或整数');
                }

                return;
            }

            if (! is_string($value)) {
                $fail(':attribute必须是字符串');
            }
        };

        return [
            'organization' => 'required_without:contact|array',
            'organization.*' => [$sectionValueRule],
            'contact' => 'required_without:organization|array',
            'contact.*' => [$sectionValueRule],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            foreach (['organization', 'contact'] as $section) {
                $value = $this->input($section);
                if (! is_array($value)) {
                    continue;
                }

                $errors = $section === 'organization'
                    ? ValidatorUtil::validateOrganization($value)
                    : ValidatorUtil::validateContact($value);

                foreach ($errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("$section.$field", $message);
                    }
                }
            }
        });
    }
}
