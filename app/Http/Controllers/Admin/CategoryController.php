<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Support\TenantMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json($category, 201);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        TenantMedia::delete($category->image);

        $category->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Kategoriya muqova rasmini yuklash (S3, bitta rasm — eski almashtiriladi).
     */
    public function uploadImage(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'image' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024),
            ],
        ]);

        // Kalit: <tenant>/category/<uuid>.<ext>
        $path = TenantMedia::store($request->file('image'), $category, $category->image);
        $category->update(['image' => $path]);

        return response()->json($category);
    }

    /**
     * Kategoriya rasmini o'chirish.
     */
    public function deleteImage(Category $category): JsonResponse
    {
        if ($category->image) {
            TenantMedia::delete($category->image);
            $category->update(['image' => null]);
        }

        return response()->json($category);
    }
}
