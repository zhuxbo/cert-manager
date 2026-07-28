<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

/**
 * @property mixed $settings
 * @property int $group_id
 */
class UpdateRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        // 判断是否为批量更新 (只更新 value，不需要复杂验证)
        if ($this->has('settings') && is_array($this->settings)) {
            return [
                'settings' => 'required|array',
                'settings.*.id' => 'required|integer|exists:settings,id',
                // 批量更新时 value 也允许为空，并放宽限制
                'settings.*.value' => 'nullable',
            ];
        }

        // 单个更新的验证规则
        return [
            'group_id' => 'required|integer|exists:setting_groups,id',
            'key' => [
                'required',
                'string',
                'max:100',
                // 唯一性检查，确保在同一 group_id 下 key 是唯一的（排除当前 ID）
                Rule::unique('settings')->where(function ($query) {
                    return $query->where('group_id', $this->input('group_id'));
                })->ignore($id),
            ],
            // 更新 type 规则，添加 select
            'type' => ['required', 'string', Rule::in(['string', 'integer', 'float', 'boolean', 'array', 'select', 'base64', 'image'])],
            // 添加 options 验证：当 type 为 select 时必须存在且为有效格式
            'options' => [
                'required_if:type,select',
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($this->input('type') === 'select') {
                        if (! is_array($value)) {
                            $fail('选项配置必须是数组格式。');

                            return;
                        }
                        // 可选：进一步校验数组内对象结构
                        foreach ($value as $item) {
                            if (! is_array($item) || ! isset($item['label']) || ! isset($item['value'])) {
                                $fail('数组中的每个选项必须包含 "label" 和 "value" 属性。');

                                return;
                            }
                        }
                    }
                },
            ],
            // 添加 is_multiple 验证：布尔值
            'is_multiple' => 'sometimes|boolean',
            // 调整 value 验证：现在允许任何类型的值
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

        // 如果不是批量更新才处理默认值
        if (! $this->has('settings')) {
            // 如果 is_multiple 未传递或为空，则默认为 false
            if (! $this->has('is_multiple') || $this->input('is_multiple') === null) {
                $this->merge([
                    'is_multiple' => false,
                ]);
            }
            // 如果 options 未传递且类型不是 select，则默认为空数组
            if ($this->input('type') !== 'select' && ! $this->has('options')) {
                $this->merge([
                    'options' => [],
                ]);
            }
        }
    }
}
