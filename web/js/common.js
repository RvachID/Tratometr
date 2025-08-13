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
})();
