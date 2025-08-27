<?php

namespace app\components;

use app\models\PurchaseSession;
use Yii;
use yii\base\Component;
use yii\db\Query;

class PurchaseSessionService extends Component
{
    /** через сколько секунд закрывать неактивную сессию */
    public int $autocloseSeconds = 10800; // 3 часа

    /** Активная сессия (автозакрытие при протухании) */
    public function active(int $userId): ?PurchaseSession
    {
        // пробуем по id из php-сессии
        if ($sid = Yii::$app->session->get('purchase_session_id')) {
            $ps = PurchaseSession::findOne(['id' => $sid, 'user_id' => $userId, 'status' => PurchaseSession::STATUS_ACTIVE]);
            if ($ps) {
                $this->autoCloseIfStale($ps);
                return $ps->status === PurchaseSession::STATUS_ACTIVE ? $ps : null;
            }
        }
        // берём последнюю активную
        $ps = PurchaseSession::find()
            ->where(['user_id' => $userId, 'status' => PurchaseSession::STATUS_ACTIVE])
            ->orderBy(['updated_at' => SORT_DESC])->limit(1)->one();
        if ($ps) {
            Yii::$app->session->set('purchase_session_id', $ps->id);
            $this->autoCloseIfStale($ps);
            return $ps->status === PurchaseSession::STATUS_ACTIVE ? $ps : null;
        }
        return null;
    }

    /** Обязательное наличие активной сессии, иначе \yii\web\HttpException */
    public function requireActive(int $userId): PurchaseSession
    {
        $ps = $this->active($userId);
        if (!$ps) {
            throw new \yii\web\BadRequestHttpException('Нет активной покупки. Начните или возобновите сессию.');
        }
        return $ps;
    }

    /** Обновить «пульс» */
    public function touch(PurchaseSession $ps): void
    {
        $ps->updateAttributes(['updated_at' => time()]);
    }

    /** Закрыть активную */
    public function closeActive(int $userId): void
    {
        PurchaseSession::updateAll(
            ['status' => PurchaseSession::STATUS_CLOSED, 'updated_at' => time()],
            ['user_id' => $userId, 'status' => PurchaseSession::STATUS_ACTIVE]
        );
        Yii::$app->session->remove('purchase_session_id');
    }

    /** Последняя активность */
    public function lastActivityTs(PurchaseSession $ps): int
    {
        $last = (new Query())->from('price_entry')
            ->where(['session_id' => $ps->id, 'user_id' => $ps->user_id])
            ->max('created_at');
        return (int)($last ?: $ps->started_at);
    }

    private function autoCloseIfStale(PurchaseSession $ps): void
    {
        if (time() - $this->lastActivityTs($ps) >= $this->autocloseSeconds) {
            $ps->updateAttributes(['status' => PurchaseSession::STATUS_CLOSED, 'updated_at' => time()]);
            Yii::$app->session->remove('purchase_session_id');
        }
    }
}
