<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConsignmentDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Consignment\StoreConsignmentRequest;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\Partner;
use App\Services\ConsignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsignmentController extends Controller
{
    public function __construct(
        private ConsignmentService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Consignment::with([
            'partner:id,name,balance',
            'shop:id,name',
            'creator:id,name',
        ])->withCount('items');

        if ($direction = $request->string('direction')->trim()->value()) {
            $query->where('direction', $direction);
        }

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($partnerId = $request->integer('partner_id')) {
            $query->where('partner_id', $partnerId);
        }

        $perPage = max(1, min($request->integer('per_page', 20), 100));

        return response()->json(
            $query->orderByDesc('created_at')->paginate($perPage)
        );
    }

    public function store(StoreConsignmentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $consignment = $data['direction'] === 'outgoing'
                ? $this->service->createOutgoing($data)
                : $this->service->createIncoming($data);

            $consignment->load(['partner:id,name', 'items', 'shop:id,name']);

            return response()->json($consignment, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Consignment $consignment): JsonResponse
    {
        $consignment->load([
            'partner',
            'items.inventory.product:id,name',
            'items.accessory.product:id,name',
            'items.sale:id,sale_date,total_price',
            'shop:id,name',
            'creator:id,name',
        ]);

        return response()->json($consignment);
    }

    public function returnItems(Request $request, Consignment $consignment): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.consignment_item_id' => 'required|integer|exists:consignment_items,id',
            'items.*.quantity' => 'integer|min:1',
        ]);

        try {
            $result = $this->service->returnItems($consignment, $request->input('items'));
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Consignment $consignment): JsonResponse
    {
        try {
            $result = $this->service->cancel($consignment);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reportSale(Request $request): JsonResponse
    {
        $request->validate([
            'consignment_item_id' => 'required|integer|exists:consignment_items,id',
            'quantity' => 'integer|min:1',
        ]);

        try {
            $item = ConsignmentItem::findOrFail($request->input('consignment_item_id'));
            $this->service->reportOutgoingSale($item, $request->input('quantity', 1));
            return response()->json(['message' => 'Sotuv qayd qilindi']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function payPartner(Request $request): JsonResponse
    {
        $request->validate([
            'partner_id' => 'required|integer|exists:partners,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|in:usd,uzs',
            'rate' => 'nullable|numeric',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $partner = Partner::findOrFail($request->input('partner_id'));
            $transaction = $this->service->payToPartner($partner, $request->input('amount'), $request->only(['currency', 'rate', 'note']));
            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function partnerConsignments(Partner $partner, Request $request): JsonResponse
    {
        $query = Consignment::with(['items', 'shop:id,name'])
            ->where('partner_id', $partner->id);

        if ($direction = $request->string('direction')->trim()->value()) {
            $query->where('direction', $direction);
        }

        return response()->json(
            $query->orderByDesc('created_at')->get()
        );
    }
}
