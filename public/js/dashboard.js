(function () {
    'use strict';

    var API_BASE = '/api';

    // Tüm ekranın tek kaynağı: ilk HTTP yüklemesi de, canlı socket
    // event'leri de hep BU state'i güncelleyip aynı render fonksiyonlarını
    // çağırır — iki ayrı kod yolu (ilk yükleme / canlı güncelleme) yok.
    var state = {
        status: {},     // providerValue -> {provider, is_running, last_sync}
        history: [],    // en yeni önce, en fazla 10 satır
        failedJobs: [], // en yeni önce, en fazla 10 satır
    };

    var PROVIDER_LABELS = {
        dummyjson: 'DummyJSON',
        fakestore: 'FakeStore API',
    };

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

        return ''
            + '<div class="status-card">'
            + '  <div class="flex items-center justify-between">'
            + '    <h3 class="font-semibold">' + (PROVIDER_LABELS[p.provider] || p.provider) + '</h3>'
            + '    <span class="status-pill ' + statusPillClass(effectiveStatus) + '">' + statusLabel(effectiveStatus) + '</span>'
            + '  </div>'
            + '  <p class="text-slate-400 text-xs mt-1">Son çalışma: ' + lastSeenAt + '</p>'
            +    stats
            +    errorLine
            + '  <button class="btn mt-4 trigger-btn" data-provider="' + p.provider + '"' + (p.is_running ? ' disabled' : '') + '>'
            +      (p.is_running ? '<span class="spinner"></span> Çalışıyor…' : 'Şimdi Senkronize Et')
            + '  </button>'
            + '</div>';
    }

    function renderHistory() {
        var body = document.getElementById('history-body');

        if (state.history.length === 0) {
            body.innerHTML = '<tr><td class="p-3 text-slate-400" colspan="8">Henüz kayıt yok.</td></tr>';
            return;
        }

        body.innerHTML = state.history.slice(0, 10).map(function (row) {
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
    function upsertHistoryRow(payload) {
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
        }

        state.history = state.history.slice(0, 10);
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
            fetchJson(API_BASE + '/sync/history?per_page=10'),
            fetchJson(API_BASE + '/sync/failed-jobs?per_page=10'),
        ]).then(function (results) {
            setProviderStatus(results[0].data);

            state.history = results[1].data;
            renderHistory();

            state.failedJobs = results[2].data;
            renderFailedJobs();
        });
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
    }

    // ---------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------

    loadInitialState().catch(function (e) { console.error('Başlangıç verisi yüklenemedi:', e); });
    connectRealtime();
})();
