<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\Concerns\InteractsWithImages;
use App\Models\Image;
use App\Models\Product;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductImageController extends Controller
{
    use InteractsWithImages;

    public function store(Request $request, Product $product, ImageService $service): JsonResponse
    {
        return $this->storeImages($request, $product, $service);
    }

    public function primary(Product $product, Image $image, ImageService $service): JsonResponse
    {
        return $this->setPrimaryImage($product, $image, $service);
    }

    public function destroy(Product $product, Image $image, ImageService $service): JsonResponse
    {
        return $this->destroyImage($product, $image, $service);
    }
}
