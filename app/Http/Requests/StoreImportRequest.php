<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    /**
     * Every offer is validated here, at the HTTP boundary, because the Job later reads the
     * payload back out of jsonb and trusts it. This is the only point where a bad shape can
     * still be reported to the caller as a 422.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'exists:suppliers,code'],
            'external_import_id' => ['required', 'string', 'max:191'],
            'sent_at' => ['required', 'date'],

            'offers' => ['required', 'array', 'min:1'],
            // `distinct` matters: the same external_id twice in one payload would upsert
            // twice and inflate processed_offers.
            'offers.*.external_id' => ['required', 'string', 'max:191', 'distinct'],
            'offers.*.property.code' => ['required', 'string', 'max:64'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:120'],
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d', 'after:offers.*.check_in'],
            'offers.*.max_guests' => ['required', 'integer', 'min:1'],
            'offers.*.price' => ['required', 'integer', 'min:0'],
            'offers.*.currency' => ['required', 'string', 'size:3'],
            'offers.*.available_units' => ['required', 'integer', 'min:0'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier.exists' => 'The supplier :input is not known.',
        ];
    }
}
