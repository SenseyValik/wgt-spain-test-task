<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertyResource;
use App\Services\PropertySearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(SearchPropertiesRequest $request, PropertySearchService $service): AnonymousResourceCollection
    {
        return PropertyResource::collection($service->search($request->criteria()));
    }
}
