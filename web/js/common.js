// common.js
(function () {
    const getCsrf = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const debounce = (fn, ms) => {
        let t;
        return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    };

    const fmt2 = (x) => Number(x || 0).toFixed(2);

    function resetPhotoPreview(mPhotoWrap, mShowPhotoBtn, mPhotoImg) {
        if (mPhotoWrap) mPhotoWrap.style.display = 'none';
        if (mShowPhotoBtn) mShowPhotoBtn.textContent = 'Показать скан';
        if (mPhotoImg) mPhotoImg.src = '';
    }

    // Рендер заметки в слот .entry-note-wrap (или создаём перед кнопками)
    function renderNote(container, note) {
        let slot = container.querySelector('.entry-note-wrap');
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'entry-note-wrap';
            slot.style.marginTop = '6px';
            const btns = container.querySelector('.d-flex.gap-2.mt-2');
            container.insertBefore(slot, btns ?? null);
        }

        slot.innerHTML = '';
        if (!note) { slot.style.display = 'none'; return; }
        slot.style.display = '';

        const TEXT = document.createElement('div');
        TEXT.className = 'entry-note-text';
        TEXT.textContent = note;
        TEXT.style.display = '-webkit-box';
        TEXT.style.webkitBoxOrient = 'vertical';
        TEXT.style.webkitLineClamp = '2';
        TEXT.style.overflow = 'hidden';
        TEXT.style.wordBreak = 'break-word';
        TEXT.style.color = '#555';

        const TOGGLE = document.createElement('button');
        TOGGLE.type = 'button';
        TOGGLE.className = 'entry-note-toggle';
        TOGGLE.textContent = 'Ещё';
        TOGGLE.style.background = 'none';
        TOGGLE.style.border = 'none';
        TOGGLE.style.padding = '0';
        TOGGLE.style.margin = '4px 0 0 0';
        TOGGLE.style.color = '#0d6efd';
        TOGGLE.style.fontSize = '0.9rem';
        TOGGLE.style.cursor = 'pointer';
        TOGGLE.style.display = (note.length > 60) ? 'inline-block' : 'none';

        let expanded = false;
        TOGGLE.onclick = () => {
            expanded = !expanded;
            if (expanded) {
                TEXT.style.webkitLineClamp = 'unset';
                TEXT.style.display = 'block';
                TOGGLE.textContent = 'Свернуть';
            } else {
                TEXT.style.display = '-webkit-box';
                TEXT.style.webkitLineClamp = '2';
                TOGGLE.textContent = 'Ещё';
            }
        };

        slot.appendChild(TEXT);
        slot.appendChild(TOGGLE);
    }

    window.Utils = { getCsrf, debounce, fmt2, resetPhotoPreview, renderNote };
    (function(){
        // Красиво форматируем число: 12 345.67
        function fmt(v){
            try { return new Intl.NumberFormat('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(v)); }
            catch(e){ return (Number(v).toFixed(2)).replace('.', '.'); }
        }

        // Сумма по всем позициям (amount * qty)
        function calcSum(){
            var forms = document.querySelectorAll('.entry-form');
            var sum = 0;
            forms.forEach(function(f){
                var a = parseFloat((f.querySelector('input[name="amount"]')?.value || '0').replace(',','.')) || 0;
                var q = parseFloat((f.querySelector('input[name="qty"]')?.value || '1').replace(',','.')) || 0;
                sum += a * q;
            });
            return sum;
        }

        // Обновление DOM итогов
        window.updateTotals = function(){
            var wrap = document.getElementById('total-wrap');
            if (!wrap) return;

            var hasLimit = wrap.getAttribute('data-has-limit') === '1';
            var sum = calcSum();

            if (!hasLimit) {
                var totalEl = document.getElementById('scan-total');
                if (totalEl) totalEl.textContent = fmt(sum);
                return;
            }

            var limitStr = wrap.getAttribute('data-limit') || '';
            var limit = parseFloat((limitStr+'').replace(/\s+/g,'').replace(',','.')) || 0;
            var rest = limit - sum;

            var remEl = document.getElementById('scan-remaining');
            var sumEl = document.getElementById('scan-sum');
            var limEl = document.getElementById('scan-limit');

            if (remEl){
                remEl.textContent = fmt(rest);
                remEl.classList.toggle('text-danger', rest < 0);
                remEl.classList.toggle('fw-bold', rest < 0);
            }
            if (sumEl) sumEl.textContent = fmt(sum);
            if (limEl) limEl.textContent = fmt(limit);
        };

        // Триггеры: любые изменения amount/qty и после сохранения/удаления
        document.addEventListener('input', function(e){
            if (e.target && (e.target.name === 'amount' || e.target.name === 'qty')) {
                window.updateTotals();
            }
        });

        // на старте
        document.addEventListener('DOMContentLoaded', window.updateTotals);
    })();
})();
