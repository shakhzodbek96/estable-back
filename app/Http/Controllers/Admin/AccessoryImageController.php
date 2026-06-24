<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\Concerns\InteractsWithImages;
use App\Models\Accessory;
use App\Models\Image;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessoryImageController extends Controller
{
    use InteractsWithImages;

    public function store(Request $request, Accessory $accessory, ImageService $service): JsonResponse
    {
        return $this->storeImages($request, $accessory, $service);
    }

    public function primary(Accessory $accessory, Image $image, ImageService $service): JsonResponse
    {
        return $this->setPrimaryImage($accessory, $image, $service);
    }

    public function destroy(Accessory $accessory, Image $image, ImageService $service): JsonResponse
    {
        return $this->destroyImage($accessory, $image, $service);
    }
}
