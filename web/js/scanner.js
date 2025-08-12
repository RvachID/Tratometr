// ===== DOM =====
const startBtn    = document.getElementById('start-scan');
const wrap        = document.getElementById('camera-wrapper');
const video       = document.getElementById('camera');
const captureBtn  = document.getElementById('capture');
const previewImg  = document.getElementById('preview-image'); // превью на главной (не в модалке)

// элементы внутри кнопки "Сфоткать" для спиннера
const btnTextEl    = captureBtn.querySelector('.btn-text') || captureBtn; // fallback
const btnSpinnerEl = captureBtn.querySelector('.spinner');

// ===== Модалка предпросмотра =====
const scanModalEl   = document.getElementById('scanModal');
const mAmountEl     = document.getElementById('m-amount');
const mQtyEl        = document.getElementById('m-qty');
const mQtyMinusEl   = document.getElementById('m-qty-minus');
const mQtyPlusEl    = document.getElementById('m-qty-plus');
const mNoteEl       = document.getElementById('m-note');
const mShowPhotoBtn = document.getElementById('m-show-photo');
const mPhotoWrap    = document.getElementById('m-photo-wrap');
const mPhotoImg     = document.getElementById('m-photo');
const mRetakeBtn    = document.getElementById('m-retake');
const mSaveBtn      = document.getElementById('m-save');

// Bootstrap modal (должен быть подключён Bootstrap 5)
let bootstrapModal = scanModalEl ? new bootstrap.Modal(scanModalEl) : null;

// ===== Состояние =====
let currentStream = null;
let scanBusy = false;
let lastPhotoURL = null;        // blob URL для фото (показываем по запросу в модалке)
let lastParsedText = '';        // ParsedText от OCR (опц. для сохранения)

// ===== Утилиты =====
function debounce(fn, ms) {
    let t;
    return function(...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), ms);
    };
}

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

// ===== Камера =====
async function stopStream() {
    if (currentStream) {
        currentStream.getTracks().forEach(t => t.stop());
        currentStream = null;
    }
}

async function getStream(constraints) {
    return await navigator.mediaDevices.getUserMedia(constraints);
}

async function initCamera() {
    await stopStream();

    // пробуем тыльную камеру
    const primary = { video: { facingMode: { ideal: 'environment' } }, audio: false };

    try {
        currentStream = await getStream(primary);
    } catch (e) {
        console.warn('environment camera failed, fallback to any camera:', e?.name, e?.message);
        currentStream = await getStream({ video: true, audio: false });
    }

    video.setAttribute('playsinline', 'true'); // iOS/Safari
    video.srcObject = currentStream;

    // ждём метаданные и play()
    await new Promise((res) => {
        const h = () => { video.removeEventListener('loadedmetadata', h); res(); };
        if (video.readyState >= 1) res(); else video.addEventListener('loadedmetadata', h);
    });

    try { await video.play(); } catch (e) { console.warn('video.play blocked', e); }
}

