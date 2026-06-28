<?php

namespace App\Http\Controllers\Public;

use App\Enums\InventoryStatus;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Support\TenantMedia;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public (mijozlar uchun) "Прайс-лист" katalog.
 *
 * Auth talab QILMAYDI — sahifa har kimga ochiq. Tenant `Origin` header orqali
 * aniqlanadi (routes/api.php → InitializeTenancyByOriginHeader guruhi ichida),
 * shu sababli har tenant faqat o'z tovarlarini ko'rsatadi.
 *
 * MUHIM: faqat mijozga zarur, xavfsiz maydonlar qaytariladi —
 * sotuv narxi (selling_price/sell_price), kategoriya, rasm, "bor/yo'q".
 * HECH QACHON: purchase_price, wholesale_price, investor, serial raqamlar,
 * shop_id va boshqa ichki ma'lumotlar oshkor qilinmaydi.
 *
 * RASM: tovar kartochkasida — agar zaxira birligida (inventory/accessory) o'z
 * rasmi bo'lsa shu ko'rsatiladi, aks holda tovar (katalog) rasmiga qaytadi.
 */
class CatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->leftJoinSub($this->availabilitySub(), 'stock', 'stock.product_id', '=', 'products.id')
            ->with(['category:id,name', 'primaryImage'])
            ->select('products.*', 'stock.available', 'stock.price_min', 'stock.price_max');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('products.name', 'ilike', "%{$search}%");
        }

        if ($categoryId = $request->integer('category_id')) {
            $query->where('products.category_id', $categoryId);
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock.available', '>', 0);
        }

        $min = $request->input('min_price');
        if ($min !== null && is_numeric($min)) {
            $query->where('stock.price_min', '>=', (float) $min);
        }

        $max = $request->input('max_price');
        if ($max !== null && is_numeric($max)) {
            $query->where('stock.price_min', '<=', (float) $max);
        }

        // Saralash. "Популярные" = bor tovarlar oldinda, keyin nom bo'yicha.
        match ($request->string('sort')->value()) {
            'cheap' => $query->orderByRaw('stock.price_min ASC NULLS LAST')->orderBy('products.name'),
            'expensive' => $query->orderByRaw('stock.price_max DESC NULLS LAST')->orderBy('products.name'),
            default => $query->orderByRaw('(stock.available > 0) DESC')->orderBy('products.name'),
        };

        $perPage = max(1, min((int) $request->integer('per_page', 24), 60));
        $paginator = $query->paginate($perPage);

        $ids = collect($paginator->items())->pluck('id')->all();
        $stockImages = $this->stockImageMap($ids);

        $paginator->getCollection()->transform(
            fn (Product $p) => $this->present($p, $stockImages[$p->id] ?? null)
        );

        return response()->json($paginator);
    }

    public function show(Product $product): JsonResponse
    {
        $stock = DB::query()
            ->fromSub($this->availabilitySub(), 's')
            ->where('product_id', $product->id)
            ->first();

        $product->setAttribute('available', $stock->available ?? 0);
        $product->setAttribute('price_min', $stock->price_min ?? null);
        $product->setAttribute('price_max', $stock->price_max ?? null);
        $product->load(['category:id,name', 'images']);

        // Zaxira birligi rasmlari ustun; bo'lmasa — tovar (katalog) rasmlari.
        $stockGallery = $this->stockGallery($product);
        $images = $stockGallery->isNotEmpty()
            ? $stockGallery
            : $product->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values();

        $main = $images->first()['url'] ?? $product->primaryImage?->url;

        $data = $this->present($product, $main);
        $data['category_description'] = $product->category?->description;
        $data['images'] = $images->values();
        $data['specs'] = $this->stockSpecs($product);
        $data['stores'] = $this->storeAvailability($product);

        return response()->json($data);
    }

    /**
     * Tovarga qo'shilgan qo'shimcha xarakteristikalar (custom_attributes) — vakil
     * bor zaxira birligidan. Snapshot allaqachon o'zini tavsiflaydi (name/unit/type/icon).
     *
     * @return array<int,array{name:string,value:mixed,unit:?string,icon:?string,icon_color:?string}>
     */
    private function stockSpecs(Product $product): array
    {
        $unit = $product->type === ProductType::Bulk
            ? Accessory::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->whereRaw('(quantity - sold_quantity - consigned_quantity) > 0')
                ->whereNotNull('custom_attributes')
                ->orderBy('id')
                ->first()
            : Inventory::query()
                ->where('product_id', $product->id)
                ->where('status', InventoryStatus::InStock->value)
                ->whereNotNull('custom_attributes')
                ->orderBy('id')
                ->first();

        $attrs = $unit?->custom_attributes;
        if (! is_array($attrs)) {
            return [];
        }

        return collect($attrs)
            ->filter(fn ($a) => isset($a['value']) && $a['value'] !== '' && $a['value'] !== null)
            ->map(fn ($a) => [
                'name' => $a['name'] ?? '',
                'value' => ($a['type'] ?? null) === 'boolean' ? (($a['value']) ? 'Да' : 'Нет') : $a['value'],
                'unit' => $a['unit'] ?? null,
                'icon' => $a['icon'] ?? null,
                'icon_color' => $a['icon_color'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Tovar bo'yicha har do'kondagi mavjud qoldiq ("Наличие в магазинах").
     * Barcha do'konlar qaytadi (qoldig'i 0 bo'lganlar ham — "Под заказ" uchun).
     *
     * @return array<int,array{id:int,name:string,available:int}>
     */
    private function storeAvailability(Product $product): array
    {
        $shops = Shop::query()->orderBy('name')->get(['id', 'name']);

        $serial = DB::table('inventories')
            ->where('product_id', $product->id)
            ->where('status', InventoryStatus::InStock->value)
            ->groupBy('shop_id')
            ->selectRaw('shop_id, COUNT(*) AS c')
            ->pluck('c', 'shop_id');

        $bulk = DB::table('accessories')
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->whereRaw('(quantity - sold_quantity - consigned_quantity) > 0')
            ->groupBy('shop_id')
            ->selectRaw('shop_id, SUM(quantity - sold_quantity - consigned_quantity) AS c')
            ->pluck('c', 'shop_id');

        return $shops->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'available' => (int) (($serial[$s->id] ?? 0) + ($bulk[$s->id] ?? 0)),
        ])->values()->all();
    }

    /** Faol kategoriyalar + ikon + har biridagi "bor" tovarlar soni (filtr chiplari uchun). */
    public function categories(): JsonResponse
    {
        $counts = DB::table('products')
            ->leftJoinSub($this->availabilitySub(), 'stock', 'stock.product_id', '=', 'products.id')
            ->whereNotNull('products.category_id')
            ->selectRaw('products.category_id, COUNT(*) FILTER (WHERE stock.available > 0) AS cnt')
            ->groupBy('products.category_id')
            ->pluck('cnt', 'products.category_id');

        $total = DB::table('products')
            ->leftJoinSub($this->availabilitySub(), 'stock', 'stock.product_id', '=', 'products.id')
            ->where('stock.available', '>', 0)
            ->count();

        $cats = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'icon'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'icon' => $c->icon,
                'count' => (int) ($counts[$c->id] ?? 0),
            ]);

        return response()->json([
            'categories' => $cats,
            'total' => $total,
        ]);
    }

    /** Do'kon brendi + about + aloqa (public landing sarlavha/footer uchun). */
    public function store(): JsonResponse
    {
        $info = Setting::getValue(Setting::STORE_INFO, []);
        $info = is_array($info) ? $info : [];
        $field = static fn (string $k): string => isset($info[$k]) && is_string($info[$k]) ? trim($info[$k]) : '';

        $name = $field('name');
        if ($name === '') {
            $receipt = Setting::getValue(Setting::RECEIPT_CONFIG, []);
            $name = collect(is_array($receipt) ? ($receipt['header_lines'] ?? []) : [])
                ->map(fn ($line) => trim((string) $line))
                ->filter()
                ->first() ?: Str::headline((string) tenant('id'));
        }

        return response()->json([
            'name' => $name,
            'tenant' => tenant('id'),
            'about' => $field('about'),
            'phone' => $field('phone'),
            'telegram' => $field('telegram'),
            'instagram' => $field('instagram'),
        ]);
    }

    /** Do'kon filiallari (manzil, lokatsiya, ish vaqti) — public. */
    public function shops(): JsonResponse
    {
        $shops = Shop::query()->orderBy('name')->get();

        return response()->json(
            $shops->map(fn (Shop $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'image' => $s->image_url,
                'address' => $s->address,
                'yandex_maps_url' => $s->yandex_maps_url,
                'google_maps_url' => $s->google_maps_url,
                'working_hours' => $s->working_hours,
            ])->values()
        );
    }

    /**
     * Har product uchun "bor" zaxira agregati (joinSub uchun).
     * Serial (inventories, status=in_stock) + bulk (accessories, mavjud miqdor) birlashtiriladi,
     * product_id bo'yicha guruhlanadi: available (jami dona), price_min, price_max.
     */
    private function availabilitySub(): QueryBuilder
    {
        $serial = DB::table('inventories')
            ->where('status', InventoryStatus::InStock->value)
            ->selectRaw('product_id, 1 AS available, selling_price AS price');

        $bulk = DB::table('accessories')
            ->where('is_active', true)
            ->whereRaw('(quantity - sold_quantity - consigned_quantity) > 0')
            ->selectRaw('product_id, (quantity - sold_quantity - consigned_quantity) AS available, sell_price AS price');

        $units = $serial->unionAll($bulk);

        return DB::query()
            ->fromSub($units, 'units')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(available) AS available, MIN(price) AS price_min, MAX(price) AS price_max');
    }

    /**
     * Sahifadagi tovarlar uchun "vakil" zaxira-birlik rasmi (product_id => url).
     * Serial — bor inventory'ning (primary oldinda) rasmi; bulk — faol accessory rasmi.
     * Bittadan rasm (DISTINCT ON), N+1 yo'q. Tovarning o'z rasmi YO'Q — bu yerda faqat
     * zaxira-birlik rasmlari; bo'lmasa present() katalog rasmiga qaytadi.
     *
     * @param  array<int>  $ids
     * @return array<int,string|null>
     */
    private function stockImageMap(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $serial = DB::table('inventories as i')
            ->join('images as img', function ($j) {
                $j->on('img.imageable_id', '=', 'i.id')
                    ->where('img.imageable_type', '=', Inventory::class);
            })
            ->where('i.status', InventoryStatus::InStock->value)
            ->whereIn('i.product_id', $ids)
            ->selectRaw('DISTINCT ON (i.product_id) i.product_id, img.path')
            ->orderByRaw('i.product_id, img.is_primary DESC, img.sort_order ASC, img.id ASC')
            ->get();

        $bulk = DB::table('accessories as a')
            ->join('images as img', function ($j) {
                $j->on('img.imageable_id', '=', 'a.id')
                    ->where('img.imageable_type', '=', Accessory::class);
            })
            ->where('a.is_active', true)
            ->whereRaw('(a.quantity - a.sold_quantity - a.consigned_quantity) > 0')
            ->whereIn('a.product_id', $ids)
            ->selectRaw('DISTINCT ON (a.product_id) a.product_id, img.path')
            ->orderByRaw('a.product_id, img.is_primary DESC, img.sort_order ASC, img.id ASC')
            ->get();

        $map = [];
        foreach ($serial as $r) {
            $map[$r->product_id] = TenantMedia::url($r->path);
        }
        // Bulk product serial bo'lmaydi — to'qnashuv yo'q.
        foreach ($bulk as $r) {
            $map[$r->product_id] = TenantMedia::url($r->path);
        }

        return $map;
    }

    /**
     * Tovar detali uchun vakil zaxira-birlik (rasmga ega bor inventory/accessory) galereyasi.
     * Bo'lmasa — bo'sh collection (chaqiruvchi katalog rasmlariga qaytadi).
     *
     * @return Collection<int,array{id:int,url:string|null}>
     */
    private function stockGallery(Product $product): Collection
    {
        $unit = $product->type === ProductType::Bulk
            ? Accessory::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->whereRaw('(quantity - sold_quantity - consigned_quantity) > 0')
                ->whereHas('images')
                ->with('images')
                ->orderBy('id')
                ->first()
            : Inventory::query()
                ->where('product_id', $product->id)
                ->where('status', InventoryStatus::InStock->value)
                ->whereHas('images')
                ->with('images')
                ->orderBy('id')
                ->first();

        return $unit
            ? $unit->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values()
            : collect();
    }

    /** Bitta tovarni mijozga ko'rsatiladigan xavfsiz shaklga aylantiradi. */
    private function present(Product $p, ?string $stockImage = null): array
    {
        $available = (int) ($p->available ?? 0);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type->value,
            'category' => $p->category
                ? ['id' => $p->category->id, 'name' => $p->category->name]
                : null,
            // Zaxira-birlik rasmi ustun; bo'lmasa — tovarning katalog rasmi.
            'image' => $stockImage ?? $p->primaryImage?->url,
            'in_stock' => $available > 0,
            'available' => $available,
            'price_min' => $p->price_min !== null ? (float) $p->price_min : null,
            'price_max' => $p->price_max !== null ? (float) $p->price_max : null,
        ];
    }
}
