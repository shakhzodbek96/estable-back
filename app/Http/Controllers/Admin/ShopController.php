<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreShopRequest;
use App\Http\Requests\Admin\UpdateShopRequest;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Shop::query()
            ->with('creator:id,name,username')
            ->withCount('users')
            ->withCount(['users as admins_count' => fn($q) => $q->where('role', 'admin')])
            ->withCount(['users as managers_count' => fn($q) => $q->where('role', 'manager')])
            ->withCount(['users as sellers_count' => fn($q) => $q->where('role', 'seller')]);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreShopRequest $request): JsonResponse
    {
        $shop = Shop::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);
        $shop->load('creator:id,name,username');

        return response()->json($shop, 201);
    }

    public function show(Shop $shop): JsonResponse
    {
        $shop->load('creator:id,name,username');

        return response()->json($shop);
    }

    public function update(UpdateShopRequest $request, Shop $shop): JsonResponse
    {
        $shop->update($request->validated());
        $shop->load('creator:id,name,username');

        return response()->json($shop);
    }

    public function destroy(Shop $shop): JsonResponse
    {
        $shop->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
