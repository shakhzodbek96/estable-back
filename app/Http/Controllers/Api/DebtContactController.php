<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debt\StoreDebtContactRequest;
use App\Http\Requests\Debt\StoreDebtEntryRequest;
use App\Http\Requests\Debt\UpdateDebtContactRequest;
use App\Http\Requests\Debt\UpdateDebtEntryRequest;
use App\Models\DebtContact;
use App\Models\DebtEntry;
use App\Services\DebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebtContactController extends Controller
{
    public function __construct(private DebtService $debts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = DebtContact::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));
        $contacts = $query->orderByDesc('id')->paginate($perPage);

        $balances = $this->debts->balancesFor(collect($contacts->items())->pluck('id')->all());
        $contacts->through(function (DebtContact $c) use ($balances) {
            $c->setAttribute('balances', $balances->get($c->id, []));

            return $c;
        });

        return response()->json($contacts);
    }

    /** Butun daftar bo'yicha umumiy ko'rsatkichlar (valyuta bo'yicha). */
    public function summary(): JsonResponse
    {
        return response()->json($this->debts->summary());
    }

    public function store(StoreDebtContactRequest $request): JsonResponse
    {
        $contact = DebtContact::create([
            ...$request->validated(),
            'created_by' => auth()->id(),
        ]);

        return response()->json($contact, 201);
    }

    public function show(DebtContact $debt): JsonResponse
    {
        $debt->load(['entries' => fn ($q) => $q->orderByDesc('entry_date')->orderByDesc('id')]);
        $debt->setAttribute('balances', $this->debts->balancesFor([$debt->id])->get($debt->id, []));

        return response()->json($debt);
    }

    public function update(UpdateDebtContactRequest $request, DebtContact $debt): JsonResponse
    {
        $debt->update($request->validated());

        return response()->json($debt);
    }

    public function destroy(DebtContact $debt): JsonResponse
    {
        $debt->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // ── Yozuvlar (oldi-berdilar) ──────────────────────────────────────────

    public function addEntry(StoreDebtEntryRequest $request, DebtContact $debt): JsonResponse
    {
        $entry = $this->debts->addEntry($debt, $request->validated());

        return response()->json($entry, 201);
    }

    public function updateEntry(UpdateDebtEntryRequest $request, DebtEntry $debtEntry): JsonResponse
    {
        $entry = $this->debts->updateEntry($debtEntry, $request->validated());

        return response()->json($entry);
    }

    public function deleteEntry(DebtEntry $debtEntry): JsonResponse
    {
        $this->debts->deleteEntry($debtEntry);

        return response()->json(['message' => 'Deleted']);
    }
}
