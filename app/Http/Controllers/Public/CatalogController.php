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
use Illuminate\Support\Facades\Cache;
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
    /**
     * Katalog ro'yxati. Har "listing" — YA yangi (product bo'yicha jamlangan), YOKI
     * alohida b/u (ishlatilgan) serial birlik. Yangilar bitta kartaga jamlanadi;
     * har bir sotuvdagi b/u birlik esa o'z narxi bilan ALOHIDA karta bo'ladi.
     */
    public function index(Request $request): JsonResponse
    {
        $inStock = InventoryStatus::InStock->value;

        // NEW listing: har product bo'yicha yangi (serial state=new) + bulk jamlanadi.
        // Fantomni yashiramiz: yangi zaxira yo'q, LEKIN b/u birligi bor bo'lsa — bu
        // "Нет" kartani ko'rsatmaymiz (uning o'rniga faqat b/u kartalar chiqadi).
        $newListings = DB::table('products as p')
            ->leftJoinSub($this->availabilitySub('new'), 'ns', 'ns.product_id', '=', 'p.id')
            ->whereRaw(
                "(ns.available > 0 OR NOT EXISTS (SELECT 1 FROM inventories iu WHERE iu.product_id = p.id AND iu.status = ? AND iu.state = 'used'))",
                [$inStock]
            )
            ->selectRaw("p.id AS product_id, NULL::bigint AS inventory_id, 'new' AS state, p.name, p.category_id, p.type, ns.available, ns.price_min, ns.price_max");

        // USED listing: har bir sotuvdagi ishlatilgan serial birlik alohida.
        $usedListings = DB::table('inventories as i')
            ->join('products as up', 'up.id', '=', 'i.product_id')
            ->where('i.status', $inStock)
            ->where('i.state', 'used')
            ->selectRaw("i.product_id AS product_id, i.id AS inventory_id, 'used' AS state, up.name, up.category_id, up.type, 1 AS available, i.selling_price AS price_min, i.selling_price AS price_max");

        $query = DB::query()->fromSub($newListings->unionAll($usedListings), 'l');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('l.name', 'ilike', "%{$search}%");
        }

        if ($categoryId = $request->integer('category_id')) {
            $query->where('l.category_id', $categoryId);
        }

        if ($request->boolean('in_stock')) {
            $query->where('l.available', '>', 0);
        }

        $min = $request->input('min_price');
        if ($min !== null && is_numeric($min)) {
            $query->where('l.price_min', '>=', (float) $min);
        }

        $max = $request->input('max_price');
        if ($max !== null && is_numeric($max)) {
            $query->where('l.price_min', '<=', (float) $max);
        }

        // Saralash. "Популярные" = bor tovarlar oldinda, keyin nom bo'yicha.
        match ($request->string('sort')->value()) {
            'cheap' => $query->orderByRaw('l.price_min ASC NULLS LAST')->orderBy('l.name')->orderBy('l.inventory_id'),
            'expensive' => $query->orderByRaw('l.price_max DESC NULLS LAST')->orderBy('l.name')->orderBy('l.inventory_id'),
            default => $query->orderByRaw('(l.available > 0) DESC')->orderBy('l.name')->orderBy('l.inventory_id'),
        };

        $perPage = max(1, min((int) $request->integer('per_page', 24), 60));
        $paginator = $query->paginate($perPage);

        // Prezentatsiya: kategoriya nomlari + rasmlar (yangi vakil / aynan shu b/u birlik).
        $rows = collect($paginator->items());
        $productIds = $rows->pluck('product_id')->unique()->values()->all();
        $usedInvIds = $rows->where('state', 'used')->pluck('inventory_id')->all();

        $categoryNames = Category::query()
            ->whereIn('id', $rows->pluck('category_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $productImages = Product::query()->with('primaryImage')->whereIn('id', $productIds)->get()->keyBy('id');
        $newImages = $this->stockImageMap($productIds, 'new');
        $usedImages = $this->usedImageMap($usedInvIds);

        $paginator->setCollection($rows->map(function ($r) use ($categoryNames, $productImages, $newImages, $usedImages) {
            $fallback = $productImages->get($r->product_id)?->primaryImage?->url;
            $image = $r->state === 'used'
                ? ($usedImages[$r->inventory_id] ?? $fallback)
                : ($newImages[$r->product_id] ?? $fallback);

            return $this->presentRow($r, $categoryNames[$r->category_id] ?? null, $image);
        }));

        return response()->json($paginator);
    }

    public function show(Product $product, Request $request): JsonResponse
    {
        // B/U (ishlatilgan) birlik detali — ?unit={inventory_id}. Aynan shu birlik.
        if ($unitId = $request->integer('unit')) {
            return $this->showUsedUnit($product, $unitId);
        }

        // Yangi (jamlangan) detali — faqat YANGI zaxira bo'yicha (kartaga mos).
        $stock = DB::query()
            ->fromSub($this->availabilitySub('new'), 's')
            ->where('product_id', $product->id)
            ->first();

        $product->setAttribute('available', $stock->available ?? 0);
        $product->setAttribute('price_min', $stock->price_min ?? null);
        $product->setAttribute('price_max', $stock->price_max ?? null);
        $product->load(['category:id,name', 'images']);

        // Zaxira birligi rasmlari ustun; bo'lmasa — tovar (katalog) rasmlari.
        $stockGallery = $this->stockGallery($product, 'new');
        $images = $stockGallery->isNotEmpty()
            ? $stockGallery
            : $product->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values();

        $main = $images->first()['url'] ?? $product->primaryImage?->url;

        $data = $this->present($product, $main);
        $data['category_description'] = $product->category?->description;
        $data['images'] = $images->values();
        $data['specs'] = $this->stockSpecs($product, 'new');
        $data['stores'] = $this->storeAvailability($product, 'new');

        return response()->json($data);
    }

    /**
     * Bitta b/u (ishlatilgan) serial birlik detali — o'z narxi, holati (korobka,
     * izoh), rasmlari va joylashgan do'koni. Faqat sotuvda turgan birlik.
     */
    private function showUsedUnit(Product $product, int $unitId): JsonResponse
    {
        $unit = Inventory::query()
            ->where('id', $unitId)
            ->where('product_id', $product->id)
            ->where('status', InventoryStatus::InStock->value)
            ->where('state', 'used')
            ->with('images')
            ->first();

        abort_if($unit === null, 404);

        $product->load(['category:id,name', 'images']);

        // Birlikning o'z rasmlari ustun; bo'lmasa — tovar (katalog) rasmlari.
        $images = $unit->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values();
        if ($images->isEmpty()) {
            $images = $product->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values();
        }
        $main = $images->first()['url'] ?? $product->primaryImage?->url;

        $shop = $unit->shop_id ? Shop::query()->find($unit->shop_id, ['id', 'name']) : null;

        return response()->json([
            'id' => $product->id,
            'inventory_id' => $unit->id,
            'state' => 'used',
            'name' => $product->name,
            'type' => $product->type->value,
            'category' => $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'image' => $main,
            'in_stock' => true,
            'available' => 1,
            'price_min' => (float) $unit->selling_price,
            'price_max' => (float) $unit->selling_price,
            'category_description' => $product->category?->description,
            'images' => $images,
            'specs' => $this->presentAttributes($unit->custom_attributes),
            'stores' => $shop ? [['id' => $shop->id, 'name' => $shop->name, 'available' => 1]] : [],
            'has_box' => (bool) $unit->has_box,
            'notes' => $unit->notes,
        ]);
    }

    /**
     * Tovarga qo'shilgan qo'shimcha xarakteristikalar (custom_attributes) — vakil
     * bor zaxira birligidan. Snapshot allaqachon o'zini tavsiflaydi (name/unit/type/icon).
     *
     * @return array<int,array{name:string,value:mixed,unit:?string,icon:?string,icon_color:?string}>
     */
    private function stockSpecs(Product $product, ?string $serialState = null): array
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
                ->when($serialState !== null, fn ($q) => $q->where('state', $serialState))
                ->whereNotNull('custom_attributes')
                ->orderBy('id')
                ->first();

        return $this->presentAttributes($unit?->custom_attributes);
    }

    /**
     * custom_attributes (snapshot) massivini mijozga ko'rsatiladigan xarakteristikalar
     * ro'yxatiga aylantiradi.
     *
     * @return array<int,array{name:string,value:mixed,unit:?string,icon:?string,icon_color:?string}>
     */
    private function presentAttributes(mixed $attrs): array
    {
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
    private function storeAvailability(Product $product, ?string $serialState = null): array
    {
        $shops = Shop::query()->orderBy('name')->get(['id', 'name']);

        $serial = DB::table('inventories')
            ->where('product_id', $product->id)
            ->where('status', InventoryStatus::InStock->value)
            ->when($serialState !== null, fn ($q) => $q->where('state', $serialState))
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

    /** Umumiy sonlar keshi kaliti (vaqt bo'yicha — 4 soat). */
    public const CACHE_TOTALS = 'catalog.stats.totals';

    /**
     * "Sotuvda bor" tovarlar soni keshi kaliti.
     * Zaxira (Inventory/Accessory) o'zgarganда AppServiceProvider keshni tozalaydi.
     */
    public const CACHE_IN_STOCK = 'catalog.stats.in_stock';

    /**
     * Katalog statistikasi ("О нас" bloki uchun): jami tovarlar, sotuvdagi tovarlar,
     * filiallar soni. DB ga ortiqcha yuk bermaslik uchun keshlanadi.
     *
     * - Umumiy sonlar (jami tovar/filial) kam o'zgaradi — VAQT bo'yicha keshlash yetarli (4 soat).
     * - "Sotuvda bor" soni zaxiraga bog'liq — INVALIDATSIYA orqali yangilanadi (zaxira
     *   o'zgarganда kesh tozalanadi), TTL faqat fallback sifatida (12 soat).
     *
     * Kesh stancl tomonidan avto tenant-aware (har tenant uchun alohida kalit).
     */
    public function stats(): JsonResponse
    {
        $totals = Cache::remember(self::CACHE_TOTALS, now()->addHours(4), fn (): array => [
            'products' => (int) DB::table('products')->count(),
            'shops' => (int) DB::table('shops')->count(),
        ]);

        $inStock = Cache::remember(self::CACHE_IN_STOCK, now()->addHours(12), fn (): int => (int) DB::table('products')
            ->leftJoinSub($this->availabilitySub(), 'stock', 'stock.product_id', '=', 'products.id')
            ->where('stock.available', '>', 0)
            ->count());

        return response()->json([
            'products' => $totals['products'],
            'products_in_stock' => (int) $inStock,
            'shops' => $totals['shops'],
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
    private function availabilitySub(?string $serialState = null): QueryBuilder
    {
        $serial = DB::table('inventories')
            ->where('status', InventoryStatus::InStock->value);

        // Serial birliklarni holat bo'yicha cheklash (null — barchasi; 'new' — faqat yangi).
        // Bulk (accessories) da holat yo'q — ular doim yangi hisoblanadi.
        if ($serialState !== null) {
            $serial->where('state', $serialState);
        }

        $serial->selectRaw('product_id, 1 AS available, selling_price AS price');

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
    private function stockImageMap(array $ids, ?string $serialState = null): array
    {
        if (empty($ids)) {
            return [];
        }

        $serialQuery = DB::table('inventories as i')
            ->join('images as img', function ($j) {
                $j->on('img.imageable_id', '=', 'i.id')
                    ->where('img.imageable_type', '=', Inventory::class);
            })
            ->where('i.status', InventoryStatus::InStock->value)
            ->whereIn('i.product_id', $ids);

        if ($serialState !== null) {
            $serialQuery->where('i.state', $serialState);
        }

        $serial = $serialQuery
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
     * B/U (ishlatilgan) birliklar uchun har bir inventory birligining vakil rasmi
     * (inventory_id => url). Har birlik alohida karta bo'lgani uchun o'z rasmi kerak.
     *
     * @param  array<int>  $inventoryIds
     * @return array<int,string|null>
     */
    private function usedImageMap(array $inventoryIds): array
    {
        if (empty($inventoryIds)) {
            return [];
        }

        $rows = DB::table('inventories as i')
            ->join('images as img', function ($j) {
                $j->on('img.imageable_id', '=', 'i.id')
                    ->where('img.imageable_type', '=', Inventory::class);
            })
            ->whereIn('i.id', $inventoryIds)
            ->selectRaw('DISTINCT ON (i.id) i.id, img.path')
            ->orderByRaw('i.id, img.is_primary DESC, img.sort_order ASC, img.id ASC')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->id] = TenantMedia::url($r->path);
        }

        return $map;
    }

    /**
     * Tovar detali uchun vakil zaxira-birlik (rasmga ega bor inventory/accessory) galereyasi.
     * Bo'lmasa — bo'sh collection (chaqiruvchi katalog rasmlariga qaytadi).
     *
     * @return Collection<int,array{id:int,url:string|null}>
     */
    private function stockGallery(Product $product, ?string $serialState = null): Collection
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
                ->when($serialState !== null, fn ($q) => $q->where('state', $serialState))
                ->whereHas('images')
                ->with('images')
                ->orderBy('id')
                ->first();

        return $unit
            ? $unit->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values()
            : collect();
    }

    /** Bitta tovarni (yangi, jamlangan) mijozga ko'rsatiladigan xavfsiz shaklga aylantiradi. */
    private function present(Product $p, ?string $stockImage = null): array
    {
        $available = (int) ($p->available ?? 0);

        return [
            'id' => $p->id,
            'inventory_id' => null,
            'state' => 'new',
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

    /**
     * Katalog ro'yxatidagi bitta "listing" qatorini (yangi jamlangan yoki b/u birlik)
     * mijoz kartasiga aylantiradi. Kategoriya nomi va rasm tashqaridan beriladi.
     */
    private function presentRow(object $r, ?string $categoryName, ?string $image): array
    {
        $available = (int) ($r->available ?? 0);

        return [
            'id' => (int) $r->product_id,
            'inventory_id' => $r->inventory_id !== null ? (int) $r->inventory_id : null,
            'state' => $r->state, // 'new' | 'used'
            'name' => $r->name,
            'type' => $r->type,
            'category' => $r->category_id
                ? ['id' => (int) $r->category_id, 'name' => $categoryName]
                : null,
            'image' => $image,
            'in_stock' => $available > 0,
            'available' => $available,
            'price_min' => $r->price_min !== null ? (float) $r->price_min : null,
            'price_max' => $r->price_max !== null ? (float) $r->price_max : null,
        ];
    }
}
