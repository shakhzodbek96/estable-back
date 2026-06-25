<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttributeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAttributeDefinitionRequest;
use App\Http\Requests\Admin\UpdateAttributeDefinitionRequest;
use App\Models\AttributeDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeDefinitionController extends Controller
{
    /**
     * Atribut ta'riflari ro'yxati. Soni kam bo'lgani uchun paginatsiyasiz —
     * sort_order, keyin nom bo'yicha tartiblangan to'liq ro'yxat.
     *
     * Query:
     *   - applies_to=serial|bulk — shu tovar turiga mos (both ham kiradi)
     *   - active=1                — faqat aktivlar (formalar uchun)
     */
    public function index(Request $request): JsonResponse
    {
        $query = AttributeDefinition::query();

        if ($appliesTo = $request->string('applies_to')->trim()->value()) {
            if ($scope = AttributeScope::tryFrom($appliesTo)) {
                $query->forScope($scope);
            }
        }

        if ($request->boolean('active')) {
            $query->active();
        }

        $items = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $items]);
    }

    public function store(StoreAttributeDefinitionRequest $request): JsonResponse
    {
        $definition = AttributeDefinition::create($this->normalize($request->validated()));

        return response()->json($definition, 201);
    }

    public function show(AttributeDefinition $attributeDefinition): JsonResponse
    {
        return response()->json($attributeDefinition);
    }

    public function update(UpdateAttributeDefinitionRequest $request, AttributeDefinition $attributeDefinition): JsonResponse
    {
        $attributeDefinition->update($this->normalize($request->validated()));

        return response()->json($attributeDefinition);
    }

    public function destroy(AttributeDefinition $attributeDefinition): JsonResponse
    {
        // Eski tovarlardagi qiymatlar snapshot — ta'rif o'chsa ham buzilmaydi.
        $attributeDefinition->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * select bo'lmagan tiplarda options'ni tozalab qo'yamiz (eski yozuv qolib ketmasin).
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('type', $data) && $data['type'] !== 'select') {
            $data['options'] = null;
        }

        return $data;
    }
}
