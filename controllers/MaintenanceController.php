<?php
namespace app\controllers;

use yii\web\Controller;
use yii\web\Response;
use yii\helpers\ArrayHelper;
use app\models\PurchaseSession;

class MaintenanceController extends Controller
{
    // будем принимать запросы через r=maintenance/... (CSRF тут выключим)
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /** Мини-проверка доступа: нужен логин + секрет из params */
    private function guard(): ?Response
    {
        if (\Yii::$app->user->isGuest) {
            \Yii::$app->response->statusCode = 401;
            return $this->asJson(['ok' => false, 'err' => 'login required']);
        }
        $secretParam = (string)\Yii::$app->request->get('s', \Yii::$app->request->post('s', ''));
        $secretConf  = (string)(\Yii::$app->params['maint_secret'] ?? '');
        if ($secretConf === '' || !hash_equals($secretConf, $secretParam)) {
            \Yii::$app->response->statusCode = 403;
            return $this->asJson(['ok' => false, 'err' => 'forbidden']);
        }
        return null;
    }

    /**
     * Сухой прогон: показать кандидатов на автозакрытие (ничего не меняет).
     * GET /index.php?r=maintenance/dry-stale&s=SECRET&seconds=10800
     */
    public function actionDryStale(int $seconds = null)
    {
        if ($resp = $this->guard()) return $resp;

        $ttl = $seconds ?? (int)\Yii::$app->ps->autocloseSeconds;
        $limitTs = time() - $ttl;

        $rows = PurchaseSession::find()
            ->where(['status' => PurchaseSession::STATUS_ACTIVE])
            ->andWhere(['<', 'updated_at', $limitTs])
            ->orderBy(['updated_at' => SORT_ASC])
            ->asArray()->all();

        if (!$rows) return ['ok' => true, 'items' => [], 'msg' => 'Кандидатов нет'];

        // вернём компактный список
        $items = array_map(function($r){
            return [
                'id'      => (int)$r['id'],
                'user_id' => (int)$r['user_id'],
                'started' => (int)$r['started_at'],
                'updated' => (int)$r['updated_at'],
                'limit'   => $r['limit_amount'], // копейки или null
            ];
        }, $rows);

        return ['ok' => true, 'count' => count($items), 'items' => $items];
    }

    /**
     * Финализировать ВСЕ «протухшие» активные сессии.
     * РЕКОМЕНДУЮ вызывать POST’ом.
     * POST /index.php?r=maintenance/finalize-stale&s=SECRET&seconds=10800
     */
    public function actionFinalizeStale(int $seconds = null)
    {
        if ($resp = $this->guard()) return $resp;

        $ttl = $seconds ?? (int)\Yii::$app->ps->autocloseSeconds;
        $limitTs = time() - $ttl;

        $rows = PurchaseSession::find()
            ->where(['status' => PurchaseSession::STATUS_ACTIVE])
            ->andWhere(['<', 'updated_at', $limitTs])
            ->orderBy(['updated_at' => SORT_ASC])
            ->all();

        $ok = 0; $fail = 0; $idsOk = []; $idsFail = [];
        foreach ($rows as $ps) {
            try {
                \Yii::$app->ps->finalize($ps, 'retro-http');
                $ok++;  $idsOk[] = (int)$ps->id;
            } catch (\Throwable $e) {
                $fail++; $idsFail[] = ['id'=>(int)$ps->id,'err'=>$e->getMessage()];
            }
        }

        return ['ok' => true, 'finalized' => $ok, 'failed' => $fail, 'ids_ok' => $idsOk, 'ids_fail' => $idsFail];
    }

    /**
     * Финализировать ОДНУ сессию по ID.
     * POST /index.php?r=maintenance/finalize-one&s=SECRET&id=123
     */
    public function actionFinalizeOne(int $id)
    {
        if ($resp = $this->guard()) return $resp;

        $ps = PurchaseSession::findOne(['id' => $id]);
        if (!$ps) {
            \Yii::$app->response->statusCode = 404;
            return ['ok' => false, 'err' => 'not found'];
        }
        try {
            \Yii::$app->ps->finalize($ps, 'manual-http');
            return ['ok' => true, 'id' => (int)$ps->id];
        } catch (\Throwable $e) {
            \Yii::$app->response->statusCode = 500;
            return ['ok' => false, 'err' => $e->getMessage()];
        }
    }
}
