/* Administrator-only polling on admin/frontend pages. Tokens stay in memory/POST. */
(() => {
    'use strict';
    const config = window.shipScssWatch;
    if (!config) return;
    let token = '', renewAt = 0, stopped = false, errors = 0;
    const labels = { waiting: '転送安定待ち', unchanged: '監視中', compiled: 'コンパイル完了', busy: '処理待ち', error: 'コンパイル失敗（既存CSSを維持）' };
    let label = document.querySelector('#wp-admin-bar-ship-scss-watch > .ab-item');
    if (!label) {
        label = document.createElement('span');
        label.style.cssText = 'position:fixed;bottom:8px;right:16px;padding:6px 10px;background:#23282d;color:#fff;z-index:99999;font-size:12px';
        document.body.appendChild(label);
    }
    const show = text => { label.textContent = `SCSS監視: ${text}`; };
    async function post(url, data) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
                body: new URLSearchParams(data), signal: controller.signal });
            return { response, data: await response.json() };
        } finally { clearTimeout(timer); }
    }
    async function tick() {
        if (stopped) return;
        try {
            if (!token || Date.now() >= renewAt) {
                const grant = await post(config.ajax, { action: 'ship_scss_watch_lease', nonce: config.nonce, token });
                if (!grant.response.ok || !grant.data.success) throw new Error('lease');
                token = grant.data.data.token;
                renewAt = Date.now() + 120000;
            }
            const probe = await post(config.probe, { token });
            if ([403, 409, 410].includes(probe.response.status)) {
                token = ''; renewAt = 0;
                throw new Error('session');
            }
            if (!probe.response.ok || !labels[probe.data.status]) throw new Error('probe');
            show(labels[probe.data.status]); errors = 0;
        } catch (error) {
            errors++;
            show('通信・認証エラー（再読込で再開）');
        }
        if (!stopped) setTimeout(tick, Math.min(30000, 2000 * Math.pow(2, Math.min(errors, 4))));
    }
    window.addEventListener('pagehide', () => { stopped = true; });
    window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; tick(); } });
    show('起動中'); tick();
})();
