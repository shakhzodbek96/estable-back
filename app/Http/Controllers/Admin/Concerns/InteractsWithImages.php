<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Image;
use App\Services\ImageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

/**
 * Polimorfik rasm endpoint'lari uchun umumiy logika (Product, Accessory, ...).
 * Controller route-model-binding bilan bog'langan modelni shu metodlarga uzatadi.
 */
trait InteractsWithImages
{
    protected function storeImages(Request $request, Model $owner, ImageService $service): JsonResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024),
            ],
        ]);

        $service->addMany($owner, $request->file('images'));

        return response()->json($owner->load('images'));
    }

    protected function setPrimaryImage(Model $owner, Image $image, ImageService $service): JsonResponse
    {
        $this->assertOwnsImage($owner, $image);
        $service->setPrimary($owner, $image);

        return response()->json($owner->load('images'));
    }

    protected function destroyImage(Model $owner, Image $image, ImageService $service): JsonResponse
    {
        $this->assertOwnsImage($owner, $image);
        $service->delete($owner, $image);

        return response()->json($owner->load('images'));
    }

    private function assertOwnsImage(Model $owner, Image $image): void
    {
        if (
            $image->imageable_type !== $owner->getMorphClass()
            || (int) $image->imageable_id !== (int) $owner->getKey()
        ) {
            abort(404);
        }
    }
}
