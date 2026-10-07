<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\FoodResource;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FoodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q']));

        return FoodResource::collection(Food::query()
            ->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('name_id', 'like', '%'.$term.'%'))
            ->orderBy('name')->paginate(20));
    }

    public function show(int $id): FoodResource
    {
        return new FoodResource(Food::findOrFail($id));
    }
}
