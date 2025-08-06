<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
use yii\helpers\Html;

$this->title = 'Траты';
$listUrl = Url::to(['price/list']);
$saveUrl = Url::to(['price/save']);
$qtyUrl  = Url::to(['price/qty']);
$delUrl  = Url::to(['price/delete']);
$csrf = Yii::$app->request->getCsrfToken();
?>
<!--<style>
    /* Mobile-first */
    .page { padding: 12px; }
    .total-card { position: sticky; top: 0; background: #fff; z-index: 5; padding: 12px; border-bottom: 1px solid #eee; }
    .total-value { font-size: 22px; font-weight: 700; }
    .add-btn { position: fixed; right: 16px; bottom: 16px; z-index: 10; }

    .entry { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .entry-main { flex: 1; min-width: 0; }
    .entry-title { font-size: 15px; font-weight: 600; }
    .entry-meta { color: #777; font-size: 12px; margin-top: 2px; }
    .entry-actions { display: flex; align-items: center; gap: 6px; margin-left: 8px; }
    .qty-chip { display:inline-flex; align-items:center; border:1px solid #ddd; border-radius: 16px; overflow:hidden; }
    .qty-chip button { border: none; background: #f7f7f7; padding: 2px 8px; font-size: 16px; }
    .qty-chip .qty-val { padding: 0 8px; min-width: 48px; text-align:center; }

    .sheet { position: fixed; left: 0; right: 0; bottom: -100%; background: #fff; border-top-left-radius: 14px; border-top-right-radius: 14px; box-shadow: 0 -6px 16px rgba(0,0,0,.08); transition: bottom .25s ease; z-index: 20; }
    .sheet.open { bottom: 0; }
    .sheet-header { padding: 12px; border-bottom: 1px solid #eee; display:flex; justify-content:space-between; align-items:center; }
    .sheet-body { padding: 12px; }
    .form-row { margin-bottom: 10px; }
    .form-row label { display:block; font-size:13px; color:#666; margin-bottom:4px; }
    .form-row input, .form-row select { width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:16px; }
    .btn { display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid transparent; background:#198754; color:#fff; text-decoration:none; }
    .btn.secondary { background:#f0f0f0; color:#333; border-color:#e0e0e0; }
    .btn.danger { background:#dc3545; }
    .load-more { display:block; width:100%; text-align:center; padding:10px; margin:10px 0 32px; color:#555; border:1px solid #eee; border-radius:8px; background:#fafafa; }
    .small { font-size:12px; color:#777; }
</style>

<div class="page">

    <div class="total-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div class="small"><?/*= Html::encode($this->title) */?></div>
                <div class="total-value">Итого: <span id="list-total">0.00</span></div>
            </div>
            <a href="#" id="btn-add" class="btn">Добавить</a>
        </div>
    </div>

    <div id="list"></div>
    <button id="btn-more" class="load-more" style="display:none;">Показать ещё</button>

    <a class="btn add-btn" id="fab-add" href="#">＋</a>
</div>

<!-- Bottom sheet -->
<!--<div id="sheet" class="sheet" aria-hidden="true">
    <div class="sheet-header">
        <strong id="sheet-title">Новая запись</strong>
        <a href="#" id="sheet-close" class="small">Закрыть</a>
    </div>
    <div class="sheet-body">
        <form id="entry-form" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?/*/*= Html::encode($csrf) */*/?>">
            <input type="hidden" name="id" id="f-id">

            <div class="form-row">
                <label for="f-amount">Цена за ед.</label>
                <input id="f-amount" name="amount" type="number" step="0.01" min="0" placeholder="например 199.99" required>
            </div>

            <div class="form-row">
                <label for="f-qty">Количество (можно дробное)</label>
                <input id="f-qty" name="qty" type="number" step="0.001" min="0.001" value="1">
            </div>

            <div class="form-row">
                <label for="f-store">Магазин</label>
                <input id="f-store" name="store" type="text" placeholder="опционально">
            </div>

            <div class="form-row">
                <label for="f-category">Категория</label>
                <input id="f-category" name="category" type="text" placeholder="опционально">
            </div>

            <div class="form-row">
                <label for="f-source">Источник</label>
                <select id="f-source" name="source">
                    <option value="manual">Ручной ввод</option>
                    <option value="price_tag">Ценник (OCR)</option>
                    <option value="receipt">Чек (OCR)</option>
                </select>
            </div>

            <div class="form-row">
                <label for="f-note">Заметка</label>
                <input id="f-note" name="note" type="text" placeholder="опционально">
            </div>

            <div style="display:flex; gap:8px; margin-top:12px;">
                <button type="submit" class="btn" id="btn-save">Сохранить</button>
                <a href="#" class="btn secondary" id="btn-cancel">Отмена</a>
            </div>
        </form>
    </div>
</div>

<script>
    (function(){
        const listEl = document.getElementById('list');
        const totalEl = document.getElementById('list-total');
        const moreBtn = document.getElementById('btn-more');
        const sheet = document.getElementById('sheet');
        const form = document.getElementById('entry-form');
        const sheetTitle = document.getElementById('sheet-title');

        const endpoints = {
            list: '<?/*= $listUrl */?>',
            save: '<?/*= $saveUrl */?>',
            qty:  '<?/*= $qtyUrl  */?>',
            del:  '<?/*= $delUrl  */?>',
        };

        let offset = 0, limit = 30, loading = false, editingId = null;

        function openSheet(title, data){
            sheet.classList.add('open');
            sheet.setAttribute('aria-hidden', 'false');
            sheetTitle.textContent = title || 'Новая запись';
            form.reset();
            document.getElementById('f-id').value = data?.id || '';
            document.getElementById('f-amount').value = data?.amount ?? '';
            document.getElementById('f-qty').value = data?.qty ?? 1;
            document.getElementById('f-store').value = data?.store ?? '';
            document.getElementById('f-category').value = data?.category ?? '';
            document.getElementById('f-source').value = data?.source ?? 'manual';
            document.getElementById('f-note').value = data?.note ?? '';
        }
        function closeSheet(){
            sheet.classList.remove('open');
            sheet.setAttribute('aria-hidden', 'true');
        }
        document.getElementById('sheet-close').addEventListener('click', e=>{ e.preventDefault(); closeSheet(); });
        document.getElementById('btn-cancel').addEventListener('click', e=>{ e.preventDefault(); closeSheet(); });

        document.getElementById('btn-add').addEventListener('click', e=>{ e.preventDefault(); editingId=null; openSheet('Новая запись'); });
        document.getElementById('fab-add').addEventListener('click', e=>{ e.preventDefault(); editingId=null; openSheet('Новая запись'); });

        function rowTpl(it){
            return `
      <div class="entry" data-id="${it.id}">
        <div class="entry-main">
          <div class="entry-title">${escapeHtml(it.store || it.category || 'Без названия')} — ${fmt(it.amount)} за ед.</div>
          <div class="entry-meta">${it.created_at} · ${it.source || 'manual'} ${it.note ? ' · '+escapeHtml(it.note) : ''}</div>
        </div>
        <div class="entry-actions">
          <div class="qty-chip">
            <button class="act-dec" title="−1">−</button>
            <span class="qty-val" data-role="qty">${fmtQty(it.qty)}</span>
            <button class="act-inc" title="+1">+</button>
          </div>
          <button class="act-set btn secondary" title="Дробное кол-во">×</button>
          <div style="min-width:84px; text-align:right;">
            <div><strong data-role="rowTotal">${fmt(it.rowTotal)}</strong></div>
          </div>
          <button class="act-edit btn secondary" title="Редактировать">Ред.</button>
          <button class="act-del btn danger" title="Удалить">×</button>
        </div>
      </div>
    `;
        }

        function loadList(reset=false){
            if(loading) return;
            loading = true;
            if(reset) { offset = 0; listEl.innerHTML=''; }
            fetch(`${endpoints.list}?offset=${offset}&limit=${limit}`, {credentials:'same-origin'})
                .then(r=>r.json()).then(d=>{
                totalEl.textContent = fmt(d.total);
                d.items.forEach(it => listEl.insertAdjacentHTML('beforeend', rowTpl(it)));
                offset += d.items.length;
                moreBtn.style.display = d.hasMore ? 'block' : 'none';
            }).finally(()=> loading=false);
        }

        moreBtn.addEventListener('click', ()=> loadList(false));

        // Сохранение (create/update)
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(form);
            fetch(endpoints.save, {
                method: 'POST',
                credentials:'same-origin',
                headers: {'X-CSRF-Token': '<?/*= $csrf */?>'},
                body: fd
            }).then(r=>r.json()).then(d=>{
                if(d.error){ alert(d.error); return; }
                closeSheet();
                // Обновим список: проще перезагрузить сначала порцию
                listEl.innerHTML=''; offset = 0; loadList(true);
            }).catch(()=> alert('Ошибка сохранения'));
        });

        // Делегирование кликов по действиям
        listEl.addEventListener('click', function(e){
            const row = e.target.closest('.entry'); if(!row) return;
            const id = row.getAttribute('data-id');

            if(e.target.classList.contains('act-inc')){
                postQty(id, {op:'inc'}).then(updateRow.bind(null, row));
            }
            if(e.target.classList.contains('act-dec')){
                postQty(id, {op:'dec'}).then(updateRow.bind(null, row));
            }
            if(e.target.classList.contains('act-set')){
                const val = prompt('Введите количество (можно дробное, например 0.25):');
                if(val === null) return;
                const num = parseFloat(val.replace(',', '.'));
                if(isNaN(num) || num <= 0) { alert('Некорректное число'); return; }
                postQty(id, {op:'set', value: num}).then(updateRow.bind(null, row));
            }
            if(e.target.classList.contains('act-edit')){
                // Соберём данные из строки как заготовку
                const qty = row.querySelector('[data-role="qty"]').textContent.trim();
                const total = row.querySelector('[data-role="rowTotal"]').textContent.trim();
                // Для редактирования лучше подтянуть актуальные поля с сервера — будем простыми:
                // Откроем форму с пустыми store/category/source/note; amount/qty пользователь задаст заново.
                openSheet('Редактирование', { id, qty: parseFloat(qty), amount: '' });
            }
            if(e.target.classList.contains('act-del')){
                if(!confirm('Удалить запись?')) return;
                fetch(`${endpoints.del}?id=${id}`, {
                    method:'POST',
                    credentials:'same-origin',
                    headers: {'X-CSRF-Token': '<?/*= $csrf */?>'}
                }).then(r=>r.json()).then(d=>{
                    if(d.error){ alert(d.error); return; }
                    row.remove();
                    totalEl.textContent = fmt(d.listTotal);
                });
            }
        });

        function postQty(id, payload){
            const fd = new FormData();
            fd.append('_csrf', '<?/*= $csrf */?>');
            Object.keys(payload).forEach(k => fd.append(k, payload[k]));
            return fetch(`${endpoints.qty}?id=${id}`, {
                method:'POST',
                credentials:'same-origin',
                body: fd
            }).then(r=>r.json());
        }

        function updateRow(row, res){
            if(res.error){ alert(res.error); return; }
            row.querySelector('[data-role="qty"]').textContent = fmtQty(res.qty);
            row.querySelector('[data-role="rowTotal"]').textContent = fmt(res.rowTotal);
            totalEl.textContent = fmt(res.listTotal);
        }

        function fmt(x){
            const n = typeof x === 'string' ? parseFloat(x) : x;
            return (isNaN(n) ? 0 : n).toFixed(2);
        }
        function fmtQty(x){
            const n = typeof x === 'string' ? parseFloat(x) : x;
            // покомпактнее: до 3 знаков, но без лишних нулей
            return (isNaN(n) ? 0 : n).toLocaleString(undefined, {maximumFractionDigits: 3});
        }
        function escapeHtml(s){
            return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        // старт
        loadList(true);
    })();
</script>-->
<?php
$this->title = 'Сканер цен';
?>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<style>
    .scan-btn {
        display: block;
        width: 80%;
        max-width: 300px;
        margin: 40px auto;
        padding: 15px;
        font-size: 20px;
        background-color: #4CAF50;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
    }
    .scan-btn:active { background-color: #45a049; }
    #result { text-align: center; margin-top: 20px; font-size: 24px; font-weight: bold; }
    #spinner { display: none; text-align: center; margin-top: 20px; }
</style>

<input type="file" id="imageInput" accept="image/*" capture="environment" style="display:none">
<button id="scanBtn" class="scan-btn">📷 Сканировать</button>
<div id="spinner">⏳ Обработка...</div>
<div id="result"></div>

<script>
    const scanBtn = document.getElementById('scanBtn');
    const imageInput = document.getElementById('imageInput');
    const spinner = document.getElementById('spinner');
    const resultDiv = document.getElementById('result');

    scanBtn.addEventListener('click', () => imageInput.click());

    if (imageInput) {
        imageInput.addEventListener("change", function () {
            const file = imageInput.files[0];
            if (!file || file.size === 0 || !file.type) {
                alert("Изображение повреждено или не выбрано.");
                return;
            }
            if (!file.type.startsWith("image/")) {
                alert("Файл не является изображением.");
                return;
            }

            spinner.style.display = "block";
            resultDiv.innerText = "";

            const reader = new FileReader();
            reader.onload = function (e) {
                const img = new Image();
                img.onload = function () {
                    if (img.width === 0 || img.height === 0) {
                        spinner.style.display = "none";
                        alert("Ошибка: изображение пустое.");
                        return;
                    }

                    // 1. Ресайз
                    const maxSize = 1024;
                    const scale = Math.min(maxSize / img.width, maxSize / img.height);
                    const resizedCanvas = document.createElement("canvas");
                    const rctx = resizedCanvas.getContext("2d");
                    resizedCanvas.width = img.width * scale;
                    resizedCanvas.height = img.height * scale;
                    rctx.drawImage(img, 0, 0, resizedCanvas.width, resizedCanvas.height);

                    // 2. Обрезка центральной области
                    const cropCanvas = document.createElement("canvas");
                    const cctx = cropCanvas.getContext("2d");
                    const cropWidth = resizedCanvas.width * 0.9;  // 90% ширины
                    const cropHeight = resizedCanvas.height * 0.8; // 80% высоты
                    const startX = (resizedCanvas.width - cropWidth) / 2;
                    const startY = (resizedCanvas.height - cropHeight) / 2;
                    cropCanvas.width = cropWidth;
                    cropCanvas.height = cropHeight;
                    cctx.drawImage(resizedCanvas, startX, startY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight);

                    // 3. Усиление контраста и перевод в ч/б
                    const imageData = cctx.getImageData(0, 0, cropWidth, cropHeight);
                    const data = imageData.data;
                    const contrast = 40; // процент усиления (0-100)
                    const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));

                    for (let i = 0; i < data.length; i += 4) {
                        // усиление контраста
                        data[i]   = truncate(factor * (data[i] - 128) + 128);
                        data[i+1] = truncate(factor * (data[i+1] - 128) + 128);
                        data[i+2] = truncate(factor * (data[i+2] - 128) + 128);
                        // преобразование в ч/б (среднее)
                        const avg = (data[i] + data[i+1] + data[i+2]) / 3;
                        const bw = avg > 128 ? 255 : 0;
                        data[i] = data[i+1] = data[i+2] = bw;
                    }
                    cctx.putImageData(imageData, 0, 0);

                    // 4. Конвертируем в base64
                    const compressedDataUrl = cropCanvas.toDataURL("image/jpeg", 0.9);

                    // 5. Отправка в OCR.Space
                    const formData = new FormData();
                    formData.append("base64Image", compressedDataUrl);
                    formData.append("apikey", "K82943706188957"); // твой API ключ
                    formData.append("language", "rus");
                    formData.append("isOverlayRequired", true);

                    axios.post("https://api.ocr.space/parse/image", formData)
                        .then(response => {
                            spinner.style.display = "none";
                            const overlay = response.data?.ParsedResults?.[0]?.TextOverlay?.Lines;
                            if (Array.isArray(overlay)) {
                                extractLargestPrice(overlay);
                            } else {
                                resultDiv.innerText = "Не удалось распознать текст.";
                            }
                        })
                        .catch(error => {
                            spinner.style.display = "none";
                            resultDiv.innerText = "Ошибка OCR: " + error.message;
                        });
                };
                img.onerror = function () {
                    spinner.style.display = "none";
                    alert("Ошибка загрузки изображения.");
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    function truncate(value) {
        return Math.min(255, Math.max(0, value));
    }

    function extractLargestPrice(overlay) {
        let maxFontSize = 0;
        let maxPrice = 0;
        overlay.forEach(line => {
            line.Words.forEach(word => {
                if (!word.IsStrikethrough && !/%/.test(word.WordText)) {
                    const cleanText = word.WordText.replace(/[^\d.,]/g, '');
                    const numericValue = parseFloat(cleanText.replace(',', '.'));
                    const fontSize = Math.abs(word.Height);
                    if (!isNaN(numericValue) && numericValue > 0 && fontSize > maxFontSize) {
                        maxFontSize = fontSize;
                        maxPrice = numericValue;
                    }
                }
            });
        });
        if (maxPrice > 0) {
            resultDiv.innerText = "Цена: " + maxPrice;
        } else {
            resultDiv.innerText = "Цена не найдена.";
        }
    }
</script>
