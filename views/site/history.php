<?php
/** @var array $items */

use yii\helpers\Html;

$this->title = 'РСЃС‚РѕСЂРёСЏ';
$fmt = Yii::$app->formatter;

function rowValueAndLabel(array $r): array
{
    $hasLimit = $r['limit_amount'] !== null;

    // Р’ СЂСѓР±Р»СЏС…
    $limitRub = $hasLimit ? ((int)$r['limit_amount']) / 100 : null;
    $totalRub = ((int)$r['total_amount']) / 100; // РґР»СЏ Р°РєС‚РёРІРЅС‹С… РЅРёР¶Рµ РїРµСЂРµР·Р°РїРёС€РµРј РёР· sum_live

    if ((int)$r['status'] === 9) { // Р—РђРљР Р«РўРђ
        if ($hasLimit) {
            // РћСЃС‚Р°С‚РѕРє РїРµСЂРµСЃС‡РёС‚С‹РІР°РµРј РЅР°РїСЂСЏРјСѓСЋ, С‡С‚РѕР±С‹ РЅРµ Р·Р°РІРёСЃРµС‚СЊ РѕС‚ РєСЌС€Р° limit_left
            $leftRub = (((int)$r['limit_amount']) - ((int)$r['total_amount'])) / 100;
            $value   = $leftRub;
            $label   = 'Р›РёРјРёС‚';
        } else {
            $value = $totalRub;
            $label = 'РС‚РѕРіРѕ';
        }
    } else { // РђРљРўРР’РќРђ
        $sumLive = array_key_exists('sum_live', $r) ? (float)$r['sum_live'] : 0.0; // Р·РґРµСЃСЊ СѓР¶Рµ РІ СЂСѓР±Р»СЏС…
        $totalRub = $sumLive;
        if ($hasLimit) {
            $value = $limitRub - $sumLive; // РѕСЃС‚Р°С‚РѕРє РІ СЂСѓР±Р»СЏС…
            $label = 'Р›РёРјРёС‚';
        } else {
            $value = $sumLive;
            $label = 'РС‚РѕРіРѕ';
        }
    }

    $isOver = $hasLimit && $value < 0;
    $ts = (int)$r['last_ts'];

    // Р’РѕР·РІСЂР°С‰Р°РµРј: [РѕСЃРЅРѕРІРЅРѕРµ_Р·РЅР°С‡РµРЅРёРµ, СЏСЂР»С‹Рє, РїСЂРёР·РЅР°Рє_РїРµСЂРµСЂР°СЃС…РѕРґР°, ts, РёС‚РѕРіРѕРІР°СЏ_СЃСѓРјРјР°, Р»РёРјРёС‚]
    return [$value, $label, $isOver, $ts, $totalRub, $limitRub];
}


?>
<div class="container mt-3">

    <h1 class="h4 mb-3">рџ“ќ РСЃС‚РѕСЂРёСЏ</h1>
    <!-- в‰Ґ sm: С‚Р°Р±Р»РёС†Р° -->
    <div class="d-none d-sm-block">
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th style="width:160px;">Р”Р°С‚Р° Рё РІСЂРµРјСЏ</th>
                <th>РњР°РіР°Р·РёРЅ</th>
                <th>РљР°С‚РµРіРѕСЂРёСЏ</th>
                <th style="width:110px;">РўРёРї</th>
                <th class="text-end" style="width:140px;">РЎСѓРјРјР°</th>
                <th class="text-end" style="width:90px;">Р”РµР№СЃС‚РІРёСЏ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $r):
                [$value, $label, $isOver, $ts, $sumRub, $limitRub] = rowValueAndLabel($r);
                ?>
                <tr>
                    <td>
                        <?= $fmt->asTime($ts, 'php:H:i') ?><br>
                        <span class="text-muted small"><?= $fmt->asDate($ts, 'php:d.m.Y') ?></span>
                    </td>
                    <td><?= Html::encode($r['shop']) ?></td>
                    <td><?= Html::encode($r['category']) ?></td>
                    <td><?= $label ?></td>
                    <td class="text-end <?= $isOver ? 'text-danger fw-bold' : '' ?>">
                        <?= number_format($value, 2, '.', ' ') ?>
                        <?php if ($limitRub !== null): ?>
                            <div class="text-muted small">(<?= number_format($sumRub, 2, '.', ' ') ?>)</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= Html::beginForm(['site/delete-session', 'id' => (int)$r['id']], 'post', [
                            'onsubmit' => "return confirm('РЈРґР°Р»РёС‚СЊ СЃРµСЃСЃРёСЋ Рё РІСЃРµ РµС‘ РїРѕР·РёС†РёРё? Р­С‚Рѕ РґРµР№СЃС‚РІРёРµ РЅРµРѕР±СЂР°С‚РёРјРѕ.');"
                        ]) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">рџ—‘ РЈРґР°Р»РёС‚СЊ</button>
                        <?= Html::endForm() ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < sm: РєР°СЂС‚РѕС‡РєРё -->
    <div class="d-sm-none">
        <?php foreach ($items as $r):
            [$value, $label, $isOver, $ts, $sumRub, $limitRub] = rowValueAndLabel($r);
            ?>
            <div class="card border-0 shadow-sm mb-2">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold"><?= $fmt->asTime($ts, 'php:H:i') ?></div>
                            <div class="text-muted small"><?= $fmt->asDate($ts, 'php:d.m.Y') ?></div>
                            <div class="small mt-1">
                                <span class="fw-semibold"><?= Html::encode($r['shop']) ?></span>
                                <span class="text-muted"> В· <?= Html::encode($r['category']) ?></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted"><?= $label ?></div>
                            <div class="<?= $isOver ? 'text-danger fw-bold' : 'fw-semibold' ?>">
                                <?= number_format($value, 2, '.', ' ') ?>
                                <?php if ($limitRub !== null): ?>
                                    <div class="text-muted small">(<?= number_format($sumRub, 2, '.', ' ') ?>)</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-2">
                        <?= Html::beginForm(['site/delete-session', 'id' => (int)$r['id']], 'post', [
                            'onsubmit' => "return confirm('РЈРґР°Р»РёС‚СЊ СЃРµСЃСЃРёСЋ Рё РІСЃРµ РµС‘ РїРѕР·РёС†РёРё? Р­С‚Рѕ РґРµР№СЃС‚РІРёРµ РЅРµРѕР±СЂР°С‚РёРјРѕ.');"
                        ]) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">рџ—‘ РЈРґР°Р»РёС‚СЊ</button>
                        <?= Html::endForm() ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

