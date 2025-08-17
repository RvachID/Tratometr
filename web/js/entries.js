// entries.js
(function () {
    const { getCsrf, debounce, fmt2, renderNote } = window.Utils;

    function bindEntryRow(container) {
        const form = container.querySelector('form.entry-form');
        if (!form) return;

        // показать заметку, если пришла с сервера (hidden input)
        const noteInput = form.querySelector('input[name="note"]');
        const noteVal = noteInput ? (noteInput.value || '').trim() : '';
        if (noteVal) renderNote(container, noteVal);

        const id = form.dataset.id;
        const amountEl = form.querySelector('input[name="amount"]');
        const qtyEl = form.querySelector('input[name="qty"]');
        const delBtn = container.querySelector('.delete-entry');

        if (amountEl) amountEl.value = fmt2(amountEl.value);
        amountEl?.addEventListener('blur', () => { amountEl.value = fmt2(amountEl.value); });

        // Вставим +/- для qty если нет
        let minusBtn = form.querySelector('.qty-minus');
        let plusBtn  = form.querySelector('.qty-plus');
        if (!minusBtn || !plusBtn) {
            const parent = qtyEl.parentElement;
            const group  = document.createElement('div');
            group.className = 'input-group mb-1';

            minusBtn = document.createElement('button');
            minusBtn.type = 'button';
            minusBtn.className = 'btn btn-outline-secondary qty-minus';
            minusBtn.textContent = '–';

            plusBtn = document.createElement('button');
            plusBtn.type = 'button';
            plusBtn.className = 'btn btn-outline-secondary qty-plus';
            plusBtn.textContent = '+';

            qtyEl.classList.add('form-control','text-center');
            parent.insertBefore(group, qtyEl);
            group.appendChild(minusBtn);
            group.appendChild(qtyEl);
            group.appendChild(plusBtn);
        }

        const csrf = getCsrf();

        const doSave = async () => {
            const fd = new FormData();
            fd.append('amount', amountEl.value);
            fd.append('qty', qtyEl.value);
            try {
                const r = await fetch(`index.php?r=scan/update&id=${id}`, {
                    method:'POST', headers:{'X-CSRF-Token':csrf}, body:fd, credentials:'include'
                });
                const ct=r.headers.get('content-type')||'';
                if (!ct.includes('application/json')) return;
                const res = await r.json();
                if (res?.success && typeof res.total!=='undefined') updateTotal(res.total);
            } catch(e){ console.error('autosave error', e); }
        };
        const debouncedSave = debounce(doSave, 400);

        amountEl.addEventListener('input', debouncedSave);
        qtyEl.addEventListener('input', debouncedSave);

        minusBtn.addEventListener('click', () => {
            let v = parseFloat(qtyEl.value || '1'); v = Math.max(0, v - 1);
            qtyEl.value = (v % 1 === 0) ? v.toFixed(0) : v.toFixed(3);
            debouncedSave();
        });
        plusBtn.addEventListener('click', () => {
            let v = parseFloat(qtyEl.value || '1'); v = v + 1;
            qtyEl.value = v.toFixed(0);
            debouncedSave();
        });

        // Удаление
        if (delBtn) {
            delBtn.onclick = async () => {
                if (!confirm('Удалить запись?')) return;
                try {
                    const r = await fetch(`index.php?r=scan/delete&id=${id}`, {
                        method:'POST', headers:{'X-CSRF-Token':csrf}, credentials:'include'
                    });
                    const res = await r.json();
                    if (res.success) {
                        container.remove();
                        if (typeof res.total!=='undefined') updateTotal(res.total);
                    } else {
                        alert(res.error || 'Не удалось удалить');
                    }
                } catch(e){ alert('Ошибка удаления: '+e.message); }
            };
        }

        form.querySelector('.save-entry')?.classList.add('d-none');
    }

    function addEntryToTop(entry) {
        const listWrap = document.querySelector('.mt-3.text-start');
        if (!listWrap) return;

        const div = document.createElement('div');
        div.className = 'border p-2 mb-2';
        div.innerHTML = `
      <form class="entry-form" data-id="${entry.id}">
        Цена:
        <input type="number" step="0.01" name="amount" value="${fmt2(entry.amount)}" class="form-control mb-1">
        <input type="hidden" name="category" value="${entry.category ?? ''}">
        <input type="hidden" name="note" value="${(entry.note ?? '').replace(/"/g,'&quot;')}">
        Штуки или килограммы:
        <input type="number" step="0.001" name="qty" value="${entry.qty}" class="form-control mb-1">
      </form>
      <div class="entry-note-wrap"></div>
      <div class="d-flex gap-2 mt-2">
        <button class="btn btn-sm btn-outline-danger delete-entry" type="button">🗑 Удалить</button>
        <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
      </div>
    `;
        listWrap.prepend(div);
        bindEntryRow(div);

        const noteVal = (entry.note ?? '').trim();
        if (noteVal) renderNote(div, noteVal);
    }

    function updateTotal(total) {
        const el = document.getElementById('scan-total');
        if (el) {
            el.textContent = Number(total).toLocaleString('ru-RU', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        }
    }

    const doSave = async () => {
        const fd = new FormData();
        fd.append('amount', amountEl.value);
        fd.append('qty', qtyEl.value);
        try {
            const r = await fetch(`index.php?r=scan/update&id=${id}`, {
                method:'POST', headers:{'X-CSRF-Token':csrf}, body:fd, credentials:'include'
            });
            let res;
            const ct = r.headers.get('content-type') || '';
            if (ct.includes('application/json')) {
                res = await r.json();
            } else {
                const text = await r.text();
                console.error('update: non-JSON response', text);
                alert('Ошибка сохранения (сервер вернул не-JSON)');
                return;
            }
            if (res.success && typeof res.total !== 'undefined') {
                updateTotal(res.total);
            } else if (res.error) {
                alert(res.error);
            }
        } catch (e) {
            console.error('autosave error', e);
            alert('Ошибка сети при сохранении');
        }
    };


    // Инициализация на странице списка
    document.querySelectorAll('.entry-form').forEach(f => bindEntryRow(f.closest('.border')));

    // экспорт для scanner.js
    window.addEntryToTop = addEntryToTop;
    window.updateTotal   = updateTotal;
})();
