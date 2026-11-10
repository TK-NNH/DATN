{{-- Khung chat gợi ý phim. Nhúng vào master bằng: @include('frontend.layouts.chatbot') --}}
<div id="fh-chat">
    <button id="fh-chat-toggle" type="button" aria-label="Mở chat gợi ý phim" title="Gợi ý phim">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
    </button>

    <div id="fh-chat-panel" hidden>
        <div class="fh-head">
            <div>
                <strong>Trợ lý gợi ý phim</strong>
                <small>Hỏi mình về phim nhé 🎬</small>
            </div>
            <div class="fh-actions">
                <button id="fh-chat-expand" type="button" aria-label="Phóng to" title="Phóng to">
                    <svg class="ic-max" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"></path></svg>
                    <svg class="ic-min" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"></path></svg>
                </button>
            </div>
        </div>

        <div id="fh-chat-messages" aria-live="polite"></div>
        <div id="fh-chat-chips"></div>

        <form id="fh-chat-form" autocomplete="off">
            <input id="fh-chat-input" type="text" maxlength="300" placeholder="Nhập câu hỏi về phim..." />
            <button type="submit" id="fh-chat-send">Gửi</button>
        </form>
    </div>
</div>

<style>
    #fh-chat { --fh-accent: #e4003a; --fh-bg: #15171c; --fh-bg2: #20232b; --fh-text: #f1f1f1; font-family: Arial, Helvetica, sans-serif; }
    #fh-chat * { box-sizing: border-box; }
    #fh-chat-toggle { position: fixed; right: 20px; bottom: 20px; z-index: 99998; width: 56px; height: 56px; border: 0; border-radius: 50%;
        background: var(--fh-accent); color: #fff; cursor: pointer; box-shadow: 0 6px 18px rgba(0,0,0,.35); display: flex; align-items: center; justify-content: center; }
    #fh-chat-panel { position: fixed; right: 20px; bottom: 88px; z-index: 99999; width: min(430px, calc(100vw - 24px)); height: min(620px, 82vh);
        background: var(--fh-bg); color: var(--fh-text); border-radius: 14px; box-shadow: 0 12px 36px rgba(0,0,0,.45); display: flex; flex-direction: column; overflow: hidden; }
    #fh-chat-panel[hidden] { display: none; }
    #fh-chat .fh-head { background: var(--fh-accent); color: #fff; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; }
    #fh-chat .fh-head strong { display: block; font-size: 15px; }
    #fh-chat .fh-head small { opacity: .9; font-size: 12px; }
    #fh-chat { --fh-top-offset: 125px; }   /* chiều cao vùng header của website được chừa lại khi phóng to (logo, menu) */
    #fh-chat .fh-actions { display: flex; align-items: center; gap: 8px; }
    #fh-chat-expand { background: none; border: 0; color: #fff; cursor: pointer; display: flex; align-items: center; padding: 4px; }
    #fh-chat-expand .ic-min { display: none; }
    #fh-chat-panel.fh-full #fh-chat-expand .ic-max { display: none; }
    #fh-chat-panel.fh-full #fh-chat-expand .ic-min { display: inline; }
    #fh-chat-close { background: none; border: 0; color: #fff; font-size: 26px; line-height: 1; cursor: pointer; }
    #fh-chat-messages { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
    #fh-chat-messages > * { flex-shrink: 0; }
    #fh-chat .fh-msg { max-width: 88%; padding: 9px 12px; border-radius: 12px; font-size: 14px; line-height: 1.45; white-space: pre-line; word-wrap: break-word; }
    #fh-chat .fh-bot { background: var(--fh-bg2); align-self: flex-start; border-bottom-left-radius: 3px; }
    #fh-chat .fh-user { background: var(--fh-accent); color: #fff; align-self: flex-end; border-bottom-right-radius: 3px; }
    #fh-chat .fh-cards { display: flex; gap: 12px; overflow-x: auto; overflow-y: hidden; padding: 2px 2px 6px; align-self: stretch; scroll-snap-type: x proximity; scrollbar-width: none; -ms-overflow-style: none; }
    #fh-chat .fh-cards::-webkit-scrollbar { display: none; }
    #fh-chat .fh-card { flex: 0 0 156px; scroll-snap-align: start; text-decoration: none; color: var(--fh-text); }
    #fh-chat .fh-poster { position: relative; display: block; width: 156px; height: 224px; border-radius: 10px; overflow: hidden; background: #343844; }
    #fh-chat .fh-poster img { width: 100%; height: 100%; object-fit: cover; display: block; }
    #fh-chat .fh-badge { position: absolute; top: 6px; left: 6px; background: var(--fh-accent); color: #fff; font-size: 11px; font-weight: bold; padding: 2px 6px; border-radius: 4px; }
    #fh-chat .fh-rate { position: absolute; bottom: 6px; right: 6px; background: rgba(0,0,0,.72); color: #ffd24d; font-size: 11px; padding: 2px 6px; border-radius: 4px; }
    #fh-chat .fh-section { font-size: 13px; font-weight: bold; color: #ffd24d; margin: 4px 2px -4px; }
    #fh-chat .fh-noposter { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 10px; text-align: center; font-size: 13px; font-weight: bold; line-height: 1.3; color: #fff; background: linear-gradient(160deg, #4a4f62, #2a2d38); }
    #fh-chat .fh-title { display: block; margin-top: 8px; font-size: 14px; font-weight: bold; line-height: 1.25; }
    #fh-chat .fh-sub { display: block; margin-top: 3px; font-size: 12px; opacity: .7; }
    #fh-chat .fh-link { align-self: flex-start; background: var(--fh-accent); color: #fff; text-decoration: none; padding: 7px 14px; border-radius: 16px; font-size: 13px; }
    #fh-chat-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 12px 8px; max-height: 96px; overflow-y: auto; }
    #fh-chat-chips button { background: transparent; color: var(--fh-text); border: 1px solid #555a68; border-radius: 16px; padding: 5px 11px; font-size: 12.5px; cursor: pointer; }
    #fh-chat-chips button:hover { border-color: var(--fh-accent); color: #fff; background: rgba(228,0,58,.2); }
    #fh-chat-form { display: flex; gap: 8px; padding: 10px 12px; border-top: 1px solid #2c303a; }
    #fh-chat-input { flex: 1; min-width: 0; border: 1px solid #3a3f4c; background: var(--fh-bg2); color: var(--fh-text); border-radius: 18px; padding: 8px 14px; font-size: 14px; outline: none; }
    #fh-chat-send { border: 0; background: var(--fh-accent); color: #fff; border-radius: 18px; padding: 0 16px; cursor: pointer; font-size: 14px; }
    #fh-chat-send:disabled { opacity: .5; cursor: default; }
    #fh-chat .fh-typing { display: inline-flex; gap: 4px; align-items: center; }
    #fh-chat .fh-typing i { width: 6px; height: 6px; border-radius: 50%; background: #9aa0ae; animation: fh-blink 1.2s infinite; }
    #fh-chat .fh-typing i:nth-child(2) { animation-delay: .2s; }
    #fh-chat .fh-typing i:nth-child(3) { animation-delay: .4s; }
    @keyframes fh-blink { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }

    /* ===== Chế độ phóng to: gần hết màn hình, chừa lại header của website ===== */
    #fh-chat.fh-is-full #fh-chat-toggle { display: none; }
    #fh-chat-panel.fh-full { top: var(--fh-top-offset); left: 24px; right: 24px; bottom: 24px; width: auto; height: auto; }
    #fh-chat-panel.fh-full #fh-chat-messages { padding: 20px max(24px, 6%); gap: 16px; }
    #fh-chat-panel.fh-full #fh-chat-chips,
    #fh-chat-panel.fh-full #fh-chat-form { padding-left: max(24px, 6%); padding-right: max(24px, 6%); }
    #fh-chat-panel.fh-full .fh-msg { font-size: 16px; max-width: 70%; }
    #fh-chat-panel.fh-full .fh-section { font-size: 17px; }
    #fh-chat-panel.fh-full .fh-cards { flex-wrap: wrap; overflow: visible; gap: 20px; }
    #fh-chat-panel.fh-full .fh-card { flex: 0 0 200px; }
    #fh-chat-panel.fh-full .fh-poster { width: 200px; height: 290px; }
    #fh-chat-panel.fh-full .fh-noposter { font-size: 16px; }
    #fh-chat-panel.fh-full .fh-title { font-size: 15px; }
    #fh-chat-panel.fh-full .fh-sub { font-size: 13px; }
    #fh-chat-panel.fh-full #fh-chat-input { font-size: 15px; padding: 10px 16px; }
    @media (max-width: 768px) {
        #fh-chat-panel.fh-full { top: 12px; left: 12px; right: 12px; bottom: 12px; }
        #fh-chat-panel.fh-full .fh-msg { max-width: 92%; }
        #fh-chat-panel.fh-full .fh-card { flex: 0 0 calc(50% - 10px); }
        #fh-chat-panel.fh-full .fh-poster { width: 100%; height: auto; aspect-ratio: 5 / 7; }
    }
