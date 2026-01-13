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

        // центрируем ввод цены
        if (amountEl) {
            amountEl.classList.add('text-center');
            amountEl.value = fmt2(amountEl.value);
            amountEl.addEventListener('blur', () => { amountEl.value = fmt2(amountEl.value); });
        }

        // Вставим +/- для qty если нет
        let minusBtn = form.querySelector('.qty-minus');
        let plusBtn  = form.querySelector('.qty-plus');
        if ((!minusBtn || !plusBtn) && qtyEl) {
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

        // ===== Низ карточки: итог по позиции + удалить справа =====
        // Если разметки нет — создаём
        let footer = container.querySelector('.item-footer');
        if (!footer) {
            footer = document.createElement('div');
            footer.className = 'item-footer d-flex align-items-center justify-content-between mt-2';

            footer.innerHTML = `
        <div class="small text-muted">
          Итого по позиции: <strong class="item-subtotal">0.00</strong>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary delete-entry" type="button">🗑 Удалить</button>
          <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
        </div>
      `;

            // Вставляем сразу после блока заметки, если он есть, иначе после формы
            const noteWrap = container.querySelector('.entry-note-wrap');
            if (noteWrap) noteWrap.insertAdjacentElement('afterend', footer);
            else form.insertAdjacentElement('afterend', footer);

            // Удалим старые «actions»-контейнеры, если были
            container.querySelectorAll('.d-flex.gap-2.mt-2').forEach(el => {
                if (!el.classList.contains('item-footer')) el.remove();
            });
        }
        const subtotalEl = footer.querySelector('.item-subtotal');
        const delBtn = footer.querySelector('.delete-entry');

        // ===== Подсчёт итога по позиции на лету =====
        function calcRowTotal() {
            const a = parseFloat((amountEl?.value || '0').toString().replace(',', '.')) || 0;
            const q = parseFloat((qtyEl?.value || '0').toString().replace(',', '.')) || 0;
            return a * q;
        }
        function renderSubtotal() {
            if (!subtotalEl) return;
            const t = calcRowTotal();
            subtotalEl.textContent = Number.isFinite(t) ? t.toFixed(2) : '0.00';
        }
        // начальный рендер
        renderSubtotal();

        const csrf = getCsrf();

        // ===== Автосохранение на сервер + обновление общего итога =====
        const doSave = async () => {
            const fd = new FormData();
            fd.append('amount', amountEl.value);
            fd.append('qty', qtyEl.value);
            try {
                const r = await fetch(`index.php?r=scan/update&id=${id}`, {
                    method:'POST', headers:{'X-CSRF-Token':csrf}, body:fd, credentials:'include'
                });
                const ct=r.headers.get('content-type')||'';
                if (!ct.includes('application/json')) {
                    const txt = await r.text();
                    console.error('update non-JSON:', txt);
                    return;
                }
                const res = await r.json();
                if (res?.success && typeof res.total!=='undefined') {
                    updateTotal(res.total);
                } else if (res?.error) {
                    alert(res.error);
                }
            } catch(e){ console.error('autosave error', e); }
        };
        const debouncedSave = debounce(doSave, 400);

        // ===== Обработчики ввода =====
        amountEl?.addEventListener('input', () => { renderSubtotal(); debouncedSave(); });
        qtyEl?.addEventListener('input',    () => { renderSubtotal(); debouncedSave(); });

        minusBtn?.addEventListener('click', () => {
            let v = parseFloat(qtyEl.value || '1'); v = Math.max(0, v - 1);
            qtyEl.value = (v % 1 === 0) ? v.toFixed(0) : v.toFixed(3);
            renderSubtotal();
            debouncedSave();
        });
        plusBtn?.addEventListener('click', () => {
            let v = parseFloat(qtyEl.value || '1'); v = v + 1;
            qtyEl.value = v.toFixed(0);
            renderSubtotal();
            debouncedSave();
        });

        // ===== Удаление =====
        if (delBtn) {
            delBtn.onclick = async () => {
                if (!confirm('Удалить запись?')) return;
                try {
                    const r = await fetch(`index.php?r=price/delete&id=${id}`, {
                        method:'POST', headers:{'X-CSRF-Token':csrf}, credentials:'include'
                    });
                    const ct = r.headers.get('content-type')||'';
                    const res = ct.includes('application/json') ? await r.json() : { success:false };
                    if (res.success) {
                        if (res.success) {
                            const aliceId = container.dataset.aliceId;
                            container.remove();
                            await reloadAliceSelect();
                            if (typeof res.total !== 'undefined') {
                                updateTotal(res.total);
                            }
                        }

                    } else {
                        alert(res.error || 'Не удалось удалить');
                    }
                } catch(e){ alert('Ошибка удаления: '+e.message); }
            };
        }

        // Скрытая «сохранить» — не используем
        form.querySelector('.save-entry')?.classList.add('d-none');
    }

    function escapeHtml(str) {
        return (str ?? '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[s]));
    }

    function addEntryToTop(entry) {
        const listWrap = document.querySelector('.mt-3.text-start');
        if (!listWrap) return;

        const div = document.createElement('div');
        div.className = 'border p-2 mb-2';

        const aliceTitle = (entry.alice_title ?? '').trim();
        const noteVal    = (entry.note ?? '').trim();

        // --- верхний бейдж, если есть товар из списка ---
        if (aliceTitle) {
            div.innerHTML = `
            <div class="mb-2">
                <span class="badge entry-badge">
                    ${escapeHtml(aliceTitle)}
                </span>
            </div>
        `;
        }

        // --- основное содержимое карточки ---
        div.insertAdjacentHTML('beforeend', `
      <form class="entry-form" data-id="${entry.id}">
        Цена:
        <input type="number" step="0.01" name="amount"
               value="${fmt2(entry.amount)}"
               class="form-control text-center mb-1">

        <input type="hidden" name="category" value="${entry.category ?? ''}">
        <input type="hidden" name="note" value="${escapeHtml(entry.note ?? '')}">

        Штук или килограммы:
        <input type="number" step="0.001" name="qty"
               value="${entry.qty}"
               class="form-control mb-1">
      </form>

      <div class="entry-note-wrap"></div>

      <div class="item-footer d-flex align-items-center justify-content-between mt-2">
        <div class="small text-muted">
          Итого по позиции: <strong class="item-subtotal">0.00</strong>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-danger delete-entry" type="button">🗑 Удалить</button>
          <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
        </div>
      </div>
    `);

        listWrap.prepend(div);
        bindEntryRow(div);

        // --- рендерим заметку ТОЛЬКО если она отличается от заголовка ---
        if (typeof renderNote === 'function' && noteVal && noteVal !== aliceTitle) {
            renderNote(div, noteVal);
        }
    }

    function updateTotal(total) {
        const wrap = document.getElementById('total-wrap');
        if (!wrap) return;

        const labelEl = document.getElementById('scan-total-label')
            || document.getElementById('scan-remaining-label');
        const totalEl = document.getElementById('scan-total');
        const remainingEl = document.getElementById('scan-remaining');
        const secondaryEl = document.getElementById('scan-secondary');
        const sumEl = document.getElementById('scan-sum');
        const limitEl = document.getElementById('scan-limit');

        const formatValue = (num) => Number(num || 0).toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        const limAttr = wrap.dataset.limit || '';
        const limit = limAttr === '' ? NaN : parseFloat(limAttr);
        const sum = Number(total || 0);

        if (Number.isNaN(limit)) {
            if (labelEl) labelEl.textContent = 'Общая сумма:';
            if (totalEl) {
                totalEl.textContent = formatValue(sum);
                totalEl.classList.remove('text-danger', 'fw-bold');
            }
            if (remainingEl) {
                remainingEl.textContent = formatValue(sum);
                remainingEl.classList.remove('text-danger', 'fw-bold');
            }
            if (secondaryEl) secondaryEl.classList.add('d-none');
            if (sumEl) sumEl.textContent = formatValue(sum);
            if (limitEl) limitEl.textContent = '';
        } else {
            const remaining = limit - sum;
            const formattedRemaining = formatValue(remaining);
            if (labelEl) labelEl.textContent = 'До лимита:';

            const targetEl = remainingEl || totalEl;
            if (targetEl) {
                targetEl.textContent = formattedRemaining;
                targetEl.classList.toggle('text-danger', remaining < 0);
                targetEl.classList.toggle('fw-bold', remaining < 0);
            }
            if (totalEl && totalEl !== targetEl) {
                totalEl.textContent = formattedRemaining;
                totalEl.classList.toggle('text-danger', remaining < 0);
                totalEl.classList.toggle('fw-bold', remaining < 0);
            }
            if (secondaryEl) secondaryEl.classList.remove('d-none');
            if (sumEl) sumEl.textContent = formatValue(sum);
            if (limitEl) limitEl.textContent = formatValue(limit);
        }
    }

    // Инициализация на странице списка
    document.querySelectorAll('.entry-form').forEach(f => bindEntryRow(f.closest('.border')));

    // экспорт для scanner.js
    window.addEntryToTop = addEntryToTop;
    window.updateTotal   = updateTotal;
})();
