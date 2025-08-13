// ===== DOM =====
const startBtn = document.getElementById('start-scan');
const wrap = document.getElementById('camera-wrapper');
const video = document.getElementById('camera');
const captureBtn = document.getElementById('capture');
const previewImg = document.getElementById('preview-image'); // показываем ТОЛЬКО при ошибке
const manualBtn = document.getElementById('manual-add');

// элементы внутри кнопки "Сфоткать" для спиннера
const btnTextEl = captureBtn.querySelector('.btn-text') || captureBtn;
const btnSpinnerEl = captureBtn.querySelector('.spinner');

// ===== Модалка предпросмотра =====
const scanModalEl = document.getElementById('scanModal');
const mAmountEl = document.getElementById('m-amount');
const mQtyEl = document.getElementById('m-qty');
const mQtyMinusEl = document.getElementById('m-qty-minus');
const mQtyPlusEl = document.getElementById('m-qty-plus');
const mNoteEl = document.getElementById('m-note');
const mShowPhotoBtn = document.getElementById('m-show-photo');
const mPhotoWrap = document.getElementById('m-photo-wrap');
const mPhotoImg = document.getElementById('m-photo');
const mRetakeBtn = document.getElementById('m-retake');
const mSaveBtn = document.getElementById('m-save');

let bootstrapModal = scanModalEl ? new bootstrap.Modal(scanModalEl) : null;

// ===== Состояние =====
let currentStream = null;
let scanBusy = false;
let lastPhotoURL = null;
let lastParsedText = '';
let wasSaved = false; // чтобы по закрытию модалки знать, скрывать ли камеру
// === Тумблер камеры + кнопка ручного ввода ===
let cameraActive = false;
const startScanBtn = document.getElementById('start-scan');
const manualAddBtn = document.getElementById('manual-add');

// Функция запуска камеры
function startCamera() {
    const videoEl = document.getElementById('video');
    if (!videoEl) return;

    navigator.mediaDevices.getUserMedia({video: true}).then(stream => {
        window.currentStream = stream;
        videoEl.srcObject = stream;
        videoEl.style.display = 'block';
    }).catch(err => {
        console.error('Ошибка запуска камеры', err);
    });
}

// Функция остановки камеры
function stopCamera() {
    if (window.currentStream) {
        window.currentStream.getTracks().forEach(track => track.stop());
        window.currentStream = null;
    }
    const videoEl = document.getElementById('video');
    if (videoEl) videoEl.style.display = 'none';
}

// Переключатель кнопки камеры
startBtn.onclick = async () => {
    cameraActive = !!currentStream;
    if (!cameraActive) {
        // открыть камеру
        wrap.style.display = 'block';
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Доступ к камере не поддерживается в этом браузере');
                return;
            }
            await initCamera(); // используем существующую функцию
            cameraActive = true;
            startBtn.textContent = '✖ Закрыть камеру';
            manualBtn?.classList.add('d-none'); // скрыть "Ввести вручную"

        } catch (e) {
            alert('Не удалось открыть камеру: ' + (e?.message || e));
            wrap.style.display = 'none';
            cameraActive = false;
            startBtn.textContent = '📷 Открыть камеру';
            manualBtn?.classList.remove('d-none'); // показать "Ввести вручную" обратно
        }
    } else {
        // закрыть камеру
        await stopStream();             // гасим стрим
        wrap.style.display = 'none';    // прячем блок
        cameraActive = false;
        startBtn.textContent = '📷 Открыть камеру';
        manualBtn?.classList.remove('d-none'); // показать обратно при фейле
    }
};

// Ручной ввод (без камеры)
manualBtn.onclick = async () => {
    // если камера открыта — закрываем
    if (cameraActive) {
        await stopStream();
        wrap.style.display = 'none';
        cameraActive = false;
        startBtn.textContent = '📷 Открыть камеру';
    }
    // открываем модалку с пустыми полями
    mAmountEl.value = fmt2(0);
    mQtyEl.value = 1;
    mNoteEl.value = '';
    lastParsedText = '';
    mPhotoWrap.style.display = 'none';
    if (mShowPhotoBtn) mShowPhotoBtn.style.display = 'none';
    resetPhotoPreview();
    bootstrapModal?.show();
};

// ===== Утилсы =====
const getCsrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const debounce = (fn, ms) => {
    let t;
    return (...a) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...a), ms);
    }
};
const fmt2 = (x) => Number(x || 0).toFixed(2);

