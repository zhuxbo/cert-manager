<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

/**
 * @property mixed $group_id
 */
class StoreRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'group_id' => 'required|integer|exists:setting_groups,id',
            'key' => [
                'required',
                'string',
                'max:100',
                Rule::unique('settings')->where(function ($query) {
                    return $query->where('group_id', $this->input('group_id'));
                }),
            ],
            'type' => ['required', 'string', Rule::in(['string', 'integer', 'float', 'boolean', 'array', 'select', 'base64', 'image'])],
            'options' => [
                'required_if:type,select',
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($this->input('type') === 'select') {
                        if (! is_array($value)) {
                            $fail('选项配置必须是数组格式。');

                            return;
                        }

                        foreach ($value as $item) {
                            if (! is_array($item) || ! isset($item['label']) || ! isset($item['value'])) {
                                $fail('数组中的每个选项必须包含 "label" 和 "value" 属性。');

                                return;
                            }
                        }
                    }
                },
            ],
            'is_multiple' => 'sometimes|boolean',
            'value' => 'nullable',
            'description' => 'nullable|string|max:500',
            'weight' => 'integer|min:0|max:10000',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->has('is_multiple') || $this->input('is_multiple') === null) {
            $this->merge([
                'is_multiple' => false,
            ]);
        }
        if ($this->input('type') !== 'select' && ! $this->has('options')) {
            $this->merge([
                'options' => [],
            ]);
        }
    }
}
