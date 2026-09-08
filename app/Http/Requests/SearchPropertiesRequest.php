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
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'city' => ['sometimes', 'string', 'max:120'],
            // Bounded: an unbounded per_page is a trivial way to make this endpoint expensive.
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
