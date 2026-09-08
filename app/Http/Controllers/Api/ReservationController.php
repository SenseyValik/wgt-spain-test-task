<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function store(
        StoreReservationRequest $request,
        Offer $offer,
        ReservationService $service
    ): JsonResponse {
        $reservation = $service->reserve($offer, $request->toData());

        // 200 for an idempotent replay: this request did not create anything, so 201
        // would not be true.
        return ReservationResource::make($reservation)
            ->response()
            ->setStatusCode($reservation->wasRecentlyCreated
                ? Response::HTTP_CREATED
                : Response::HTTP_OK);
    }
}
