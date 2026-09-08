<?php

namespace App\Http\Requests;

use App\Data\PropertySearchCriteria;
use Illuminate\Foundation\Http\FormRequest;

class SearchPropertiesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @example "2026-10-10" */
            'check_in' => ['required', 'date_format:Y-m-d'],

            /** @example "2026-10-15" */
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],

            /**
             * Only offers that sleep at least this many guests. Defaults to 1.
             *
             * @example 2
             */
            'guests' => ['sometimes', 'integer', 'min:1'],

            /**
             * Optional city filter, matched exactly.
             *
             * @example "Barcelona"
             */
            'city' => ['sometimes', 'string', 'max:120'],

            /**
             * Bounded: an unbounded per_page is a trivial way to make this endpoint expensive.
             *
             * @example 15
             */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function criteria(): PropertySearchCriteria
    {
        return new PropertySearchCriteria(
            checkIn: $this->string('check_in')->toString(),
            checkOut: $this->string('check_out')->toString(),
            guests: $this->integer('guests', 1),
            city: $this->filled('city') ? $this->string('city')->toString() : null,
            perPage: $this->integer('per_page', 15),
        );
    }
}
