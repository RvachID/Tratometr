// scanner.js
(function () {
    const { getCsrf, fmt2, resetPhotoPreview } = window.Utils;

    // ===== DOM =====
    const startBtn   = document.getElementById('start-scan');
    const wrap       = document.getElementById('camera-wrapper');
    const video      = document.getElementById('camera');
    const captureBtn = document.getElementById('capture');
    const cancelBtn  = document.getElementById('ocr-cancel-btn'); // КНОПКА ОТМЕНЫ РЯДОМ С "Сканировать"
    const previewImg = document.getElementById('preview-image');
    const manualBtn  = document.getElementById('manual-add');

    const btnTextEl    = captureBtn?.querySelector('.btn-text') || captureBtn;
    const btnSpinnerEl = captureBtn?.querySelector('.spinner');

    // ===== Модалка =====
    const scanModalEl   = document.getElementById('scanModal');
    const mAmountEl     = document.getElementById('m-amount');
    const mQtyEl        = document.getElementById('m-qty');
    const mQtyMinusEl   = document.getElementById('m-qty-minus');
    const mQtyPlusEl    = document.getElementById('m-qty-plus');
    const mNoteEl       = document.getElementById('m-note');

    // Кнопки модалки
    const mShowPhotoBtn = document.getElementById('m-show-photo'); // ПОКАЗАТЬ/СКРЫТЬ СКАН (БЕЗ OCR)
    const mPhotoWrap    = document.getElementById('m-photo-wrap');
    const mPhotoImg     = document.getElementById('m-photo');
    const mRetakeBtn    = document.getElementById('m-retake');
    const mSaveBtn      = document.getElementById('m-save');

    let bootstrapModal = scanModalEl ? new bootstrap.Modal(scanModalEl) : null;

    // Выделение суммы только по клику/тапу (без автопоказа клавиатуры при открытии модалки)
    let selectOnFocusNext = false;
    mAmountEl?.addEventListener('pointerdown', () => { selectOnFocusNext = true; });
    mAmountEl?.addEventListener('mousedown',   () => { selectOnFocusNext = true; });
    mAmountEl?.addEventListener('touchstart',  () => { selectOnFocusNext = true; }, { passive: true });
    mAmountEl?.addEventListener('focus', (e) => {
        if (selectOnFocusNext) {
            e.target.select();
            selectOnFocusNext = false;
        }
    });

    // ===== Состояние =====
    let currentStream = null;
    let scanBusy = false;
    let lastPhotoURL = null;         // objectURL последнего снимка
    let lastParsedText = '';
    let wasSaved = false;
    let cameraActive = false;
    // снимок для модалки/повторного показа
    window.mPhotoBlob = null;

    const scanRoot  = document.getElementById('scan-root');
    let   metaStore    = scanRoot?.dataset.store || '';
    let   metaCategory = scanRoot?.dataset.category || '';
    console.log('scan meta:', { metaStore, metaCategory });

    const shopModalEl  = document.getElementById('shopModal');
    const shopStoreEl  = document.getElementById('shop-store');
    const shopCatEl    = document.getElementById('shop-category');
    const shopBeginBtn = document.getElementById('shop-begin');
    let   shopModal    = (window.bootstrap && shopModalEl) ? new bootstrap.Modal(shopModalEl) : null;

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

    // ===== Камера переключатель =====
    if (startBtn) {
        startBtn.onclick = async () => {
            cameraActive = !!currentStream;
            if (!cameraActive) {
                wrap?.setAttribute('style','display:block');
                try {
                    if (!navigator.mediaDevices?.getUserMedia) {
                        alert('Доступ к камере не поддерживается в этом браузере');
                        return;
                    }
                    await initCamera();
                    cameraActive = true;
                    startBtn.textContent = '✖ Закрыть камеру';
                    manualBtn?.classList.add('d-none');

                    // При запуске камеры снова показываем кнопки модалки
                    document.getElementById('m-show-photo')?.removeAttribute('style');
                    document.getElementById('m-retake')?.removeAttribute('style');

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
            window.mPhotoBlob = null;

            // Скрыть «Показать скан» и «Переснять» в ручном режиме
            document.getElementById('m-show-photo')?.setAttribute('style','display:none');
            document.getElementById('m-retake')?.setAttribute('style','display:none');

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
    async function initCamera() {
        await stopStream();
        const primary = { video: { facingMode: { ideal: 'environment' } }, audio: false };
        try { currentStream = await getStream(primary); }
        catch { currentStream = await getStream({ video: true, audio: false }); }
        video.setAttribute('playsinline','true');
        video.srcObject = currentStream;
        await new Promise(res => {
            const h = () => { video.removeEventListener('loadedmetadata', h); res(); };
            if (video.readyState >= 1) res(); else video.addEventListener('loadedmetadata', h);
        });
        try { await video.play(); } catch {}
    }

    // Закрытие модалки
    scanModalEl?.addEventListener('hidden.bs.modal', async () => {
        resetPhotoPreview(mPhotoWrap, mShowPhotoBtn, mPhotoImg);
        if (wasSaved) {
            if (wrap) wrap.style.display = 'none';
            await stopStream();
            cameraActive = false;
            wasSaved = false;
            if (startBtn) startBtn.textContent = '📷 Открыть камеру';
            manualBtn?.classList.remove('d-none');
        }
    });

    // ===== Управление UI "Сканировать"/"Отмена" (СТРАНИЦА)
    const OCR_TIMEOUT_MS = 12000; // 12 сек
    let ocrAbortCtrlPage = null;
    let ocrTimerPage     = null;

    function setPageOcrPending(pending) {
        if (!captureBtn) return;
        if (pending) {
            captureBtn.disabled = true;
            captureBtn.dataset._text = captureBtn.textContent;
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
            if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
            else captureBtn.textContent = 'Сканируем…';
            cancelBtn?.classList.remove('d-none');
            cancelBtn && (cancelBtn.disabled = false);
        } else {
            captureBtn.disabled = false;
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
            if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сканировать';
            else captureBtn.textContent = '📸 Сканировать';
            cancelBtn?.classList.add('d-none');
        }
    }

    // Отмена на странице
    cancelBtn && (cancelBtn.onclick = () => {
        if (ocrAbortCtrlPage) {
            ocrAbortCtrlPage.abort('user-cancel');
        }
    });

    /** fetch с AbortController и таймаутом (СТРАНИЦА) */
    async function ocrFetchPage(url, opts = {}) {
        if (!navigator.onLine) throw new Error('Нет сети. Проверьте подключение.');

        const ctrl = new AbortController();
        ocrAbortCtrlPage = ctrl;
        opts.signal = ctrl.signal;

        setPageOcrPending(true);

        ocrTimerPage = setTimeout(() => {
            try { ctrl.abort('timeout'); } catch (_) {}
        }, OCR_TIMEOUT_MS);

        try {
            const res = await fetch(url, opts);
            return res;
        } finally {
            clearTimeout(ocrTimerPage); ocrTimerPage = null;
            ocrAbortCtrlPage = null;
            setPageOcrPending(false);
        }
    }

    // ===== Скан с камеры + OCR (кнопка на странице)
    async function captureAndRecognize() {
        if (scanBusy) return;
        scanBusy = true;

        try {
            if (!video.videoWidth || !video.videoHeight) { alert('Камера ещё не готова'); return; }

            setPageOcrPending(true);

            // снимок
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            // простая бинаризация
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = img.data;
            for (let i=0;i<data.length;i+=4){
                const avg=(data[i]+data[i+1]+data[i+2])/3;
                const bw=avg>128?255:0;
                data[i]=data[i+1]=data[i+2]=bw;
            }
            ctx.putImageData(img,0,0);

            // получаем Blob и отправляем
            const blob = await new Promise((resolve) => {
                canvas.toBlob((b) => resolve(b || null), 'image/jpeg', 0.9);
            });
            if (!blob) { alert('Не удалось получить изображение'); return; }

            // сохраним снимок для показа «Показать скан»
            window.mPhotoBlob = blob;
            if (lastPhotoURL) URL.revokeObjectURL(lastPhotoURL);
            lastPhotoURL = URL.createObjectURL(blob);

            const formData = new FormData();
            formData.append('image', blob, 'scan.jpg');

            const csrf = getCsrf();
            if (!csrf) { alert('CSRF-токен не найден'); return; }

            const r = await ocrFetchPage('/index.php?r=scan/recognize', {
                method:'POST', headers:{'X-CSRF-Token':csrf}, body:formData, credentials:'include'
            });

            if (r.status === 429) throw new Error('Превышен лимит OCR-запросов. Подождите минуту и попробуйте снова.');
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) { throw new Error('Сервер вернул не JSON.'); }
            const res = await r.json();

            if (!res.success) {
                const msg = (res.error||'').toLowerCase();
                if (previewImg && (msg.includes('не удалось извлечь цену') || msg.includes('цена не распознана') || res.reason==='no_amount')) {
                    previewImg.src = lastPhotoURL;
                }
                throw new Error(res.error || 'Не удалось распознать цену');
            }

            // Подготовка модалки
            mAmountEl.value = fmt2(res.recognized_amount);
            mQtyEl.value = 1;
            mNoteEl.value = '';
            mPhotoWrap.style.display = 'none';
            lastParsedText = res.parsed_text || '';
            resetPhotoPreview(mPhotoWrap, mShowPhotoBtn, mPhotoImg);
            bootstrapModal?.show();

        } catch (e) {
            if (e?.name === 'AbortError') {
                const msg = String(e?.message || '');
                if (msg.includes('timeout')) alert('OCR: истек таймаут. Попробуйте ещё раз.');
                else alert('Отменено.');
            } else {
                alert(e?.message || 'Ошибка OCR-запроса');
            }
        } finally {
            setPageOcrPending(false);
            scanBusy = false;
        }
    }

    // Кнопки количества и формат суммы
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

    // Показать/скрыть скан в модалке (только превью)
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

    // Переснять = закрыть модалку и вернуться к камере
    if (mRetakeBtn) {
        mRetakeBtn.onclick = () => { bootstrapModal?.hide(); };
    }

    // Сохранение из модалки
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

            try {
                const r = await fetch('/index.php?r=scan/store', {
                    method:'POST', headers:{'X-CSRF-Token':csrf}, body:fd, credentials:'include'
                });
                const ct = r.headers.get('content-type')||'';
                if (!ct.includes('application/json')) throw new Error('Сервер вернул не JSON.');
                const res = await r.json();
                if (!res.success) throw new Error(res.error || 'Ошибка сохранения');

                // Если на странице есть список, обновим его
                if (res.entry && typeof window.addEntryToTop === 'function') window.addEntryToTop(res.entry);
                if (typeof res.total !== 'undefined' && typeof window.updateTotal === 'function') window.updateTotal(res.total);

                wasSaved = true;
                bootstrapModal?.hide();

                if (lastPhotoURL) { URL.revokeObjectURL(lastPhotoURL); lastPhotoURL = null; }
                window.mPhotoBlob = null;
            } catch (e) { alert(e.message); }
        };
    }

    // init: основная кнопка «Сканировать» на странице
    if (captureBtn) captureBtn.onclick = captureAndRecognize;

    // ===== Проверка серверной сессии =====
    async function checkShopSession() {
        try {
            const r = await fetch('/index.php?r=site/session-status', { credentials: 'include' });
            const res = await r.json();
            if (!res.ok) return;

            if (res.needPrompt && shopModal) {
                if (res.store)     shopStoreEl.value = res.store;
                if (res.category)  shopCatEl.value   = res.category;
                shopModal.show();
            } else {
                metaStore    = res.store     || metaStore;
                metaCategory = res.category  || metaCategory;
                scanRoot?.setAttribute('data-store', metaStore);
                scanRoot?.setAttribute('data-category', metaCategory);
            }
        } catch (e) { /* тихо */ }
    }
    document.addEventListener('DOMContentLoaded', checkShopSession);

    // После закрытия модалки выбора магазина — обновим заголовок
    shopModalEl?.addEventListener('hidden.bs.modal', () => {
        metaStore    = scanRoot?.dataset.store    || metaStore;
        metaCategory = scanRoot?.dataset.category || metaCategory;
        updateScanTitle();
    });

    // Начать серверную сессию (из модалки выбора магазина)
    shopBeginBtn && (shopBeginBtn.onclick = async () => {
        const store = (shopStoreEl.value || '').trim();
        const cat   = (shopCatEl.value || '').trim();
        if (!store) { shopStoreEl.focus(); return; }

        const csrf = getCsrf();
        const fd = new FormData();
        fd.append('store', store);
        fd.append('category', cat);

        try {
            const r = await fetch('/index.php?r=site/begin-ajax', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: fd,
                credentials: 'include'
            });
            const res = await r.json();
            if (!res.ok) throw new Error('Не удалось начать сессию');

            metaStore    = res.store || store;
            metaCategory = res.category || cat;
            scanRoot?.setAttribute('data-store', metaStore);
            scanRoot?.setAttribute('data-category', metaCategory);
            updateScanTitle();
            shopModal?.hide();
        } catch (e) {
            alert(e.message);
        }
    });

})();
