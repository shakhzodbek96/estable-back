<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreShopRequest;
use App\Http\Requests\Admin\UpdateShopRequest;
use App\Models\Shop;
use App\Support\TenantMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

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
        TenantMedia::delete($shop->image_path);

        $shop->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Do'kon rasmini yuklash (S3). Eski rasm almashtiriladi.
     */
    public function uploadImage(Request $request, Shop $shop): JsonResponse
    {
        $request->validate([
            'image' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(5 * 1024), // 5 MB
            ],
        ]);

        // Kalit: <tenant-id>/shop/<uuid>.<ext> — eski rasm almashtiriladi.
        $path = TenantMedia::store($request->file('image'), $shop, $shop->image_path);

        $shop->update(['image_path' => $path]);

        $shop->load('creator:id,name,username');

        return response()->json($shop);
    }

    /**
     * Do'kon rasmini o'chirish.
     */
    public function deleteImage(Shop $shop): JsonResponse
    {
        if ($shop->image_path) {
            TenantMedia::delete($shop->image_path);
            $shop->update(['image_path' => null]);
        }

        $shop->load('creator:id,name,username');

        return response()->json($shop);
    }
}
