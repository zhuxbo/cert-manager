<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;

trait ResolvesContactId
{
    /**
     * 处理嵌套 contact_id / contact 数据，返回最终 contact_id
     *
     * 必须在 DB::transaction 内调用
     */
    protected function resolveContactId(array $data, int $userId): ?int
    {
        $contactId = $data['contact_id'] ?? null;
        $contactData = $data['contact'] ?? null;

        if ($contactId === null && $contactData !== null) {
            $attrs = array_merge(
                array_filter($contactData, fn ($v) => $v !== null),
                ['user_id' => $userId]
            );
            $contact = Contact::create($attrs);

            return $contact->id;
        }

        if ($contactId !== null) {
            $contact = Contact::where('id', $contactId)->where('user_id', $userId)->first();
            if (! $contact) {
                $this->error('联系人不存在或无权使用');
            }

            if ($contactData !== null) {
                // 过滤 null，避免把 null 写入 NOT NULL 的 last_name/first_name 列（与创建分支 array_filter 一致）
                $diff = collect($contactData)
                    ->reject(fn ($v) => $v === null)
                    ->filter(fn ($v, $k) => $v !== $contact->$k);
                if ($diff->isNotEmpty()) {
                    $contact->update($diff->all());
                }
            }

            return $contactId;
        }

        return null;
    }
}
