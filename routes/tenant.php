<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Routes (ishlatilmaydi)
|--------------------------------------------------------------------------
|
| Estable'da tenant endpointlar routes/api.php faylida joylashgan.
| Bu fayl stancl/tenancy TenancyServiceProvider tomonidan avtomatik
| map qilinadi, lekin bizda tenant API routing'i `/api/*` prefiksida
| ishlaganligi uchun bu fayl bo'sh qolsin.
|
| Sabab: API-only backend'da routing /api prefix bilan Laravel'ning
| o'z `api.php` tizimiga bog'langan. Stancl'ning tenant route
| mexanizmi esa web middleware group uchun mo'ljallangan.
|
*/
