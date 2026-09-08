<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    /**
     * Scramble reads the 200, 404 and 422 off the code, but not these two: the created
     * status is set conditionally, and the conflict is thrown from the service.
     */
    #[PathParameter('offer', description: 'Offer id — take best_offer.id from GET /api/properties.', example: 1)]
    #[ApiResponse(201, 'A new reservation. One unit of the offer has been taken.', type: ReservationResource::class)]
    #[ApiResponse(200, 'An idempotent replay — this client_reference was already reserved, so nothing was created and no further unit was taken.', type: ReservationResource::class)]
    #[ApiResponse(409, 'The offer has expired, or has no available units left.', type: 'array{message: string, context?: array<string, mixed>}')]
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