// ===== Снимок + OCR (recognize) =====
async function captureAndRecognize() {
    if (scanBusy) return;
    scanBusy = true;
    captureBtn.disabled = true;

    // показать спиннер/текст
    if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
    if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
    else captureBtn.textContent = 'Сканируем…';

    try {
        if (!video.videoWidth || !video.videoHeight) {
            alert('Камера ещё не готова');
            return;
        }

        // рисуем в канвас (динамический)
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // Ч/Б бинаризация (простая)
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = img.data;
        for (let i = 0; i < data.length; i += 4) {
            const avg = (data[i] + data[i+1] + data[i+2]) / 3;
            const bw = avg > 128 ? 255 : 0;
            data[i] = data[i+1] = data[i+2] = bw;
        }
        ctx.putImageData(img, 0, 0);

        // превью на странице (как раньше), но фото в модалке показываем по кнопке
        await new Promise((resolve) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    alert('Не удалось получить изображение');
                    resolve(null);
                    return;
                }

                // обновим превью на главной
                const url = URL.createObjectURL(blob);
                previewImg.src = url;

                // сохраним URL для модалки (покажем по кнопке)
                if (lastPhotoURL) URL.revokeObjectURL(lastPhotoURL);
                lastPhotoURL = url;

                const formData = new FormData();
                formData.append('image', blob, 'scan.jpg');

                const csrf = getCsrf();
                if (!csrf) {
                    alert('CSRF-токен не найден');
                    console.error('CSRF-токен отсутствует в <meta>');
                    resolve(null);
                    return;
                }

                // новый эндпоинт: только распознаёт (без записи)
                fetch('/index.php?r=scan/recognize', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrf },
                    body: formData,
                    credentials: 'include'
                })
                    .then(async r => {
                        if (r.status === 429) {
                            throw new Error('Превышен лимит OCR-запросов. Подождите минуту и попробуйте снова.');
                        }
                        const ct = r.headers.get('content-type') || '';
                        if (!ct.includes('application/json')) {
                            const text = await r.text();
                            console.error('Ожидали JSON, получили:', text);
                            throw new Error('Сервер вернул не JSON. См. консоль.');
                        }
                        return r.json();
                    })
                    .then(res => {
                        if (!res.success) {
                            throw new Error(res.error || 'Не удалось распознать сумму');
                        }

                        // Заполняем модалку
                        mAmountEl.value = res.recognized_amount;
                        mQtyEl.value = 1;
                        mNoteEl.value = '';
                        mPhotoWrap.style.display = 'none';
                        lastParsedText = res.parsed_text || '';

                        // Открываем модалку
                        if (bootstrapModal) bootstrapModal.show();
                        resolve(true);
                    })
                    .catch(err => {
                        alert(err.message);
                        console.error('recognize error:', err);
                        resolve(false);
                    });
            }, 'image/jpeg', 0.9);
        });

    } finally {
        scanBusy = false;
        captureBtn.disabled = false;
        if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
        if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сфоткать';
        else captureBtn.textContent = '📸 Сфоткать';
    }
}

// ===== Модалка: кнопки и логика =====
if (mQtyMinusEl && mQtyPlusEl && mQtyEl) {
    mQtyMinusEl.onclick = () => {
        let v = parseFloat(mQtyEl.value || '1');
        v = Math.max(0, v - 1);
        mQtyEl.value = (v % 1 === 0) ? v.toFixed(0) : v.toFixed(3);
    };
    mQtyPlusEl.onclick = () => {
        let v = parseFloat(mQtyEl.value || '1');
        v = v + 1;
        mQtyEl.value = v.toFixed(0);
    };
}

if (mShowPhotoBtn && mPhotoWrap && mPhotoImg) {
    mShowPhotoBtn.onclick = (e) => {
        e.preventDefault();
        if (mPhotoWrap.style.display === 'none') {
            mPhotoWrap.style.display = 'block';
            mPhotoImg.src = lastPhotoURL || '';
            mShowPhotoBtn.textContent = 'Скрыть фото';
        } else {
            mPhotoWrap.style.display = 'none';
            mShowPhotoBtn.textContent = 'Показать фото';
        }
    };
}

if (mRetakeBtn) {
    mRetakeBtn.onclick = () => {
        // Закрыть модалку и остаться в режиме камеры (ничего не сохраняем)
        if (bootstrapModal) bootstrapModal.hide();
    };
}

if (mSaveBtn) {
    mSaveBtn.onclick = async () => {
        const csrf = getCsrf();
        const fd = new FormData();
        fd.append('amount', mAmountEl.value);
        fd.append('qty', mQtyEl.value);
        fd.append('note', mNoteEl.value);
        fd.append('parsed_text', lastParsedText);

        try {
            const r = await fetch('/index.php?r=scan/store', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: fd,
                credentials: 'include',
            });
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                const text = await r.text();
                console.error('Ожидали JSON, получили:', text);
                throw new Error('Сервер вернул не JSON. См. консоль.');
            }
            const res = await r.json();
            if (!res.success) throw new Error(res.error || 'Ошибка сохранения');

            // Обновляем UI без перезагрузки
            if (res.entry) addEntryToTop(res.entry);
            if (typeof res.total !== 'undefined') updateTotal(res.total);

            if (bootstrapModal) bootstrapModal.hide();
        } catch (e) {
            alert(e.message);
            console.error('store error:', e);
        }
    };
}

