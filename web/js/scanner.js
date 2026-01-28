// scanner.js
(function () {
    const { getCsrf, fmt2, resetPhotoPreview } = window.Utils;

    let zoomActive = false;
    let zoomPoint = null;
    let zoomTimer = null;

    const ZOOM_FACTOR = 2;
    const ZOOM_HOLD_MS = 250;


    // ===== DOM =====
    const startBtn   = document.getElementById('start-scan');
    const wrap       = document.getElementById('camera-wrapper');
    const video      = document.getElementById('camera');
    const captureBtn = document.getElementById('capture');
    const previewImg = document.getElementById('preview-image');
    const manualBtn  = document.getElementById('manual-add');

    // [NEW] Кнопка отмены рядом со "Сканировать" (добавь в вёрстку id="ocr-cancel-btn")
    const cancelBtn  = document.getElementById('ocr-cancel-btn');

    const btnTextEl    = captureBtn?.querySelector('.btn-text') || captureBtn;
    const btnSpinnerEl = captureBtn?.querySelector('.spinner');

    // ===== Модалка =====
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

    let bootstrapModal = scanModalEl ? new bootstrap.Modal(scanModalEl) : null;

    const shopLimitEl = document.getElementById('shop-limit');
    let   metaLimit   = null;

    // ===== Состояние =====
    let currentStream = null;
    let scanBusy = false;
    let lastPhotoURL = null;
    let lastParsedText = '';
    let wasSaved = false;
    let cameraActive = false;
    const scanRoot  = document.getElementById('scan-root');
    let   metaStore    = scanRoot?.dataset.store || '';
    let   metaCategory = scanRoot?.dataset.category || '';
    console.log('scan meta:', { metaStore, metaCategory });

    const shopModalEl  = document.getElementById('shopModal');
    const shopStoreEl  = document.getElementById('shop-store');
    const shopCatEl    = document.getElementById('shop-category');
    const shopBeginBtn = document.getElementById('shop-begin');
    let   shopModal    = (window.bootstrap && shopModalEl) ? new bootstrap.Modal(shopModalEl) : null;
    const totalWrap = document.getElementById('total-wrap');
    const totalLabelEl = document.getElementById('scan-total-label');
    const mAliceSelect  = document.getElementById('m-alice-item');
    const ensureTotalsMarkup = () => {
        const wrapEl = document.getElementById('total-wrap');
        if (!wrapEl) return;

        const limitAttr = wrapEl.dataset.limit ?? '';
        const limitValue = parseFloat(limitAttr);
        const hasLimit = limitAttr !== '' && !Number.isNaN(limitValue);

        if (hasLimit) {
            if (!document.getElementById('scan-remaining')) {
                wrapEl.innerHTML = `
                    <div class="total-total">
                        <span class="me-1"><strong id="scan-remaining-label">До лимита:</strong></span>
                        <strong id="scan-remaining"></strong>
                    </div>
                    <div class="text-muted small mt-1" id="scan-secondary">
                        <span id="scan-sum-label">Итого:</span>
                        <span id="scan-sum"></span>
                        <span class="mx-1">/</span>
                        <span id="scan-limit-label">Лимит:</span>
                        <span id="scan-limit"></span>
                    </div>
                `;
                if (!document.getElementById('scan-total')) {
                    const hiddenTotal = document.createElement('strong');
                    hiddenTotal.id = 'scan-total';
                    hiddenTotal.style.display = 'none';
                    wrapEl.appendChild(hiddenTotal);
                }
            } else {
                const secondary = document.getElementById('scan-secondary');
                if (secondary) secondary.classList.remove('d-none');
            }
        } else {
            if (!document.getElementById('scan-total')) {
                wrapEl.innerHTML = `
                    <div class="total-total">
                        <span class="me-1"><strong id="scan-total-label">Итого:</strong></span>
                        <strong id="scan-total"></strong>
                    </div>
                `;
            }
            const secondary = document.getElementById('scan-secondary');
            if (secondary) secondary.classList.add('d-none');
        }
    };
    const originalUpdateTotal = typeof window.updateTotal === 'function' ? window.updateTotal : null;

    window.updateTotal = function(total) {
        ensureTotalsMarkup();
        if (originalUpdateTotal) {
            originalUpdateTotal(total);
        }
        const wrapEl = document.getElementById('total-wrap');
        if (!wrapEl) return;
        const limitAttr = wrapEl.dataset.limit ?? '';
        const limitValue = parseFloat(limitAttr);
        if (limitAttr !== '' && !Number.isNaN(limitValue)) {
            const secondary = document.getElementById('scan-secondary');
            if (secondary) secondary.classList.remove('d-none');
        }
    };

    ensureTotalsMarkup();

    // [NEW] Управление отменой OCR
    const USE_CLIENT_BW = false;
    const MAX_W = 1600;
    const OCR_TIMEOUT_MS = 12000;
    let ocrAbortCtrl = null;
    let ocrTimer = null;
    function ocrUi(pending) {
        if (!captureBtn) return;
        if (pending) {
            captureBtn.disabled = true;
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
            if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
            else captureBtn.textContent = 'Сканируем…';
            if (cancelBtn) { cancelBtn.classList.remove('d-none'); cancelBtn.disabled = false; }
        } else {
            captureBtn.disabled = false;
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
            if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сканировать';
            else captureBtn.textContent = '📸 Сканировать';
            if (cancelBtn) cancelBtn.classList.add('d-none');
        }
    }
    function ocrCleanup() {
        if (ocrTimer) { clearTimeout(ocrTimer); ocrTimer = null; }
        ocrAbortCtrl = null;
        ocrUi(false);
    }
    cancelBtn && (cancelBtn.onclick = () => {
        if (ocrAbortCtrl) ocrAbortCtrl.abort('user-cancel');
    });

    const needPrompt = scanRoot?.dataset.needPrompt === '1';
    if (needPrompt && shopModal) {
        if (metaStore)    shopStoreEl.value = metaStore;     // префилл, если есть
        if (metaCategory) shopCatEl.value   = metaCategory;
        shopModal.show();
    }

    function updateScanTitle() {
        const h2 = document.getElementById('scan-title');
        if (!h2) return;
        const cat = scanRoot?.dataset.category || metaCategory || '';
        const sto = scanRoot?.dataset.store || metaStore || '';
        if (cat || sto) {
            h2.textContent = `Покупаем: ${cat || '—'}. В магазине: ${sto || '—'}`;
        } else {
            h2.textContent = 'Тратометр';
        }
    }
    updateScanTitle();

    // ===== [NEW] Выделение суммы по клику (без автофокуса при открытии)
    let selectOnFocusNext = false;
    mAmountEl?.addEventListener('pointerdown', () => { selectOnFocusNext = true; });
    mAmountEl?.addEventListener('mousedown',   () => { selectOnFocusNext = true; });
    mAmountEl?.addEventListener('touchstart',  () => { selectOnFocusNext = true; }, { passive: true });
    mAmountEl?.addEventListener('focus', (e) => {
        if (selectOnFocusNext) { e.target.select(); selectOnFocusNext = false; }
    });

    // Переключатель камеры
    if (startBtn) {
        startBtn.onclick = async () => {
            cameraActive = !!currentStream;
            if (!cameraActive) {
                wrap?.setAttribute('style','display:block');
                try {
                    if (!navigator.mediaDevices?.getUserMedia) {
                        alert('Доступ к камере не поддерживается в этом браузере'); return;
                    }
                    await initCamera();
                    cameraActive = true;
                    startBtn.textContent = '✖ Закрыть камеру';
                    manualBtn?.classList.add('d-none');

                    // При запуске камеры снова показываем кнопки
                    document.getElementById('m-show-photo').style.display = '';
                    document.getElementById('m-retake').style.display = '';

                } catch (e) {
                    alert('Не удалось открыть камеру: ' + (e?.message || e));
                    if (wrap) wrap.style.display = 'none';
                    cameraActive = false;
                    startBtn.textContent = '📷 Открыть камеру';
                    manualBtn?.classList.remove('d-none');
                }
            } else {
                await stopStream();
                if (wrap) wrap.style.display = 'none';
                cameraActive = false;
                startBtn.textContent = '📷 Открыть камеру';
                manualBtn?.classList.remove('d-none');
            }
        };
    }

    // Ручной ввод
    if (manualBtn) {
        manualBtn.onclick = async () => {
            if (cameraActive) {
                await stopStream();
                if (wrap) wrap.style.display = 'none';
                cameraActive = false;
                if (startBtn) startBtn.textContent = '📷 Открыть камеру';
            }

            mAmountEl.value = fmt2(0);
            mQtyEl.value = 1;
            mNoteEl.value = '';
            lastParsedText = '';

            if (mAliceSelect) {
                mAliceSelect.value = '';
            }

            // Скрыть кнопки "Скан" и "Переснять"
            document.getElementById('m-show-photo').style.display = 'none';
            document.getElementById('m-retake').style.display = 'none';

            resetPhotoPreview(mPhotoWrap, mShowPhotoBtn, mPhotoImg);
            bootstrapModal?.show();
        };
    }

    // ===== Камера =====
    async function stopStream() {
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
    }
    async function getStream(c) { return await navigator.mediaDevices.getUserMedia(c); }


    if (video) {
        video.addEventListener('pointerdown', (e) => {
            if (scanBusy) return;

            const rect = video.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            zoomTimer = setTimeout(() => {
                zoomActive = true;
                zoomPoint = { x, y };

                video.classList.add('zooming');
                video.style.transformOrigin = `${x}px ${y}px`;
            }, ZOOM_HOLD_MS);
        });

        video.addEventListener('pointermove', (e) => {
            if (!zoomActive) return;

            const rect = video.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            zoomPoint = { x, y };
            video.style.transformOrigin = `${x}px ${y}px`;
        });

        const endPointer = async () => {
            clearTimeout(zoomTimer);

            // 🔴 ВАЖНО: если зум НЕ был активирован — ничего не делаем
            if (!zoomActive) {
                zoomActive = false;
                return;
            }

            // ✔️ зум был → значит запускаем скан ПОСЛЕ отпускания
            zoomActive = false;
            video.classList.remove('zooming');
            video.style.transformOrigin = '';

            await captureAndRecognize(true);
        };

        video.addEventListener('pointerup', endPointer);
        video.addEventListener('pointercancel', endPointer);
    }


    async function initCamera() {
        await stopStream();

        const primary = { video: { facingMode: { ideal: 'environment' } }, audio: false };

        try {
            currentStream = await getStream(primary);
        } catch {
            currentStream = await getStream({ video: true, audio: false });
        }

        video.setAttribute('playsinline', 'true');
        video.srcObject = currentStream;

        await new Promise(res => {
            if (video.readyState >= 1) return res();
            video.addEventListener('loadedmetadata', res, { once: true });
        });

        try {
            await video.play();
        } catch {}
    }


    // Скан + OCR (скрытое превью по умолчанию, видим то, что отправили)
    async function captureAndRecognize(fromZoom = false) {
        if (scanBusy) return;
        scanBusy = true;

        if (captureBtn) captureBtn.disabled = true;
        if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
        if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
        else if (captureBtn) captureBtn.textContent = 'Сканируем…';

        // UI отмены OCR
        ocrUi(true);

        try {
            if (!video.videoWidth || !video.videoHeight) {
                alert('Камера ещё не готова');
                return;
            }

            const vw = video.videoWidth;
            const vh = video.videoHeight;

            // ===== вычисление области захвата =====
            let sx = 0, sy = 0, sw = vw, sh = vh;

            if (fromZoom && zoomPoint) {
                const scaleX = vw / video.clientWidth;
                const scaleY = vh / video.clientHeight;

                const cx = zoomPoint.x * scaleX;
                const cy = zoomPoint.y * scaleY;

                sw = vw / ZOOM_FACTOR;
                sh = vh / ZOOM_FACTOR;

                sx = Math.max(0, cx - sw / 2);
                sy = Math.max(0, cy - sh / 2);

                if (sx + sw > vw) sx = vw - sw;
                if (sy + sh > vh) sy = vh - sh;
            }

            // ===== canvas =====
            const canvas = document.createElement('canvas');

            const scale = Math.min(1, MAX_W / Math.max(sw, sh));
            canvas.width  = Math.round(sw * scale);
            canvas.height = Math.round(sh * scale);

            const ctx = canvas.getContext('2d');
            ctx.drawImage(
                video,
                sx, sy, sw, sh,
                0, 0, canvas.width, canvas.height
            );

            // ===== опциональная Ч/Б-предобработка =====
            if (USE_CLIENT_BW) {
                const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const data = img.data;
                for (let i = 0; i < data.length; i += 4) {
                    const avg = (data[i] + data[i + 1] + data[i + 2]) / 3;
                    const bw = avg > 128 ? 255 : 0;
                    data[i] = data[i + 1] = data[i + 2] = bw;
                }
                ctx.putImageData(img, 0, 0);
            }

            await new Promise((resolve) => {
                canvas.toBlob((blob) => {
                    try {
                        if (!blob) {
                            alert('Не удалось получить изображение');
                            return resolve(false);
                        }

                        // сохраняем именно тот кадр, что ушёл на OCR
                        if (lastPhotoURL) URL.revokeObjectURL(lastPhotoURL);
                        lastPhotoURL = URL.createObjectURL(blob);

                        const formData = new FormData();
                        formData.append('image', blob, 'scan.jpg');

                        const csrf = getCsrf();
                        if (!csrf) {
                            alert('CSRF-токен не найден');
                            return resolve(false);
                        }

                        ocrAbortCtrl = new AbortController();
                        ocrTimer = setTimeout(() => {
                            try { ocrAbortCtrl.abort('timeout'); } catch (_) {}
                        }, OCR_TIMEOUT_MS);

                        fetch('/index.php?r=scan/recognize', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': csrf },
                            body: formData,
                            credentials: 'include',
                            signal: ocrAbortCtrl.signal
                        })
                            .then(async r => {
                                if (r.status === 429) {
                                    throw new Error('Превышен лимит OCR-запросов. Подождите минуту и попробуйте снова.');
                                }
                                const ct = r.headers.get('content-type') || '';
                                if (!ct.includes('application/json')) {
                                    throw new Error('Сервер вернул не JSON.');
                                }
                                return r.json();
                            })
                            .then(res => {
                                if (!res.success) {
                                    const msg = (res.error || '').toLowerCase();
                                    if (
                                        previewImg &&
                                        lastPhotoURL &&
                                        (
                                            msg.includes('не удалось извлечь цену') ||
                                            msg.includes('цена не распознана') ||
                                            res.reason === 'no_amount'
                                        )
                                    ) {
                                        previewImg.src = lastPhotoURL;
                                    }
                                    throw new Error(res.error || 'Не удалось распознать цену');
                                }

                                if (mShowPhotoBtn) mShowPhotoBtn.style.display = '';

                                mAmountEl.value = fmt2(res.recognized_amount);
                                mQtyEl.value = 1;
                                mNoteEl.value = '';
                                lastParsedText = res.parsed_text || '';

                                if (mAliceSelect) mAliceSelect.value = '';

                                resetPhotoPreview(mPhotoWrap, mShowPhotoBtn, mPhotoImg);
                                bootstrapModal?.show();

                                resolve(true);
                            })
                            .catch(err => {
                                if (err?.name === 'AbortError') {
                                    const msg = String(err?.message || '');
                                    if (msg.includes('timeout')) alert('OCR: истек таймаут. Попробуйте ещё раз.');
                                    else alert('Отменено.');
                                } else {
                                    alert(err.message || 'Ошибка OCR-запроса');
                                }
                                resolve(false);
                            })
                            .finally(() => {
                                ocrCleanup();
                            });
                    } catch (e) {
                        ocrCleanup();
                        resolve(false);
                    }
                }, 'image/jpeg', 0.9);
            });

        } finally {
            scanBusy = false;
            if (captureBtn) captureBtn.disabled = false;
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
            if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сканировать';
            else if (captureBtn) captureBtn.textContent = '📸 Сканировать';
            ocrCleanup();
        }
    }


    // Кнопки модалки
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
        mAmountEl.addEventListener('blur', () => { mAmountEl.value = fmt2(mAmountEl.value); });
    }

    if (mShowPhotoBtn && mPhotoWrap && mPhotoImg) {
        const SHOW = 'Показать скан';
        const HIDE = 'Скрыть скан';

        // Функция синхронизации UI с фактом видимости блока
        const sync = (visible) => {
            if (visible) {
                mPhotoWrap.style.display = 'block';
                mPhotoImg.src = lastPhotoURL || '';
                mShowPhotoBtn.textContent = HIDE;
            } else {
                mPhotoWrap.style.display = 'none';
                mPhotoImg.src = '';
                mShowPhotoBtn.textContent = SHOW;
            }
        };

        // Начальное состояние — по реальному рендеру
        const initiallyVisible = getComputedStyle(mPhotoWrap).display === 'block';
        sync(initiallyVisible);

        mShowPhotoBtn.onclick = (e) => {
            e.preventDefault();
            const isVisible = getComputedStyle(mPhotoWrap).display === 'block';
            sync(!isVisible);
        };
    }

    if (mRetakeBtn) {
        mRetakeBtn.onclick = () => { bootstrapModal?.hide(); };
    }
    if (mSaveBtn) {
        mSaveBtn.onclick = async () => {
            const csrf = getCsrf();
            const fd = new FormData();
            fd.append('amount', mAmountEl.value);
            fd.append('qty', mQtyEl.value);
            fd.append('note', mNoteEl.value);
            fd.append('parsed_text', lastParsedText);
            fd.append('store',    metaStore);
            fd.append('category', metaCategory);

            const selectedAliceId = mAliceSelect ? (mAliceSelect.value || '') : '';
            if (selectedAliceId !== '') {
                fd.append('alice_item_id', selectedAliceId);
            }

            try {
                const r = await fetch('/index.php?r=scan/store', {
                    method:'POST', headers:{'X-CSRF-Token':csrf}, body:fd, credentials:'include'
                });
                const ct = r.headers.get('content-type')||'';
                if (!ct.includes('application/json')) throw new Error('Сервер вернул не JSON.');
                const res = await r.json();
                if (!res.success) throw new Error(res.error || 'Ошибка сохранения');

                if (res.entry && typeof window.addEntryToTop === 'function') window.addEntryToTop(res.entry);
                if (typeof res.total !== 'undefined' && typeof window.updateTotal === 'function') window.updateTotal(res.total);

                // убираем использованный пункт из выпадашки
                if (selectedAliceId && mAliceSelect) {
                    const opt = mAliceSelect.querySelector('option[value="' + selectedAliceId + '"]');
                    if (opt) opt.remove();
                    mAliceSelect.value = '';
                }

                await closeCameraUI();
                wasSaved = true;
                bootstrapModal?.hide();

                if (lastPhotoURL) { URL.revokeObjectURL(lastPhotoURL); lastPhotoURL = null; }
            } catch (e) { alert(e.message); }
        };
    }


    // init
    if (captureBtn) captureBtn.onclick = captureAndRecognize;

    // --- checkShopSession: тянем лимит, обновляем data-атрибуты и подпись тотала
    async function checkShopSession() {
        try {
            const r = await fetch('/index.php?r=site/session-status', { credentials: 'include' });
            const res = await r.json();
            if (!res.ok) return;

            // элементы тотала берём локально, чтобы не плодить глобалы
            const totalWrap   = document.getElementById('total-wrap');
            const totalLabelEl= document.getElementById('scan-total-label');

            // если нет активной сессии — показываем модалку выбора
            if (res.needPrompt && shopModal) {
                if (res.store)    shopStoreEl.value = res.store;
                if (res.category) shopCatEl.value   = res.category;
                shopModal.show();
            } else {
                // обновим локальные метаданные (store/category)
                metaStore    = res.store     || metaStore;
                metaCategory = res.category  || metaCategory;

                // лимит от бэка (в рублях, число или null)
                if (typeof res.limit === 'number') {
                    metaLimit = res.limit;
                    scanRoot?.setAttribute('data-limit', String(metaLimit));
                    if (totalWrap) totalWrap.dataset.limit = String(metaLimit);
                    if (totalLabelEl) totalLabelEl.textContent = 'До лимита:';
                } else {
                    metaLimit = null;
                    scanRoot?.setAttribute('data-limit', '');
                    if (totalWrap) totalWrap.dataset.limit = '';
                    if (totalLabelEl) totalLabelEl.textContent = 'Общая сумма:';
                }

                scanRoot?.setAttribute('data-store', metaStore);
                scanRoot?.setAttribute('data-category', metaCategory);
                if (typeof window.updateTotals === 'function') window.updateTotals();
            }
        } catch (e) { /* тихо */ }
    }

    document.addEventListener('DOMContentLoaded', checkShopSession, reloadAliceSelect());

    // После закрытия модалки — обновляем заголовок на всякий случай
    shopModalEl?.addEventListener('hidden.bs.modal', () => {
        // синхронизируемся с актуальными data-атрибутами
        metaStore    = scanRoot?.dataset.store    || metaStore;
        metaCategory = scanRoot?.dataset.category || metaCategory;
        updateScanTitle();
    });

    // --- shopBeginBtn.onclick: создаём сессию с опциональным лимитом и сразу синхронизируем UI
    shopBeginBtn && (shopBeginBtn.onclick = async () => {
        const store = (shopStoreEl.value   || '').trim();
        const cat   = (shopCatEl.value     || '').trim();
        const lim   = (shopLimitEl?.value  || '').trim(); // может быть пусто

        if (!store) { shopStoreEl.focus(); return; }

        const csrf = getCsrf();
        const fd   = new FormData();
        fd.append('store', store);
        fd.append('category', cat);
        if (lim !== '') fd.append('limit', lim); // передаём лимит, только если введён

        try {
            const r = await fetch('/index.php?r=site/begin-ajax', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: fd,
                credentials: 'include'
            });
            const res = await r.json();
            if (!res.ok) throw new Error(res.error || 'Не удалось начать сессию');

            // обновляем метаданные
            metaStore    = res.store || store;
            metaCategory = res.category || cat;
            metaLimit    = (typeof res.limit === 'number') ? res.limit : null;

            scanRoot?.setAttribute('data-store',    metaStore);
            scanRoot?.setAttribute('data-category', metaCategory);
            scanRoot?.setAttribute('data-limit',    metaLimit !== null ? String(metaLimit) : '');

            // синхронизируем «тотал» на странице (лейбл + data-limit у контейнера)
            const totalWrap    = document.getElementById('total-wrap');
            const totalLabelEl = document.getElementById('scan-total-label');
            if (totalWrap) totalWrap.dataset.limit = metaLimit !== null ? String(metaLimit) : '';
            if (totalLabelEl) totalLabelEl.textContent = metaLimit !== null ? 'До лимита:' : 'Общая сумма:';

            // новая сессия — текущая сумма 0; сразу корректно отрисуем «Лимит»
            if (typeof window.updateTotals === 'function') window.updateTotals();

            updateScanTitle();
            shopModal?.hide();
        } catch (e) {
            alert(e.message);
        }
    });

    async function closeCameraUI() {
        // Остановить стрим
        await stopStream();
        // Спрятать обёртку камеры
        if (wrap) wrap.style.display = 'none';
        // Обновить флаги/кнопки
        cameraActive = false;
        if (startBtn) startBtn.textContent = '📷 Открыть камеру';
        manualBtn?.classList.remove('d-none');
    }
})();
