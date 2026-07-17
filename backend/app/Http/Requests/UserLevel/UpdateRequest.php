<?php

namespace App\Http\Requests\UserLevel;

use App\Http\Requests\BaseRequest;

class UpdateRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $costRate = $this->input('cost_rate');
        if (is_int($costRate) || is_float($costRate) || is_string($costRate)) {
            $this->merge(['cost_rate' => (string) $costRate]);
        }
    }

    public function rules(): array
    {
        $userLevelId = $this->route('id', 0);

        return [
            'name' => 'required|string|min:3|max:20|unique:user_levels,name,'.$userLevelId,
            'code' => 'required|string|min:3|max:20|unique:user_levels,code,'.$userLevelId,
            'custom' => 'required|integer|in:0,1',
            'cost_rate' => ['required', 'regex:/^(?:[1-9]\d?)(?:\.\d{1,4})?$/'],
            'weight' => 'required|integer|min:1|max:10000',
        ];
    }
}
