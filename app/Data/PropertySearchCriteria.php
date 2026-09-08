<?php

namespace App\Data;

final readonly class PropertySearchCriteria
{
    public function __construct(
        public string $checkIn,
        public string $checkOut,
        public int $guests,
        public ?string $city,
        public int $perPage,
    ) {}
}
