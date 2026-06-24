<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\Concerns\InteractsWithImages;
use App\Models\Image;
use App\Models\Inventory;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryImageController extends Controller
{
    use InteractsWithImages;

    public function store(Request $request, Inventory $inventory, ImageService $service): JsonResponse
    {
        return $this->storeImages($request, $inventory, $service);
    }

    public function primary(Inventory $inventory, Image $image, ImageService $service): JsonResponse
    {
        return $this->setPrimaryImage($inventory, $image, $service);
    }

    public function destroy(Inventory $inventory, Image $image, ImageService $service): JsonResponse
    {
        return $this->destroyImage($inventory, $image, $service);
    }
}
