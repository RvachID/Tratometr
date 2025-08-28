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

    // ===== Итоги/лимит =====
    (function(){
        // Форматирование: 12 345,67
        function fmt(v){
            try {
                return new Intl.NumberFormat('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2})
                    .format(Number(v));
            } catch(e){
                return (Number(v).toFixed(2));
            }
        }

        // Универсальный парсер "12 345,67" / "12345.67" / с NBSP
        function parseNum(s){
            if (!s) return NaN;
            s = (''+s)
                .replace(/\u00A0/g, ' ')   // NBSP -> space
                .replace(/\s+/g, '')
                .replace(',', '.');
            return parseFloat(s);
        }

        // Сумма по всем позициям (amount * qty)
        function calcSum(){
            var forms = document.querySelectorAll('.entry-form');
            var sum = 0;
            forms.forEach(function(f){
                var a = parseNum(f.querySelector('input[name="amount"]')?.value || '0');
                var q = parseNum(f.querySelector('input[name="qty"]')?.value || '1');
                if (isNaN(a)) a = 0;
                if (isNaN(q)) q = 0;
                sum += a * q;
            });
            return sum;
        }

        // Главный апдейтер
        function updateTotals(){
            var wrap = document.getElementById('total-wrap');
            if (!wrap) return;

            var sum = calcSum();

            // Новая вёрстка?
            var remEl = document.getElementById('scan-remaining');
            var sumEl = document.getElementById('scan-sum');
            var limEl = document.getElementById('scan-limit');

            // Определяем наличие лимита
            var dsLimit = (wrap.getAttribute('data-limit') || '').trim();
            var limit = parseNum(dsLimit);
            var hasLimit = !!dsLimit;

            // Фолбэк: пробуем вытащить лимит из текста "Лимит: ..."
            if (!hasLimit && limEl) {
                var limTxt = limEl.textContent || '';
                var num = parseNum(limTxt);
                if (!isNaN(num)) { limit = num; hasLimit = true; }
            }
            // Явный флаг
            if (!hasLimit && wrap.getAttribute('data-has-limit') === '1') {
                hasLimit = true;
                if (isNaN(limit)) limit = 0;
            }

            if (remEl || sumEl || limEl) {
                // Новая двухстрочная вёрстка
                if (hasLimit) {
                    var rest = limit - sum;
                    if (remEl){
                        remEl.textContent = fmt(rest);
                        remEl.classList.toggle('text-danger', rest < 0);
                        remEl.classList.toggle('fw-bold', rest < 0);
                    }
                    if (sumEl) sumEl.textContent = fmt(sum);
                    if (limEl) limEl.textContent = fmt(limit);
                } else {
                    // Без лимита: показываем просто сумму
                    if (sumEl) sumEl.textContent = fmt(sum);
                    if (remEl){
                        remEl.textContent = fmt(sum);
                        remEl.classList.remove('text-danger','fw-bold');
                    }
                }
                return;
            }

            // Старая вёрстка (одна строка с #scan-total)
            var totalEl = document.getElementById('scan-total');
            if (!totalEl) return;

            if (hasLimit) {
                var restOld = limit - sum;
                totalEl.textContent = fmt(restOld);
                totalEl.classList.toggle('text-danger', restOld < 0);
                totalEl.classList.toggle('fw-bold', restOld < 0);
            } else {
                totalEl.textContent = fmt(sum);
                totalEl.classList.remove('text-danger', 'fw-bold');
            }
        }

        // Делаем функцию доступной извне
        window.updateTotals = updateTotals;

        // ----- Слушатели (в capture, на случай stopPropagation) -----
        var handler = function(e){
            var t = e.target;
            if (!t) return;
            var name = t.name || '';
            if (name === 'amount' || name === 'qty') updateTotals();
        };
        document.addEventListener('input', handler, true);   // capture
        document.addEventListener('change', handler, true);  // capture
        document.addEventListener('keyup', handler, true);   // на всякий

        // Клики по кнопкам сохранения/удаления
        document.addEventListener('click', function(e){
            var t = e.target;
            if (!t) return;
            if (t.closest('.save-entry') || t.closest('.delete-entry') || t.closest('#m-save')) {
                setTimeout(updateTotals, 0); // после DOM-правок
            }
        }, true);

        // Наблюдаем за списком позиций — реакция на добавление/удаление
        var entriesRoot = document.querySelector('.mt-3.text-start');
        if (entriesRoot && 'MutationObserver' in window) {
            var mo = new MutationObserver(debounce(updateTotals, 50));
            mo.observe(entriesRoot, {childList: true, subtree: true});
        }

        // Первый запуск
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateTotals);
        } else {
            updateTotals();
        }
    })();
})();
