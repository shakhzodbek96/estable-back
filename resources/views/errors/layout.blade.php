{{--
  Estable umumiy xato sahifasi (404, 503, ...).
  Tashqi shrift/asset yuklamaydi (xato paytida ham ishlashi va stack'ni
  oshkor qilmasligi uchun). API so'rovlari bu sahifani KO'RMAYDI — ular
  har doim JSON oladi (bootstrap/app.php → shouldRenderJsonWhen).
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Estable</title>
    <style>
        :root {
            --bg: #f4f6fb; --card: #ffffff; --ink: #0f172a;
            --muted: #6b7280; --line: #eef0f4; --blue: #1a6fcf;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body {
            background: var(--bg); color: var(--ink); line-height: 1.5;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .card {
            width: 100%; max-width: 420px; background: var(--card);
            border: 1px solid var(--line); border-radius: 20px; padding: 40px 32px;
            text-align: center; box-shadow: 0 20px 50px -24px rgba(15, 23, 42, .25);
        }
        .brand {
            display: inline-flex; align-items: center; gap: 8px; margin-bottom: 22px;
            font-size: 17px; font-weight: 800; letter-spacing: -.02em; color: var(--ink);
        }
        .brand .dot {
            width: 28px; height: 28px; border-radius: 9px; background: var(--blue);
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-size: 15px; font-weight: 700;
        }
        .code { margin: 0; font-size: 64px; font-weight: 800; letter-spacing: -.03em; color: var(--blue); line-height: 1; }
        h1 { margin: 14px 0 6px; font-size: 20px; font-weight: 700; }
        p.msg { margin: 0; color: var(--muted); font-size: 14.5px; }
    </style>
</head>
<body>
    <main class="card">
        <span class="brand"><span class="dot">E</span>Estable</span>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p class="msg">@yield('message')</p>
    </main>
</body>
</html>