// ===== Главная: автосохранение и +/- для qty =====
function bindEntryRow(container) {
    const form = container.querySelector('form.entry-form');
    if (!form) return;

    const id       = form.dataset.id;
    const amountEl = form.querySelector('input[name="amount"]');
    const qtyEl    = form.querySelector('input[name="qty"]');
    const delBtn   = form.querySelector('.delete-entry');

    // Обернём qty в input-group с +/- если ещё нет
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

        qtyEl.classList.add('form-control', 'text-center');

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
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: fd,
                credentials: 'include',
            });
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) return;
            const res = await r.json();
            if (res && res.success && typeof res.total !== 'undefined') {
                updateTotal(res.total);
            }
        } catch (e) {
            console.error('autosave error', e);
        }
    };
    const debouncedSave = debounce(doSave, 400);

    amountEl.addEventListener('input', debouncedSave);
    qtyEl.addEventListener('input', debouncedSave);

    minusBtn.addEventListener('click', () => {
        let v = parseFloat(qtyEl.value || '1');
        v = Math.max(0, v - 1);
        qtyEl.value = (v % 1 === 0) ? v.toFixed(0) : v.toFixed(3);
        debouncedSave();
    });

    plusBtn.addEventListener('click', () => {
        let v = parseFloat(qtyEl.value || '1');
        v = v + 1;
        qtyEl.value = v.toFixed(0);
        debouncedSave();
    });

    // Удаление
    if (delBtn) {
        delBtn.onclick = async () => {
            if (!confirm('Удалить запись?')) return;
            try {
                const r = await fetch(`index.php?r=scan/delete&id=${id}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrf },
                    credentials: 'include',
                });
                const res = await r.json();
                if (res.success) {
                    container.remove();
                    if (typeof res.total !== 'undefined') updateTotal(res.total);
                } else {
                    alert(res.error || 'Не удалось удалить');
                }
            } catch (e) {
                alert('Ошибка удаления: ' + e.message);
            }
        };
    }

    // скрыть старую кнопку ручного сохранения, если вдруг есть
    const saveBtn = form.querySelector('.save-entry');
    if (saveBtn) saveBtn.classList.add('d-none');
}


function addEntryToTop(entry) {
    const listWrap = document.querySelector('.mt-3.text-start');
    if (!listWrap) return;

    const div = document.createElement('div');
    div.className = 'border p-2 mb-2';
    div.innerHTML = `
    <form class="entry-form" data-id="${entry.id}">
      Сумма:
      <input type="number" step="0.01" name="amount" value="${entry.amount}" class="form-control mb-1">

      <input type="hidden" name="category" value="${entry.category ?? ''}">

      Кол-во:
      <input type="number" step="0.001" name="qty" value="${entry.qty}" class="form-control mb-1">

      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-danger delete-entry" type="button">🗑 Удалить</button>
        <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
      </div>
    </form>
  `;
    listWrap.prepend(div);
    bindEntryRow(div);
}


function updateTotal(total) {
    const el = document.querySelector('.mt-3 h5 strong');
    if (el) el.textContent = Number(total).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}


// ===== Инициализация для уже существующих записей =====
document.querySelectorAll('.entry-form').forEach(f => bindEntryRow(f.closest('.border')));

// ===== События =====
startBtn.onclick = async () => {
    wrap.style.display = 'block';
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Доступ к камере не поддерживается в этом браузере');
            return;
        }
        await initCamera();
    } catch (e) {
        console.error('initCamera error:', e);
        alert('Не удалось открыть камеру: ' + (e?.message || e));
    }
};

captureBtn.onclick = captureAndRecognize;
