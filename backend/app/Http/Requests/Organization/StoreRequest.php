<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\BaseRequest;

class StoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:100',
            'registration_number' => 'required|string|max:50',
            'country' => 'required|string|max:50',
            'state' => 'required|string|max:50',
            'city' => 'required|string|max:50',
            'address' => 'required|string|max:255',
            'postcode' => 'nullable|string|min:3|max:20',
            'phone' => 'nullable|string|max:20',
            'contact_id' => 'nullable|integer',
            'contact' => 'nullable|array',
            'contact.first_name' => 'required_with:contact|string|max:50',
            'contact.last_name' => 'nullable|string|max:50',
            'contact.identification_number' => 'nullable|string|max:50',
            'contact.title' => 'nullable|string|max:100',
            'contact.email' => 'required_with:contact|email|max:100',
            'contact.phone' => 'required_with:contact|string|max:50',
        ];
    }
}
