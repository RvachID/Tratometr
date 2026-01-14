// common.js

window.reloadAliceSelect = async function (selectedId = null) {
    const select = document.getElementById('m-alice-item');
    if (!select) return;

    try {
        const r = await fetch('index.php?r=alice-item/list-json', {
            credentials: 'include'
        });
        if (!r.ok) throw new Error('alice list load failed');

        const items = await r.json();

        select.innerHTML = '<option value="">выберите...</option>';

        let hasPinned = false;
        let hasActive = false;
        let hasDone = false;

        const pinnedGroup = document.createElement('optgroup');
        pinnedGroup.label = '📌 Важное';

        const activeGroup = document.createElement('optgroup');
        activeGroup.label = '🛒 Остальное';

        for (const item of items) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.title;

            if (selectedId && String(item.id) === String(selectedId)) {
                opt.selected = true;
            }

            if (item.is_done) {
                opt.disabled = true;
                doneGroup.appendChild(opt);
                hasDone = true;
            } else if (item.is_pinned) {
                pinnedGroup.appendChild(opt);
                hasPinned = true;
            } else {
                activeGroup.appendChild(opt);
                hasActive = true;
            }
        }

        if (hasPinned) select.appendChild(pinnedGroup);
        if (hasActive) select.appendChild(activeGroup);
        if (hasDone) select.appendChild(doneGroup);

    } catch (e) {
        console.error('reloadAliceSelect error', e);
    }
};

document.addEventListener('DOMContentLoaded', () => {

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    document.querySelectorAll('.alice-title-input').forEach(input => {

        let originalValue = input.value;

        input.addEventListener('focus', () => {
            originalValue = input.value;
        });

        input.addEventListener('blur', async () => {
            const newValue = input.value.trim();
            const id = input.dataset.id;

            if (!id) return;
            if (newValue === originalValue) return;

            if (newValue === '') {
                input.value = originalValue;
                return;
            }

            try {
                const fd = new FormData();
                fd.append('title', newValue);

                const r = await fetch(`?r=alice-item/update&id=${id}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd,
                    credentials: 'include'
                });

                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }

                originalValue = newValue;

            } catch (e) {
                console.error('inline save failed', e);
                input.value = originalValue;
                alert('Не удалось сохранить название');
            }
        });
    });
});
document.querySelectorAll('.alice-swipe-wrap').forEach(wrap => {
    const card = wrap.querySelector('.alice-card');
    const id = wrap.dataset.id;
    const isPinned = wrap.dataset.pinned === '1';

    let startX = 0;
    let currentX = 0;
    let dragging = false;
    const threshold = 90;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    card.addEventListener('touchstart', e => {
        startX = e.touches[0].clientX;
        dragging = true;
        card.style.transition = 'none';
    });

    card.addEventListener('touchmove', e => {
        if (!dragging) return;
        currentX = e.touches[0].clientX - startX;
        currentX = Math.max(-140, Math.min(140, currentX));
        card.style.transform = `translateX(${currentX}px)`;
    });

    card.addEventListener('touchend', async () => {
        dragging = false;
        card.style.transition = 'transform .25s ease';

        // 👉 PIN / UNPIN
        if (currentX > threshold) {
            await fetch(`index.php?r=alice-item/toggle-pinned&id=${id}`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                credentials: 'include'
            });
            location.reload();
            return;
        }

        // 👈 DELETE
        if (currentX < -threshold) {
            card.style.transform = 'translateX(-100%)';
            setTimeout(() => wrap.remove(), 200);

            showUndo(async () => {
                await fetch(`index.php?r=alice-item/delete&id=${id}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrf },
                    credentials: 'include'
                });
            });
            return;
        }

        card.style.transform = 'translateX(0)';
        currentX = 0;
    });
});

/* Undo */
function showUndo(onConfirm) {
    const bar = document.createElement('div');
    bar.className = 'undo-bar';
    bar.innerHTML = `Удалено <button>Отменить</button>`;
    document.body.appendChild(bar);

    const timer = setTimeout(() => {
        onConfirm();
        bar.remove();
    }, 4000);

    bar.querySelector('button').onclick = () => {
        clearTimeout(timer);
        location.reload();
    };
}


(function () {
    const getCsrf = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const debounce = (fn, ms) => {
        let t;
        return (...a) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...a), ms);
        };
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
        if (!note) {
            slot.style.display = 'none';
            return;
        }
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

    window.Utils = {getCsrf, debounce, fmt2, resetPhotoPreview, renderNote};

    (function () {
        function fmt(v) {
            try {
                return new Intl.NumberFormat('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                    .format(Number(v));
            } catch (e) {
                return (Number(v).toFixed(2));
            }
        }


        function parseNum(s) {
            if (!s) return NaN;
            s = ('' + s)
                .replace(/\u00A0/g, ' ')   // NBSP -> space
                .replace(/\s+/g, '')
                .replace(',', '.');
            return parseFloat(s);
        }


        function calcSum() {
            var forms = document.querySelectorAll('.entry-form');
            var sum = 0;
            forms.forEach(function (f) {
                var a = parseNum(f.querySelector('input[name="amount"]')?.value || '0');
                var q = parseNum(f.querySelector('input[name="qty"]')?.value || '1');
                if (isNaN(a)) a = 0;
                if (isNaN(q)) q = 0;
                sum += a * q;
            });
            return sum;
        }


        function updateTotals() {
            var wrap = document.getElementById('total-wrap');
            if (!wrap) return;

            var sum = calcSum();
            if (typeof window.updateTotal === 'function') {
                window.updateTotal(sum);
            }
        }

        window.updateTotals = updateTotals;


        var handler = function (e) {
            var t = e.target;
            if (!t) return;
            var name = t.name || '';
            if (name === 'amount' || name === 'qty') updateTotals();
        };
        document.addEventListener('input', handler, true);   // capture
        document.addEventListener('change', handler, true);  // capture
        document.addEventListener('keyup', handler, true);   // РЅР° РІСЃСЏРєРёР№


        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t) return;
            if (t.closest('.save-entry') || t.closest('.delete-entry') || t.closest('#m-save')) {
                setTimeout(updateTotals, 0); // РїРѕСЃР»Рµ DOM-РїСЂР°РІРѕРє
            }
        }, true);


        var entriesRoot = document.querySelector('.mt-3.text-start');
        if (entriesRoot && 'MutationObserver' in window) {
            var mo = new MutationObserver(debounce(updateTotals, 50));
            mo.observe(entriesRoot, {childList: true, subtree: true});
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateTotals);
        } else {
            updateTotals();
        }
    })();
})();








