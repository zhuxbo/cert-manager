<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;
use App\Models\Order;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|max:'.config('batch.max_ids'),
            'ids.*' => 'integer',
        ];
    }

    /**
     * 验证后处理
     */
    protected function passedValidation(): void
    {
        $ids = $this->input('ids', []);
        $existingIds = Order::whereIn('id', $ids)->pluck('id')->toArray();
        $this->merge(['ids' => $existingIds]);
    }
}