</style>

<script>
(function () {
    var ENDPOINT = @json(route('chatbot.message'));
    var TOKEN = @json(csrf_token());
    var STORE = 'fh_chat_v1';

    var root = document.getElementById('fh-chat');
    var expandBtn = document.getElementById('fh-chat-expand');
    var panel = document.getElementById('fh-chat-panel');
    var toggle = document.getElementById('fh-chat-toggle');
    var list = document.getElementById('fh-chat-messages');
    var chipsBox = document.getElementById('fh-chat-chips');
    var form = document.getElementById('fh-chat-form');
    var input = document.getElementById('fh-chat-input');
    var sendBtn = document.getElementById('fh-chat-send');

    var state = { open: false, full: false, items: [], chips: [] };

    function save() {
        try { sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) {}
    }
    function load() {
        try {
            var raw = sessionStorage.getItem(STORE);
            if (raw) { state = JSON.parse(raw); }
        } catch (e) {}
    }
    function scrollDown() { list.scrollTop = list.scrollHeight; }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;   // textContent: chống XSS từ tên phim
        return n;
    }

    function renderUser(text) {
        list.appendChild(el('div', 'fh-msg fh-user', text));
    }

    function renderCards(movies) {
        if (!movies || !movies.length) return;
        var wrap = el('div', 'fh-cards');
        movies.forEach(function (m) {
            var a = el(m.url ? 'a' : 'div', 'fh-card');
            if (m.url) a.href = m.url;
            a.title = m.title;

            var poster = el('span', 'fh-poster');
            if (m.poster) {
                var img = document.createElement('img');
                img.src = m.poster;
                img.alt = m.title;
                img.loading = 'lazy';
                img.onerror = function () { img.remove(); poster.appendChild(el('span', 'fh-noposter', m.title)); };
                poster.appendChild(img);
            } else {
                poster.appendChild(el('span', 'fh-noposter', m.title));
            }
            if (m.badge) poster.appendChild(el('span', 'fh-badge', m.badge));
            if (m.rating) poster.appendChild(el('span', 'fh-rate', '★ ' + m.rating));
            a.appendChild(poster);

            a.appendChild(el('span', 'fh-title', m.title));
            var sub = [m.genres, m.status].filter(Boolean).join(' · ');
            if (sub) a.appendChild(el('span', 'fh-sub', sub));
            wrap.appendChild(a);
        });
        // Rê chuột vào hàng phim rồi cuộn: chuyển cuộn dọc thành cuộn ngang
        wrap.addEventListener('wheel', function (e) {
            if (wrap.scrollWidth <= wrap.clientWidth) return;
            var delta = Math.abs(e.deltaY) > Math.abs(e.deltaX) ? e.deltaY : e.deltaX;
            var atStart = wrap.scrollLeft <= 0 && delta < 0;
            var atEnd = wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 1 && delta > 0;
            if (atStart || atEnd) return;
            e.preventDefault();
            wrap.scrollLeft += delta;
        }, { passive: false });
        list.appendChild(wrap);
    }

    function renderBot(data) {
        list.appendChild(el('div', 'fh-msg fh-bot', data.reply));

        renderCards(data.movies);

        (data.sections || []).forEach(function (s) {
            if (!s.movies || !s.movies.length) return;
            list.appendChild(el('div', 'fh-section', s.title));
            renderCards(s.movies);
        });

        (data.links || []).forEach(function (l) {
            var a = el('a', 'fh-link', l.text);
            a.href = l.url;
            list.appendChild(a);
        });
    }

    function renderChips(chips) {
        chipsBox.innerHTML = '';
        (chips || []).forEach(function (c) {
            var b = el('button', null, c);
            b.type = 'button';
            b.addEventListener('click', function () { send(c); });
            chipsBox.appendChild(b);
        });
    }

    function renderAll() {
        list.innerHTML = '';
        state.items.forEach(function (it) {
            if (it.role === 'user') renderUser(it.text); else renderBot(it.data);
        });
        renderChips(state.chips);
        scrollDown();
    }

    function applyFull() {
        panel.classList.toggle('fh-full', !!state.full);
        root.classList.toggle('fh-is-full', !!state.full && !!state.open);   // chỉ ẩn nút bong bóng khi khung đang mở ở chế độ phóng to
        var label = state.full ? 'Thu nhỏ' : 'Phóng to';
        expandBtn.setAttribute('aria-label', label);
        expandBtn.title = label;
    }

    function setFull(v) {
        state.full = v;
        applyFull();
        save();
        scrollDown();
    }

    function setOpen(v) {
        state.open = v;
        panel.hidden = !v;
        applyFull();
        if (v) {
            if (!state.items.length) { send('Xin chào', true); }
            setTimeout(function () { input.focus(); }, 50);
        }
        save();
    }

    function send(text, silent) {
        text = (text || '').trim();
        if (!text) return;

        if (!silent) {
            state.items.push({ role: 'user', text: text });
            renderUser(text);
        }
        input.value = '';
        sendBtn.disabled = true;
        chipsBox.innerHTML = '';

        var typing = el('div', 'fh-msg fh-bot');
        typing.innerHTML = '<span class="fh-typing"><i></i><i></i><i></i></span>';
        list.appendChild(typing);
        scrollDown();

        fetch(ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ message: text })
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            typing.remove();
            state.items.push({ role: 'bot', data: data });
            state.chips = data.chips || [];
            renderBot(data);
            renderChips(state.chips);
        })
        .catch(function () {
            typing.remove();
            var data = { reply: 'Xin lỗi, mình đang gặp lỗi. Bạn thử lại sau nhé.', movies: [], links: [], chips: [] };
            state.items.push({ role: 'bot', data: data });
            renderBot(data);
            renderChips(['Phim đang chiếu', 'Phim sắp chiếu']);
        })
        .finally(function () {
            sendBtn.disabled = false;
            scrollDown();
            save();
            input.focus();
        });
    }

    toggle.addEventListener('click', function () { setOpen(panel.hidden); });
    expandBtn.addEventListener('click', function () { setFull(!state.full); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && state.full) setFull(false);   // Esc để thu nhỏ lại
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });

    load();
    renderAll();
    panel.hidden = !state.open;
    applyFull();
})();
</script>
