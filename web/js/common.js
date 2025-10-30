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
        if (mShowPhotoBtn) mShowPhotoBtn.textContent = 'РџРѕРєР°Р·Р°С‚СЊ СЃРєР°РЅ';
        if (mPhotoImg) mPhotoImg.src = '';
    }

    // Р РµРЅРґРµСЂ Р·Р°РјРµС‚РєРё РІ СЃР»РѕС‚ .entry-note-wrap (РёР»Рё СЃРѕР·РґР°С‘Рј РїРµСЂРµРґ РєРЅРѕРїРєР°РјРё)
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
        TOGGLE.textContent = 'Р•С‰С‘';
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
                TOGGLE.textContent = 'РЎРІРµСЂРЅСѓС‚СЊ';
            } else {
                TEXT.style.display = '-webkit-box';
                TEXT.style.webkitLineClamp = '2';
                TOGGLE.textContent = 'Р•С‰С‘';
            }
        };

        slot.appendChild(TEXT);
        slot.appendChild(TOGGLE);
    }

    window.Utils = { getCsrf, debounce, fmt2, resetPhotoPreview, renderNote };

    // ===== РС‚РѕРіРё/Р»РёРјРёС‚ =====
    (function(){
        // Р¤РѕСЂРјР°С‚РёСЂРѕРІР°РЅРёРµ: 12 345,67
        function fmt(v){
            try {
                return new Intl.NumberFormat('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2})
                    .format(Number(v));
            } catch(e){
                return (Number(v).toFixed(2));
            }
        }

        // РЈРЅРёРІРµСЂСЃР°Р»СЊРЅС‹Р№ РїР°СЂСЃРµСЂ "12 345,67" / "12345.67" / СЃ NBSP
        function parseNum(s){
            if (!s) return NaN;
            s = (''+s)
                .replace(/\u00A0/g, ' ')   // NBSP -> space
                .replace(/\s+/g, '')
                .replace(',', '.');
            return parseFloat(s);
        }

        // РЎСѓРјРјР° РїРѕ РІСЃРµРј РїРѕР·РёС†РёСЏРј (amount * qty)
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

        // Р“Р»Р°РІРЅС‹Р№ Р°РїРґРµР№С‚РµСЂ
                function updateTotals(){
            var wrap = document.getElementById('total-wrap');
            if (!wrap) return;

            var sum = calcSum();
            if (typeof window.updateTotal === 'function') {
                window.updateTotal(sum);
            }
        }
window.updateTotals = updateTotals;

        // ----- РЎР»СѓС€Р°С‚РµР»Рё (РІ capture, РЅР° СЃР»СѓС‡Р°Р№ stopPropagation) -----
        var handler = function(e){
            var t = e.target;
            if (!t) return;
            var name = t.name || '';
            if (name === 'amount' || name === 'qty') updateTotals();
        };
        document.addEventListener('input', handler, true);   // capture
        document.addEventListener('change', handler, true);  // capture
        document.addEventListener('keyup', handler, true);   // РЅР° РІСЃСЏРєРёР№

        // РљР»РёРєРё РїРѕ РєРЅРѕРїРєР°Рј СЃРѕС…СЂР°РЅРµРЅРёСЏ/СѓРґР°Р»РµРЅРёСЏ
        document.addEventListener('click', function(e){
            var t = e.target;
            if (!t) return;
            if (t.closest('.save-entry') || t.closest('.delete-entry') || t.closest('#m-save')) {
                setTimeout(updateTotals, 0); // РїРѕСЃР»Рµ DOM-РїСЂР°РІРѕРє
            }
        }, true);

        // РќР°Р±Р»СЋРґР°РµРј Р·Р° СЃРїРёСЃРєРѕРј РїРѕР·РёС†РёР№ вЂ” СЂРµР°РєС†РёСЏ РЅР° РґРѕР±Р°РІР»РµРЅРёРµ/СѓРґР°Р»РµРЅРёРµ
        var entriesRoot = document.querySelector('.mt-3.text-start');
        if (entriesRoot && 'MutationObserver' in window) {
            var mo = new MutationObserver(debounce(updateTotals, 50));
            mo.observe(entriesRoot, {childList: true, subtree: true});
        }

        // РџРµСЂРІС‹Р№ Р·Р°РїСѓСЃРє
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateTotals);
        } else {
            updateTotals();
        }
    })();
})();








