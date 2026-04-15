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

    /**
     * Bir vaqtning o'zida bir nechta mahsulot yaratish.
     * Body: { category_id, type, names: ["Item 1", "Item 2"] }
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', 'in:serial,bulk'],
            'names' => ['required', 'array', 'min:1', 'max:100'],
            'names.*' => ['required', 'string', 'max:255'],
        ]);

        $created = collect($data['names'])
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn ($name) => Product::create([
                'category_id' => $data['category_id'],
                'type' => $data['type'],
                'name' => $name,
            ]))
            ->values();

        $created->each->load('category:id,name');

        return response()->json([
            'created' => $created,
            'count' => $created->count(),
        ], 201);
    }
}
