<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Sync Dashboard</title>
    {{-- Tailwind CDN + Pusher-js CDN: proje bilerek build step'siz (bkz. CLAUDE.md).
         Reverb, Pusher protokolünü konuştuğu için pusher-js doğrudan çalışır. --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.pusher.com/8.4/pusher.min.js"></script>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
    <div class="max-w-5xl mx-auto p-4 sm:p-6">
        <header class="flex items-center justify-between mb-6">
            <h1 class="text-xl sm:text-2xl font-bold">Sync Dashboard</h1>
            <span id="connection-badge" class="badge badge-connecting">bağlanıyor…</span>
        </header>

        <section id="status-section" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
            <p class="text-slate-500 text-sm">Yükleniyor…</p>
        </section>

        <section class="mb-8">
            <h2 class="text-lg font-semibold mb-2">Sync Geçmişi</h2>
            <div class="overflow-x-auto bg-white rounded-lg shadow">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="p-3">Provider</th>
                            <th class="p-3">Durum</th>
                            <th class="p-3">Başladı</th>
                            <th class="p-3">Bitti</th>
                            <th class="p-3">Eklenen</th>
                            <th class="p-3">Güncellenen</th>
                            <th class="p-3">Silinen</th>
                            <th class="p-3">Hata</th>
                        </tr>
                    </thead>
                    <tbody id="history-body">
                        <tr><td class="p-3 text-slate-400" colspan="8">Yükleniyor…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold mb-2">Başarısız Job'lar</h2>
            <div class="overflow-x-auto bg-white rounded-lg shadow">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="p-3">Job</th>
                            <th class="p-3">Kuyruk</th>
                            <th class="p-3">Hata</th>
                            <th class="p-3">Zaman</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody id="failed-jobs-body">
                        <tr><td class="p-3 text-slate-400" colspan="5">Yükleniyor…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        // PHP tarafındaki gerçek Reverb app key'i; ayrı JS dosyasına
        // hardcode etmemek için tek satırlık bir köprü.
        window.REVERB_APP_KEY = @json(config('broadcasting.connections.reverb.key'));
        // Scheduler'ın gerçek çalışma aralığı (dk) — dashboard'daki "sonraki
        // otomatik sync" geri sayımı bunu kullanır (bkz. app/Console/Kernel.php).
        window.SYNC_INTERVAL_MINUTES = @json(config('sync.interval_minutes'));
    </script>
    <script src="{{ asset('js/dashboard.js') }}"></script>
</body>
</html>
