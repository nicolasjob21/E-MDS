<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ChequeWatch — Cheque Number Monitoring</title>
    {{-- No favicon for this personal project; empty data URI overrides any browser-cached icon. --}}
    <link rel="icon" href="data:,">

    {{-- Apply the saved theme before first paint to avoid a flash. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('cw-theme');
                document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
            } catch (e) {}
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="min-h-screen antialiased">
    <div id="app"></div>
</body>
</html>
