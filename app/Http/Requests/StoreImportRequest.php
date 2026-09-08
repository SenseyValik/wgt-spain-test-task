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
     * The @example annotations are what fill the "Try it" panel on /docs/api with a body
     * that can be sent as-is. `supplier-a` and `supplier-b` are what the seeder creates.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Supplier code. The seeder creates `supplier-a` and `supplier-b`.
             *
             * @example "supplier-a"
             */
            'supplier' => ['required', 'string', 'exists:suppliers,code'],

            /**
             * The supplier's own id for this import. Unique per supplier: resending the same
             * one returns the existing record and queues no second job.
             *
             * @example "import-2026-09-01-001"
             */
            'external_import_id' => ['required', 'string', 'max:191'],

            /**
             * When the supplier sent the import.
             *
             * @example "2026-09-01T10:00:00Z"
             */
            'sent_at' => ['required', 'date'],

            'offers' => ['required', 'array', 'min:1'],

            /**
             * The supplier's own id for this offer. Unique per supplier — an offer already
             * seen under a different import is updated and repointed, never duplicated.
             *
             * `distinct` matters: the same external_id twice in one payload would upsert
             * twice and inflate processed_offers.
             *
             * @example "offer-a-10001"
             */
            'offers.*.external_id' => ['required', 'string', 'max:191', 'distinct'],

            /**
             * Global catalogue key. Two suppliers offering the same code resolve to one
             * property, which is what makes "cheapest offer per property" comparable.
             *
             * @example "BCN-0001"
             */
            'offers.*.property.code' => ['required', 'string', 'max:64'],

            /** @example "Apartment near Sagrada Familia" */
            'offers.*.property.name' => ['required', 'string', 'max:255'],

            /** @example "Barcelona" */
            'offers.*.property.city' => ['required', 'string', 'max:120'],

            /** @example "2026-10-10" */
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],

            /** @example "2026-10-15" */
            'offers.*.check_out' => ['required', 'date_format:Y-m-d', 'after:offers.*.check_in'],

            /** @example 4 */
            'offers.*.max_guests' => ['required', 'integer', 'min:1'],

            /**
             * Price in minor units — 72500 is 725.00 EUR. Integers keep the cheapest-offer
             * ordering exact.
             *
             * @example 72500
             */
            'offers.*.price' => ['required', 'integer', 'min:0'],

            /** @example "EUR" */
            'offers.*.currency' => ['required', 'string', 'size:3'],

            /** @example 2 */
            'offers.*.available_units' => ['required', 'integer', 'min:0'],

            /**
             * After this moment the offer is no longer actual and drops out of the search.
             *
             * @example "2026-12-31T23:59:59Z"
             */
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
