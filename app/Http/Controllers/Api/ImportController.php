<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportAcceptedResource;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Services\ImportIntakeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request, ImportIntakeService $service): JsonResponse
    {
        $import = $service->accept($request->validated());

        return ImportAcceptedResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Pure read — no service, because there is no logic for one to hold.
     */
    public function show(Import $import): ImportResource
    {
        return ImportResource::make($import->load('supplier'));
    }
}
