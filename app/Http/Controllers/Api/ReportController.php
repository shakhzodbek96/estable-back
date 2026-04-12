<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $service
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->service->dashboard($request->only([
            'date_from', 'date_to', 'shop_id',
        ])));
    }

    public function profit(Request $request): JsonResponse
    {
        return response()->json($this->service->profit($request->only([
            'date_from', 'date_to', 'shop_id', 'group_by',
        ])));
    }

    public function inventory(Request $request): JsonResponse
    {
        return response()->json($this->service->inventory($request->only([
            'shop_id', 'category_id', 'type',
        ])));
    }

    public function topProducts(Request $request): JsonResponse
    {
        return response()->json($this->service->topProducts($request->only([
            'date_from', 'date_to', 'limit', 'type',
        ])));
    }

    public function sellers(Request $request): JsonResponse
    {
        return response()->json($this->service->sellers($request->only([
            'date_from', 'date_to', 'shop_id',
        ])));
    }

    public function topCustomers(Request $request): JsonResponse
    {
        return response()->json($this->service->topCustomers($request->only([
            'date_from', 'date_to', 'limit',
        ])));
    }

    public function investorReport(Request $request, Investor $investor): JsonResponse
    {
        return response()->json($this->service->investorReport($investor->id, $request->only([
            'date_from', 'date_to',
        ])));
    }

    public function expenses(Request $request): JsonResponse
    {
        return response()->json($this->service->expenses($request->only([
            'date_from', 'date_to', 'type',
        ])));
    }
}
