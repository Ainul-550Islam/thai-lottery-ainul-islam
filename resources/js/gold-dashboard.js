/**
 * Thai Lottery gold dashboard (live data: inline #gd-state JSON or data-endpoint).
 * @typedef {{user:{name:string,initials:string,tier:string,notifications:number,avatar?:string},
 *  stats:{balance:number,balanceDelta:number,winnings:number,winningsDelta:number,bets:number,betsDelta:number,points:number,pointsDelta:number},
 *  live:{game:string,name:string,drawNo:string,drawAt:string},
 *  results:{label:string,drawNo:string,date:string,balls:string[],nextDraw:string}[],
 *  upcoming:{name:string,time:string,at:string,tone:'green'|'blue'|'purple'|'gold',logo?:string}[],
 *  activity:{type:'bet'|'deposit'|'win',text:string,ref:string,ago:string}[],
 *  level:{tier:string,pct:number,next:string}}} DashboardState
 * API: set #gd-root[data-endpoint]; it must return DashboardState JSON (times in ISO 8601).
 * Realtime: call window.TLGoldDashboard.render(state) from an Echo listener.
 */
(() => {
    'use strict';
    const root = document.getElementById('gd-root');
    if (!root) return;
    const $ = (s, c = root) => c.querySelector(s), $$ = (s, c = root) => [...c.querySelectorAll(s)];
    const el = (t, c, x) => { const e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; };
    const ico = (id) => { const NS = 'http://www.w3.org/2000/svg', s = document.createElementNS(NS, 'svg'), u = document.createElementNS(NS, 'use'); s.setAttribute('class', 'ic'); u.setAttribute('href', '#i-' + id); s.append(u); return s; };
    const pad = (n) => String(n).padStart(2, '0');
    const TZ = 'Asia/Bangkok';
    const F = (o) => new Intl.DateTimeFormat('en-US', { timeZone: TZ, ...o });
    const fDate = F({ weekday: 'short', month: 'short', day: '2-digit', year: 'numeric' });
    const fTime = F({ hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    const fHM = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false });
    const FMT = {
        money: (n) => '฿ ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
        int: (n) => Number(n).toLocaleString('en-US'),
        pct: (n) => (n >= 0 ? '↑ +' : '↓ −') + Math.abs(n) + '%',
        pcts: (n) => '★ ' + (n >= 0 ? '+' : '−') + Math.abs(n) + '%',
        text: String,
    };

    let results = [];
    const get = (o, p) => p.split('.').reduce((a, k) => (a == null ? a : a[k]), o);

    function showResult(i) {
        const r = results[i]; if (!r) return;
        $$('.gd-tab').forEach((b, j) => { b.classList.toggle('on', j === i); b.setAttribute('aria-selected', String(j === i)); });
        $('#gd-rn').textContent = r.label; $('#gd-rno').textContent = 'Draw No. ' + r.drawNo; $('#gd-rd').textContent = r.date;
        $('#gd-rx').textContent = 'Next Draw: ' + r.nextDraw;
        $('#gd-rb').replaceChildren(...r.balls.map((b) => el('span', 'gd-ball', b)));
    }

    /** @param {DashboardState} s */
    function render(s) {
        const d = new Date(s.live.drawAt), view = { ...s, live: { ...s.live, label: fDate.format(d) + ' | ' + fHM.format(d) } };
        $$('[data-bind]').forEach((n) => {
            const v = get(view, n.dataset.bind); if (v == null) return;
            const f = n.dataset.fmt || 'text'; n.textContent = FMT[f](v);
            if (f === 'pct' || f === 'pcts') n.classList.toggle('dn', v < 0);
        });
        $$('[data-src]').forEach((n) => {
            const v = get(view, n.dataset.src);
            if (v) { n.src = v; return; }
            const f = el('span', 'gd-av', s.user.initials || (s.user.name || '?').slice(0, 1).toUpperCase()); n.replaceWith(f);
        });
        $('#gd-pc').textContent = s.level.pct + '%';
        const b = $('#gd-bdg'); b.textContent = s.user.notifications; b.hidden = !s.user.notifications;
        $('#gd-cd').dataset.at = s.live.drawAt;
        $('#gd-bar').style.width = Math.min(100, Math.max(0, s.level.pct)) + '%';
        results = s.results;
        $('#gd-tabs').replaceChildren(...results.map((r, i) => { const t = el('button', 'gd-tab', r.label); t.type = 'button'; t.dataset.i = i; t.setAttribute('role', 'tab'); return t; }));
        showResult(0);
        $('#gd-up').replaceChildren(...s.upcoming.map((u) => {
            const li = el('li'), info = el('div'), t = el('span', 'gd-tm gd-t-' + u.tone); t.dataset.at = u.at;
            info.append(el('b', null, u.name), el('small', null, u.time));
            li.append(u.logo ? Object.assign(el('img', 'gd-lg'), { src: u.logo, alt: '' }) : el('span', 'gd-dot', u.name.slice(0, 3).toUpperCase()), info, t); return li;
        }));
        const K = { bet: ['', 'check'], deposit: ['b', 'arrow'], win: ['r', 'trophy'] };
        $('#gd-act').replaceChildren(...s.activity.map((a) => {
            const li = el('li'), i = el('i', K[a.type][0]); i.append(ico(K[a.type][1]));
            li.append(i, el('span', null, a.text), el('span', 'gd-g', a.ref), el('em', null, a.ago)); return li;
        }));
        tick();
    }

    const parts = (iso) => { const t = Math.max(0, Math.floor((Date.parse(iso) - Date.now()) / 1000)); return [Math.floor(t / 3600), Math.floor((t % 3600) / 60), t % 60]; };
    function tick() {
        $$('.gd-tm[data-at]').forEach((n) => { n.textContent = parts(n.dataset.at).map(pad).join(':'); });
        const cd = $('#gd-cd'); if (cd && cd.dataset.at) { const p = parts(cd.dataset.at); $$('[data-p]', cd).forEach((n, i) => { n.firstChild.nodeValue = pad(p[i]); }); }
        const now = new Date(); $('#gd-date').textContent = fDate.format(now); $('#gd-time').textContent = fTime.format(now) + ' (ICT)';
    }

    async function load() {
        const inline = document.getElementById('gd-state');
        if (inline && inline.textContent.trim() !== 'null') return JSON.parse(inline.textContent); // server-rendered, real data
        const url = root.dataset.endpoint;
        if (!url) throw new Error('No dashboard data'); // never fall back to fake numbers
        const r = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    }
    async function refresh() {
        try { root.dataset.state = 'ready'; render(await load()); }
        catch (e) { root.dataset.state = 'error'; console.error('[gold-dashboard]', e); } // never show mock money after a failed API call
    }

    $('#gd-tabs').addEventListener('click', (e) => { const t = e.target.closest('.gd-tab'); if (t) showResult(+t.dataset.i); });
    $('#gd-menu').addEventListener('click', () => root.classList.toggle('nav'));
    $('#gd-fs').addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.()));
    setInterval(tick, 1000);
    window.TLGoldDashboard = { render, refresh };
    refresh();
})();
