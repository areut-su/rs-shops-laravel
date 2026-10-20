<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Storefront') }}</title>
    <link rel="stylesheet" href="/assets/storefront-theme.css">
</head>
<body>
    <main>
        <ps-price-widget api-base="/api/storefront/v1" locale="ru" variant="main" theme="light"></ps-price-widget>
    </main>
    <script src="/assets/rs-storefront-widget.js" defer></script>
</body>
</html>
