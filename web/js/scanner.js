// scanner.js
(function () {
    const { getCsrf, fmt2, resetPhotoPreview } = window.Utils;

    // ===== DOM =====
    const startBtn   = document.getElementById('start-scan');
    const wrap       = document.getElementById('camera-wrapper');
    const video      = document.getElementById('camera');
    const captureBtn = document.getElementById('capture');
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
    const mShowPhotoBtn = document.getElementById('m-show-photo');
    const mPhotoWrap    = document.getElementById('m-photo-wrap');
    const mPhotoImg     = document.getElementById('m-photo');
    const mRetakeBtn    = document.getElementById('m-retake');
    const mSaveBtn      = document.getElementById('m-save');

    let bootstrapModal = scanModalEl ? new bootstrap.Modal(scanModalEl) : null;

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

    function updateScanTitle() {
        const scanRoot  = document.getElementById('scan-root');
        const category  = scanRoot?.dataset.category || '';
        const store     = scanRoot?.dataset.store || '';

        let titleText;
        if (category || store) {
            titleText = `Покупаем: ${category || '—'}. В магазине: ${store || '—'}`;
        }

        const h2 = document.querySelector('.container.mt-3.text-center h2');
        if (h2) h2.textContent = titleText;
    }


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
            if (mShowPhotoBtn) mShowPhotoBtn.style.display = 'none';
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
        if (mShowPhotoBtn) mShowPhotoBtn.style.display = '';
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

    // Скан + OCR
    async function captureAndRecognize() {
        if (scanBusy) return;
        scanBusy = true;
        if (captureBtn) captureBtn.disabled = true;
        if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
        if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
        else if (captureBtn) captureBtn.textContent = 'Сканируем…';

        try {
            if (!video.videoWidth || !video.videoHeight) { alert('Камера ещё не готова'); return; }

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

            await new Promise((resolve)=>{
                canvas.toBlob((blob)=>{
                    try{
                        if(!blob){ alert('Не удалось получить изображение'); return resolve(false); }
                        if (lastPhotoURL) URL.revokeObjectURL(lastPhotoURL);
                        lastPhotoURL = URL.createObjectURL(blob);

                        const formData = new FormData();
                        formData.append('image', blob, 'scan.jpg');

                        const csrf = getCsrf();
                        if (!csrf){ alert('CSRF-токен не найден'); return resolve(false); }

                        fetch('/index.php?r=scan/recognize', {
                            method:'POST', headers:{'X-CSRF-Token':csrf}, body:formData, credentials:'include'
                        })
                            .then(async r=>{
                                if (r.status===429) throw new Error('Превышен лимит OCR-запросов. Подождите минуту и попробуйте снова.');
                                const ct=r.headers.get('content-type')||'';
                                if (!ct.includes('application/json')) { throw new Error('Сервер вернул не JSON.'); }
                                return r.json();
                            })
                            .then(res=>{
                                if (!res.success) {
                                    const msg = (res.error||'').toLowerCase();
                                    if (previewImg && (msg.includes('не удалось извлечь цену') || msg.includes('цена не распознана') || res.reason==='no_amount')) {
                                        previewImg.src = lastPhotoURL;
                                    }
                                    throw new Error(res.error || 'Не удалось распознать цену');
                                }
                                if (mShowPhotoBtn) mShowPhotoBtn.style.display = '';
                                mAmountEl.value = fmt2(res.recognized_amount);
                                mQtyEl.value = 1;
                                mNoteEl.value = '';
                                mPhotoWrap.style.display = 'none';
                                lastParsedText = res.parsed_text || '';
                                resetPhotoPreview(mPhotoWrap, mShowPhotoBtn, mPhotoImg);
                                bootstrapModal?.show();
                                resolve(true);
                            })
                            .catch(err=>{ alert(err.message); resolve(false); });
                    } catch(e){ resolve(false); }
                }, 'image/jpeg', 0.9);
            });

        } finally {
            scanBusy = false;
            if (captureBtn) captureBtn.disabled = false;
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
            if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сканировать';
            else if (captureBtn) captureBtn.textContent = '📸 Сканировать';
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
            } catch (e) { alert(e.message); }
        };
    }

    // init
    if (captureBtn) captureBtn.onclick = captureAndRecognize;

    async function checkShopSession() {
        try {
            const r = await fetch('/index.php?r=site/session-status', { credentials: 'include' });
            const res = await r.json();
            if (!res.ok) return;

            // если нет активной сессии или таймеры вышли — показываем модалку
            if (res.needPrompt && shopModal) {
                // префилл, если есть старые значения (полезно для 45–120 минут)
                if (res.store)     shopStoreEl.value = res.store;
                if (res.category)  shopCatEl.value   = res.category;
                shopModal.show();
            } else {
                // обновим локальные метаданные из ответа (на случай рефреша)
                metaStore    = res.store     || metaStore;
                metaCategory = res.category  || metaCategory;
                scanRoot?.setAttribute('data-store', metaStore);
                scanRoot?.setAttribute('data-category', metaCategory);
            }
        } catch (e) { /* молча */ }
    }
    document.addEventListener('DOMContentLoaded', checkShopSession);

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

            // обновляем метаданные для сохранений
            metaStore    = res.store || store;
            metaCategory = res.category || cat;
            scanRoot?.setAttribute('data-store', metaStore);
            scanRoot?.setAttribute('data-category', metaCategory);

            shopModal?.hide();
            updateScanTitle();
        } catch (e) {
            alert(e.message);
        }
    });
})();


