(function () {
    'use strict';

    var API_BASE = '/api';

    var HISTORY_PER_PAGE = 10;

    // Tüm ekranın tek kaynağı: ilk HTTP yüklemesi de, canlı socket
    // event'leri de hep BU state'i güncelleyip aynı render fonksiyonlarını
    // çağırır — iki ayrı kod yolu (ilk yükleme / canlı güncelleme) yok.
    var state = {
        status: {},     // providerValue -> {provider, is_running, last_sync}
        history: [],    // GÖRÜNTÜLENEN sayfanın satırları (en yeni önce)
        historyPage: 1, // 1 = en yeni; sonraki sayfalar geçmişe (başa) doğru gider
        historyMeta: { page: 1, per_page: HISTORY_PER_PAGE, total: 0 },
        failedJobs: [], // en yeni önce, en fazla 10 satır
    };

    var PROVIDER_LABELS = {
        dummyjson: 'DummyJSON',
        fakestore: 'FakeStore API',
    };

    // Scheduler her provider'ı `*/{dakika} * * * *` cron'uyla tetikliyor
    // (bkz. app/Console/Kernel.php) — yani "son sync'ten X dakika sonra"
    // DEĞİL, saatin dakikası bu sayıya bölünebilir olduğunda (duvar saati
    // hizalı). Geri sayım da aynı mantıkla, sunucudan ek bir istek atmadan
    // tamamen istemci tarafında hesaplanır.
    var SYNC_INTERVAL_MINUTES = window.SYNC_INTERVAL_MINUTES || 5;

    function nextScheduledRunAt() {
        var intervalMs = SYNC_INTERVAL_MINUTES * 60 * 1000;

        return new Date(Math.ceil((Date.now() + 1000) / intervalMs) * intervalMs);
    }

    function formatCountdown(ms) {
        var totalSeconds = Math.max(0, Math.floor(ms / 1000));
        var m = Math.floor(totalSeconds / 60);
        var s = totalSeconds % 60;

        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ---------------------------------------------------------------
    // Yardımcılar
    // ---------------------------------------------------------------

    function formatDate(iso) {
        if (!iso) {
            return '—';
        }

        var d = new Date(iso);

        if (isNaN(d.getTime())) {
            return iso;
        }

        return d.toLocaleString('tr-TR', { dateStyle: 'short', timeStyle: 'medium' });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : str;
        return div.innerHTML;
    }

    function statusPillClass(status) {
        switch (status) {
            case 'running': return 'status-pill-running';
            case 'completed': return 'status-pill-completed';
            case 'failed': return 'status-pill-failed';
            default: return 'status-pill-none';
        }
    }

    function statusLabel(status) {
        switch (status) {
            case 'running': return 'Çalışıyor';
            case 'completed': return 'Tamamlandı';
            case 'failed': return 'Başarısız';
            default: return 'Hiç çalışmadı';
        }
    }

    // ---------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------

    function renderStatus() {
        var container = document.getElementById('status-section');
        var providers = Object.keys(state.status).map(function (key) { return state.status[key]; });

        if (providers.length === 0) {
            container.innerHTML = '<p class="text-slate-500 text-sm">Henüz veri yok.</p>';
            return;
        }

        container.innerHTML = providers.map(renderStatusCard).join('');
    }

    function renderStatusCard(p) {
        var lastSync = p.last_sync;
        var effectiveStatus = p.is_running ? 'running' : (lastSync ? lastSync.status : null);

        var stats = lastSync
            ? '<div class="grid grid-cols-3 gap-2 text-center mt-3 text-sm">'
                + '<div><div class="font-semibold text-emerald-600">' + (lastSync.products_added || 0) + '</div><div class="text-slate-400 text-xs">eklenen</div></div>'
                + '<div><div class="font-semibold text-blue-600">' + (lastSync.products_updated || 0) + '</div><div class="text-slate-400 text-xs">güncellenen</div></div>'
                + '<div><div class="font-semibold text-rose-600">' + (lastSync.products_deleted || 0) + '</div><div class="text-slate-400 text-xs">silinen</div></div>'
                + '</div>'
            : '<p class="text-slate-400 text-sm mt-3">Henüz sync çalışmadı.</p>';

        var errorLine = (lastSync && lastSync.error_message)
            ? '<p class="text-rose-600 text-xs mt-2 break-words">' + escapeHtml(lastSync.error_message) + '</p>'
            : '';

        var lastSeenAt = lastSync ? formatDate(lastSync.completed_at || lastSync.started_at) : '—';

        // Otomatik (cron) sync'in ne zaman tekrar tetikleneceğini gösteren
        // canlı geri sayım — tickCountdowns() her saniye sadece bu span'in
        // içeriğini günceller, karta ait diğer her şey aynı kalır.
        var countdownLine = p.is_running
            ? ''
            : '<p class="text-slate-400 text-xs mt-1">Sonraki otomatik sync: <span class="countdown-value font-mono" data-countdown-for="' + p.provider + '">--:--</span></p>';

        return ''
            + '<div class="status-card" data-status-card-for="' + p.provider + '">'
            + '  <div class="flex items-center justify-between">'
            + '    <h3 class="font-semibold">' + (PROVIDER_LABELS[p.provider] || p.provider) + '</h3>'
            + '    <span class="status-pill ' + statusPillClass(effectiveStatus) + '" data-status-pill-for="' + p.provider + '">' + statusLabel(effectiveStatus) + '</span>'
            + '  </div>'
            + '  <p class="text-slate-400 text-xs mt-1" data-last-sync-for="' + p.provider + '">Son çalışma: ' + lastSeenAt + '</p>'
            +    countdownLine
            +    stats
            +    errorLine
            // `disabled`, provider `is_running` olduğunda set edilir — bu bayrak
            // hem manuel tetiklemeden hem de scheduler'ın otomatik tetiklemesinden
            // sonra AYNI şekilde true olur (bkz. SyncController::status() —
            // "running" durumu tetikleyici kaynağı ayırt etmez). Yani otomatik
            // bir sync çalışırken buton, manuel tetiklemedeki gibi devre dışı kalır.
            + '  <button class="btn mt-4 trigger-btn" data-provider="' + p.provider + '"' + (p.is_running ? ' disabled title="Otomatik sync çalışıyor"' : '') + '>'
            +      (p.is_running ? '<span class="spinner"></span> Çalışıyor…' : 'Şimdi Senkronize Et')
            + '  </button>'
            + '</div>';
    }

    function renderHistory() {
        var body = document.getElementById('history-body');

        if (state.history.length === 0) {
            body.innerHTML = '<tr><td class="p-3 text-slate-400" colspan="8">Henüz kayıt yok.</td></tr>';
            renderHistoryPagination();
            return;
        }

        body.innerHTML = state.history.map(function (row) {
            return ''
                + '<tr class="border-b last:border-0 fade-in-row">'
                + '  <td class="p-3">' + (PROVIDER_LABELS[row.provider] || row.provider) + '</td>'
                + '  <td class="p-3"><span class="status-pill ' + statusPillClass(row.status) + '">' + statusLabel(row.status) + '</span></td>'
                + '  <td class="p-3">' + formatDate(row.started_at) + '</td>'
                + '  <td class="p-3">' + formatDate(row.completed_at) + '</td>'
                + '  <td class="p-3">' + (row.products_added || 0) + '</td>'
                + '  <td class="p-3">' + (row.products_updated || 0) + '</td>'
                + '  <td class="p-3">' + (row.products_deleted || 0) + '</td>'
                + '  <td class="p-3 text-rose-600 text-xs max-w-[200px] truncate" title="' + escapeHtml(row.error_message || '') + '">' + (row.error_message ? escapeHtml(row.error_message) : '—') + '</td>'
                + '</tr>';
        }).join('');

        renderHistoryPagination();
    }

    // "Sonraki" (›) geçmişe/başa doğru ilerler (daha eski kayıtlar), "Önceki"
    // (‹) en yeniye doğru geri döner — sayfa 1 her zaman en yeni 10 kayıt.
    function renderHistoryPagination() {
        var container = document.getElementById('history-pagination');
        var meta = state.historyMeta;
        var totalPages = Math.max(1, Math.ceil(meta.total / meta.per_page));

        container.innerHTML = ''
            + '<span>' + meta.total + ' kayıt</span>'
            + '<div class="flex items-center gap-2">'
            + '  <button class="pagination-btn" data-history-page="prev"' + (meta.page <= 1 ? ' disabled' : '') + '>‹ Önceki</button>'
            + '  <span>Sayfa ' + meta.page + ' / ' + totalPages + '</span>'
            + '  <button class="pagination-btn" data-history-page="next"' + (meta.page >= totalPages ? ' disabled' : '') + '>Sonraki ›</button>'
            + '</div>';
    }

    function renderFailedJobs() {
        var body = document.getElementById('failed-jobs-body');

        if (state.failedJobs.length === 0) {
            body.innerHTML = '<tr><td class="p-3 text-slate-400" colspan="5">Başarısız job yok.</td></tr>';
            return;
        }

        body.innerHTML = state.failedJobs.slice(0, 10).map(function (job) {
            var firstLine = (job.exception || '').split('\n')[0];

            return ''
                + '<tr class="border-b last:border-0 fade-in-row">'
                + '  <td class="p-3 font-mono text-xs">' + escapeHtml(job.job_class || '—') + '</td>'
                + '  <td class="p-3">' + escapeHtml(job.queue || '—') + '</td>'
                + '  <td class="p-3 text-rose-600 text-xs max-w-[240px] truncate" title="' + escapeHtml(job.exception || '') + '">' + escapeHtml(firstLine) + '</td>'
                + '  <td class="p-3 text-xs">' + formatDate(job.failed_at) + '</td>'
                + '  <td class="p-3"><button class="btn btn-sm retry-btn" data-uuid="' + job.uuid + '">Tekrar Dene</button></td>'
                + '</tr>';
        }).join('');
    }

    // ---------------------------------------------------------------
    // State güncelleme (ilk yükleme VE canlı event'ler aynı yolu kullanır)
    // ---------------------------------------------------------------

    function setProviderStatus(providerRows) {
        providerRows.forEach(function (p) { state.status[p.provider] = p; });
        renderStatus();
    }

    // Aynı provider+started_at'e sahip bir satır zaten varsa (ör. "running"
    // olarak eklenmişti) günceller, yoksa başa ekler — "running" -> "completed"
    // geçişinde geçmiş tablosunda YENİ bir satır değil, AYNI satır güncellenir.
    // Sadece 1. sayfadayken (en yeni kayıtlar) uygulanır: kullanıcı geçmişte
    // daha eski bir sayfayı incelerken, arka planda tetiklenen yeni bir sync
    // görünümünü aniden değiştirmemeli — 1. sayfaya dönünce zaten güncel olur.
    function upsertHistoryRow(payload) {
        if (state.historyPage !== 1) {
            return;
        }

        var idx = -1;

        for (var i = 0; i < state.history.length; i++) {
            if (state.history[i].provider === payload.provider && state.history[i].started_at === payload.started_at) {
                idx = i;
                break;
            }
        }

        if (idx >= 0) {
            state.history[idx] = Object.assign({}, state.history[idx], payload);
        } else {
            state.history.unshift(payload);
            state.history = state.history.slice(0, HISTORY_PER_PAGE);
            state.historyMeta.total += 1;
        }

        renderHistory();
    }

    function applyLiveSyncUpdate(payload) {
        var current = state.status[payload.provider] || { provider: payload.provider, is_running: false, last_sync: null };

        if (payload.status === 'running') {
            current.is_running = true;
        } else {
            current.is_running = false;
            current.last_sync = payload;
        }

        state.status[payload.provider] = current;
        renderStatus();
        upsertHistoryRow(payload);
    }

    function prependFailedJob(job) {
        state.failedJobs.unshift(job);
        state.failedJobs = state.failedJobs.slice(0, 10);
        renderFailedJobs();
    }

    function removeFailedJob(uuid) {
        state.failedJobs = state.failedJobs.filter(function (j) { return j.uuid !== uuid; });
        renderFailedJobs();
    }

    // ---------------------------------------------------------------
    // API çağrıları
    // ---------------------------------------------------------------

    function fetchJson(url, options) {
        return fetch(url, Object.assign({
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        }, options || {})).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || body.success === false) {
                    throw new Error((body.error && body.error.message) || 'İstek başarısız oldu');
                }

                return body;
            });
        });
    }

    function loadInitialState() {
        return Promise.all([
            fetchJson(API_BASE + '/sync/status'),
            fetchJson(API_BASE + '/sync/failed-jobs?per_page=10'),
        ]).then(function (results) {
            setProviderStatus(results[0].data);

            state.failedJobs = results[1].data;
            renderFailedJobs();
        });
    }

    function loadHistoryPage(page) {
        return fetchJson(API_BASE + '/sync/history?per_page=' + HISTORY_PER_PAGE + '&page=' + page)
            .then(function (body) {
                state.history = body.data;
                state.historyPage = body.meta.page;
                state.historyMeta = body.meta;
                renderHistory();
            })
            .catch(function (e) { console.error('Sync geçmişi yüklenemedi:', e); });
    }

    function clearHistory() {
        if (!confirm('Sync geçmişindeki tüm loglar silinsin mi? Bu işlem geri alınamaz.')) {
            return;
        }

        fetchJson(API_BASE + '/sync/history', { method: 'DELETE' })
            .catch(function (e) { alert('Geçmiş temizlenemedi: ' + e.message); });
    }

    function triggerSync(provider) {
        fetchJson(API_BASE + '/sync/trigger', {
            method: 'POST',
            body: JSON.stringify({ provider: provider }),
        }).catch(function (e) {
            alert('Sync tetiklenemedi: ' + e.message);
        });
    }

    function retryJob(uuid) {
        fetchJson(API_BASE + '/sync/retry/' + uuid, { method: 'POST' })
            .then(function () { removeFailedJob(uuid); })
            .catch(function (e) { alert('Retry başarısız: ' + e.message); });
    }

    // ---------------------------------------------------------------
    // Event delegation (kartlar/satırlar dinamik oluşturulduğu için)
    // ---------------------------------------------------------------

    document.addEventListener('click', function (event) {
        var triggerBtn = event.target.closest('.trigger-btn');

        if (triggerBtn) {
            triggerBtn.disabled = true;
            triggerSync(triggerBtn.dataset.provider);
            return;
        }

        var retryBtn = event.target.closest('.retry-btn');

        if (retryBtn) {
            retryBtn.disabled = true;
            retryJob(retryBtn.dataset.uuid);
            return;
        }

        if (event.target.id === 'clear-history-btn') {
            clearHistory();
            return;
        }

        var pageBtn = event.target.closest('[data-history-page]');

        if (pageBtn && !pageBtn.disabled) {
            var nextPage = pageBtn.dataset.historyPage === 'next' ? state.historyPage + 1 : state.historyPage - 1;
            loadHistoryPage(nextPage);
        }
    });

    // ---------------------------------------------------------------
    // WebSocket (Reverb, pusher-js ile — Reverb Pusher protokolünü konuşur)
    // ---------------------------------------------------------------

    function setConnectionBadge(kind) {
        var badge = document.getElementById('connection-badge');
        var map = {
            connecting: ['badge-connecting', 'bağlanıyor…'],
            connected: ['badge-connected', 'canlı'],
            disconnected: ['badge-disconnected', 'bağlantı yok'],
        };
        var pair = map[kind] || map.connecting;
        badge.className = 'badge ' + pair[0];
        badge.textContent = pair[1];
    }

    function connectRealtime() {
        var pusher = new Pusher(window.REVERB_APP_KEY, {
            wsHost: window.location.hostname,
            wsPort: window.location.port ? parseInt(window.location.port, 10) : (window.location.protocol === 'https:' ? 443 : 80),
            forceTLS: window.location.protocol === 'https:',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            cluster: 'mt1',
        });

        pusher.connection.bind('connecting', function () { setConnectionBadge('connecting'); });
        pusher.connection.bind('connected', function () { setConnectionBadge('connected'); });
        pusher.connection.bind('unavailable', function () { setConnectionBadge('disconnected'); });
        pusher.connection.bind('failed', function () { setConnectionBadge('disconnected'); });
        pusher.connection.bind('disconnected', function () { setConnectionBadge('disconnected'); });

        var channel = pusher.subscribe('sync-status');

        channel.bind('sync-status.updated', applyLiveSyncUpdate);
        channel.bind('failed-job.recorded', prependFailedJob);
        // Logları Sil, isteği atan sekmeyle sınırlı kalmasın diye kanaldan
        // yayınlanıyor — dashboard'u açık tutan HERKES 1. sayfaya (boş
        // tabloya) döner, sadece butona basan kişi değil.
        channel.bind('sync-history.cleared', function () {
            state.historyPage = 1;
            loadHistoryPage(1);
        });
    }

    // ---------------------------------------------------------------
    // Geri sayım (her saniye sadece span içeriğini günceller — tam
    // renderStatus() tekrar çalıştırmaz, gereksiz DOM rebuild olmaz)
    // ---------------------------------------------------------------

    function tickCountdowns() {
        var remaining = nextScheduledRunAt().getTime() - Date.now();
        var text = formatCountdown(remaining);

        document.querySelectorAll('[data-countdown-for]').forEach(function (el) {
            el.textContent = text;
        });
    }

    // ---------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------

    loadInitialState().catch(function (e) { console.error('Başlangıç verisi yüklenemedi:', e); });
    loadHistoryPage(1);
    connectRealtime();

    tickCountdowns();
    setInterval(tickCountdowns, 1000);
})();