//Выводим комментарий если он есть
function renderNote(container, note) {
    // 1) находим готовый слот или создаём перед блоком кнопок
    let slot = container.querySelector('.entry-note-wrap');
    if (!slot) {
        slot = document.createElement('div');
        slot.className = 'entry-note-wrap';
        slot.style.marginTop = '6px';
        const btns = container.querySelector('.d-flex.gap-2.mt-2'); // блок кнопок
        container.insertBefore(slot, btns ?? null); // вставляем ПЕРЕД кнопками
    }

    // 2) чистим слот и показываем/скрываем
    slot.innerHTML = '';
    if (!note) { slot.style.display = 'none'; return; }
    slot.style.display = '';

    // 3) сам контент заметки + "Ещё/Свернуть"
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


// ===== Камера =====
async function stopStream() {
    if (currentStream) {
        currentStream.getTracks().forEach(t => t.stop());
        currentStream = null;
    }
}

async function getStream(c) {
    return await navigator.mediaDevices.getUserMedia(c);
}

async function initCamera() {
    await stopStream();
    const primary = {video: {facingMode: {ideal: 'environment'}}, audio: false};
    try {
        currentStream = await getStream(primary);
    } catch {
        currentStream = await getStream({video: true, audio: false});
    }
    video.setAttribute('playsinline', 'true');
    video.srcObject = currentStream;
    await new Promise(res => {
        const h = () => {
            video.removeEventListener('loadedmetadata', h);
            res();
        };
        if (video.readyState >= 1) res(); else video.addEventListener('loadedmetadata', h);
    });
    try {
        await video.play();
    } catch {
    }
}

// Закрытие модалки: если запись сохранена — прячем камеру и останавливаем стрим
scanModalEl?.addEventListener('hidden.bs.modal', async () => {
    if (mShowPhotoBtn) mShowPhotoBtn.style.display = '';
    resetPhotoPreview();
    if (wasSaved) {
        wrap.style.display = 'none';
        await stopStream();
        cameraActive = false;
        wasSaved = false;

        startBtn.textContent = '📷 Открыть камеру';
        manualBtn?.classList.remove('d-none');
    }
});

// ===== Снимок + OCR =====
async function captureAndRecognize() {
    if (scanBusy) return;
    scanBusy = true;
    captureBtn.disabled = true;
    if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
    if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
    else captureBtn.textContent = 'Сканируем…';

    try {
        if (!video.videoWidth || !video.videoHeight) {
            alert('Камера ещё не готова');
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // простая бинаризация
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = img.data;
        for (let i = 0; i < data.length; i += 4) {
            const avg = (data[i] + data[i + 1] + data[i + 2]) / 3;
            const bw = avg > 128 ? 255 : 0;
            data[i] = data[i + 1] = data[i + 2] = bw;
        }
        ctx.putImageData(img, 0, 0);

        await new Promise((resolve) => {
            canvas.toBlob((blob) => {
                try {
                    if (!blob) {
                        alert('Не удалось получить изображение');
                        return resolve(false);
                    }

                    // создаём blob-url, но НЕ показываем его на странице (покажем по кнопке/при ошибке)
                    if (lastPhotoURL) URL.revokeObjectURL(lastPhotoURL);
                    lastPhotoURL = URL.createObjectURL(blob);

                    const formData = new FormData();
                    formData.append('image', blob, 'scan.jpg');

                    const csrf = getCsrf();
                    if (!csrf) {
                        alert('CSRF-токен не найден');
                        return resolve(false);
                    }

                    fetch('/index.php?r=scan/recognize', {
                        method: 'POST', headers: {'X-CSRF-Token': csrf}, body: formData, credentials: 'include'
                    })
                        .then(async r => {
                            if (r.status === 429) throw new Error('Превышен лимит OCR-запросов. Подождите минуту и попробуйте снова.');
                            const ct = r.headers.get('content-type') || '';
                            if (!ct.includes('application/json')) {
                                throw new Error('Сервер вернул не JSON.');
                            }
                            return r.json();
                        })
                        .then(res => {
                            if (!res.success) {
                                // если именно цена не распознана — покажем фото на странице
                                const msg = (res.error || '').toLowerCase();
                                if (previewImg && (msg.includes('не удалось извлечь цену') || msg.includes('цена не распознана') || res.reason === 'no_amount')) {
                                    previewImg.src = lastPhotoURL;
                                }
                                throw new Error(res.error || 'Не удалось распознать цену');
                            }
                            if (mShowPhotoBtn) mShowPhotoBtn.style.display = '';
                            // заполняем модалку
                            mAmountEl.value = fmt2(res.recognized_amount);
                            mQtyEl.value = 1;
                            mNoteEl.value = '';
                            mPhotoWrap.style.display = 'none';
                            lastParsedText = res.parsed_text || '';
                            resetPhotoPreview();
                            bootstrapModal?.show();
                            resolve(true);
                        })
                        .catch(err => {
                            alert(err.message);
                            resolve(false);
                        });

                } catch (e) {
                    resolve(false);
                }
            }, 'image/jpeg', 0.9);
        });

    } finally {
        scanBusy = false;
        captureBtn.disabled = false;
        if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
        if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сканировать';
        else captureBtn.textContent = '📸 Сканировать';
    }
}

// ===== Модалка: кнопки =====
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
if (mAmountEl) {
    // чтобы всегда было вида 12.00
    mAmountEl.addEventListener('blur', () => {
        mAmountEl.value = fmt2(mAmountEl.value);
    });
}

if (mShowPhotoBtn && mPhotoWrap && mPhotoImg) {
    mShowPhotoBtn.onclick = (e) => {
        e.preventDefault();
        const isHidden = mPhotoWrap.style.display !== 'block';
        if (isHidden) {
            mPhotoWrap.style.display = 'block';
            mPhotoImg.src = lastPhotoURL || '';
            mShowPhotoBtn.textContent = 'Скрыть скан';
        } else {
            mPhotoWrap.style.display = 'none';
            mShowPhotoBtn.textContent = 'Показать скан';
             mPhotoImg.src = '';
        }
    };
}

if (mRetakeBtn) {
    mRetakeBtn.onclick = () => {
        bootstrapModal?.hide(); /* камера остаётся открытой */
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
                method: 'POST', headers: {'X-CSRF-Token': csrf}, body: fd, credentials: 'include'
            });
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) throw new Error('Сервер вернул не JSON.');
            const res = await r.json();
            if (!res.success) throw new Error(res.error || 'Ошибка сохранения');

            if (res.entry) addEntryToTop(res.entry);
            if (typeof res.total !== 'undefined') updateTotal(res.total);

            wasSaved = true;             // флаг: после закрытия модалки скрыть камеру
            bootstrapModal?.hide();

            // очистим последний blob-url (не обяз., но чтобы не копить)
            if (lastPhotoURL) {
                URL.revokeObjectURL(lastPhotoURL);
                lastPhotoURL = null;
            }

        } catch (e) {
            alert(e.message);
        }
    };
}

