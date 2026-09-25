/* BizVaza voice-to-fill: fills [data-voice] fields using the browser Speech API */
(function () {
    'use strict';

    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;

    var MIC_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>';

    function toast(msg) {
        var t = document.querySelector('.voice-toast');
        if (!t) {
            t = document.createElement('div');
            t.className = 'voice-toast';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('show'); }, 2800);
    }

    function enhance(el) {
        if (!el || el.dataset.voiceEnhanced) return;
        el.dataset.voiceEnhanced = '1';

        var wrap = document.createElement('span');
        wrap.className = 'voice-wrap';
        el.parentNode.insertBefore(wrap, el);
        wrap.appendChild(el);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mic-btn';
        btn.setAttribute('aria-label', 'Fill this field by voice');
        btn.setAttribute('title', 'Tap to speak');
        btn.innerHTML = MIC_SVG;
        wrap.appendChild(btn);

        var rec = new SR();
        rec.lang = el.getAttribute('data-voice-lang') || document.documentElement.lang || 'en-US';
        rec.continuous = false;
        rec.interimResults = true;
        rec.maxAlternatives = 1;

        var appendMode = el.hasAttribute('data-voice-append');
        var prefix = '';
        var listening = false;

        function setListening(on) {
            listening = on;
            btn.classList.toggle('listening', on);
            if (on) {
                btn.setAttribute('title', 'Listening…');
                prefix = appendMode ? el.value : '';
            } else {
                btn.setAttribute('title', 'Tap to speak');
            }
        }

        function stop() {
            try { rec.stop(); } catch (e) {}
            setListening(false);
        }

        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (listening) { stop(); return; }
            setListening(true);
            if (!appendMode) el.value = '';
            try { rec.start(); }
            catch (e) { setListening(false); toast('Could not start the microphone. Try again.'); }
        });

        rec.onstart = function () {
            setListening(true);
            if (!appendMode) el.value = '';
        };

        rec.onresult = function (ev) {
            var text = '';
            for (var i = ev.resultIndex; i < ev.results.length; i++) {
                text += ev.results[i][0].transcript;
            }
            el.value = appendMode ? prefix + text : text;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        };

        rec.onend = function () {
            setListening(false);
        };

        rec.onerror = function (ev) {
            setListening(false);
            var map = {
                'not-allowed': 'Microphone permission blocked. Allow it in your browser settings.',
                'service-not-allowed': 'Microphone permission blocked. Allow it in your browser settings.',
                'no-speech': 'No speech detected. Tap the mic and try again.',
                'audio-capture': 'No microphone found.',
                'network': 'Speech service offline. Check your connection.'
            };
            toast(map[ev.error] || 'Speech input error. Try again.');
        };
    }

    function scan() {
        var els = document.querySelectorAll('[data-voice]:not([data-voice-enhanced])');
        for (var i = 0; i < els.length; i++) enhance(els[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    if ('MutationObserver' in window) {
        var mo = new MutationObserver(function () { scan(); });
        mo.observe(document.documentElement, { childList: true, subtree: true });
    }
})();
