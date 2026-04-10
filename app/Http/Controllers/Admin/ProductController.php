<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with('category:id,name');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($categoryId = $request->integer('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($type = $request->string('type')->trim()->value()) {
            $query->where('type', $type);
        }

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());
        $product->load('category:id,name');

        return response()->json($product, 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category:id,name');

        return response()->json($product);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());
        $product->load('category:id,name');

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