// ===== Главная: автосохранение и +/- для qty =====
function bindEntryRow(container) {
    const form = container.querySelector('form.entry-form');
    if (!form) return;

// Показ комментария (если сервер отдал note через hidden)
    const noteInput = form.querySelector('input[name="note"]');
    const noteVal = noteInput ? (noteInput.value || '').trim() : '';
    if (noteVal) renderNote(container, noteVal);

    const id = form.dataset.id;
    const amountEl = form.querySelector('input[name="amount"]');
    const qtyEl = form.querySelector('input[name="qty"]');
    const delBtn = form.querySelector('.delete-entry');

    // привести цену к 2 знакам на загрузке
    if (amountEl) amountEl.value = fmt2(amountEl.value);
    amountEl?.addEventListener('blur', () => {
        amountEl.value = fmt2(amountEl.value);
    });

    // Вставим +/- для qty если нет
    let minusBtn = form.querySelector('.qty-minus');
    let plusBtn = form.querySelector('.qty-plus');
    if (!minusBtn || !plusBtn) {
        const parent = qtyEl.parentElement;
        const group = document.createElement('div');
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
                method: 'POST', headers: {'X-CSRF-Token': csrf}, body: fd, credentials: 'include'
            });
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) return;
            const res = await r.json();
            if (res?.success && typeof res.total !== 'undefined') updateTotal(res.total);
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
                    method: 'POST', headers: {'X-CSRF-Token': csrf}, credentials: 'include'
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

    // скрываем старую кнопку ручного сохранения
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

      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-danger delete-entry" type="button">🗑 Удалить</button>
        <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
      </div>
    </form>
  `;
    listWrap.prepend(div);

    // Привязываем хэндлеры
    bindEntryRow(div);

    // Рендерим комментарий (если есть)
    const noteVal = (entry.note ?? '').trim();
    if (noteVal) renderNote(div, noteVal);
}


function updateTotal(total) {
    const el = document.querySelector('.mt-3 h5 strong');
    if (el) el.textContent = Number(total).toLocaleString('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ===== Инициализация =====
document.querySelectorAll('.entry-form').forEach(f => bindEntryRow(f.closest('.border')));

captureBtn.onclick = captureAndRecognize;

function resetPhotoPreview() {
    if (mPhotoWrap) mPhotoWrap.style.display = 'none';
    if (mShowPhotoBtn) mShowPhotoBtn.textContent = 'Показать скан';
    if (mPhotoImg) mPhotoImg.src = ''; // не держим старый кадр
}