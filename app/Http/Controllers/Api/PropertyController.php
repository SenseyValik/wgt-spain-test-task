<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    /**
     * Pure read — no service, because there is no logic for one to hold: no transaction, no
     * locking, no branching on business state. The query itself lives on the model, as a
     * scope, rather than in here.
     */
    public function index(SearchPropertiesRequest $request): AnonymousResourceCollection
    {
        $criteria = $request->criteria();

        return PropertyResource::collection(
            Property::query()
                ->withCheapestActualOffer($criteria)
                ->paginate($criteria->perPage)
        );
    }
}
