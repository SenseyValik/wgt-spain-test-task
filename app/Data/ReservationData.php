<?php

namespace App\Data;

final readonly class ReservationData
{
    public function __construct(
        public string $clientReference,
        public string $customerName,
        public string $customerEmail,
    ) {}
}
