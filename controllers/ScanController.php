<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\PriceEntry;

class ScanController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => \yii\filters\VerbFilter::class,
                'actions' => [
                    'store'  => ['post'],
                    'update' => ['post'],
                    'delete' => ['post'],
                ],
            ],
        ];
    }

    /** Создание позиции из сканера */
    public function actionStore()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->user->isGuest) return ['success'=>false,'error'=>'Требуется вход'];

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success'=>false,'error'=>'Нет активной покупки.'];

        $amount = Yii::$app->request->post('amount');
        $qty    = Yii::$app->request->post('qty', 1);
        $note   = (string)Yii::$app->request->post('note', '');
        $text   = (string)Yii::$app->request->post('parsed_text', '');

        if (!is_numeric($amount) || (float)$amount <= 0) return ['success'=>false,'error'=>'Неверная сумма'];
        if (!is_numeric($qty)    || (float)$qty    <= 0)   $qty = 1;

        $m = new PriceEntry();
        $m->user_id           = Yii::$app->user->id;
        $m->session_id        = $ps->id;
        $m->amount            = (float)$amount;
        $m->qty               = (float)$qty;
        $m->store             = $ps->shop;
        $m->category          = $ps->category ?: null;
        $m->note              = $note;
        $m->recognized_text   = $text;
        $m->recognized_amount = (float)$amount;
        $m->source            = 'price_tag';
        $m->created_at        = time();
        $m->updated_at        = time();
        $m->save(false);

        Yii::$app->ps->touch($ps);

        $total = (float) PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->sum('amount * qty');

        return ['success'=>true, 'entry'=>[
            'id'=>$m->id,'amount'=>$m->amount,'qty'=>$m->qty,'note'=>(string)$m->note,
            'store'=>(string)$m->store,'category'=>$m->category,
        ], 'total'=>$total];
    }

    /** Автосохранение правок суммы/qty */
    public function actionUpdate($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success'=>false,'error'=>'Нет активной покупки.'];

        $m = PriceEntry::findOne(['id'=>(int)$id, 'user_id'=>Yii::$app->user->id]);
        if (!$m) return ['success'=>false,'error'=>'Запись не найдена'];

        $m->load(Yii::$app->request->post(), '');
        $m->user_id    = Yii::$app->user->id;
        $m->session_id = $ps->id;                  // фиксируем принадлежность
        $m->store      = $ps->shop;
        $m->category   = $ps->category ?: null;
        $m->updated_at = time();
        $m->save(false);

        Yii::$app->ps->touch($ps);

        $total = (float) PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->sum('amount * qty');

        return ['success'=>true, 'total'=>number_format($total, 2, '.', '')];
    }

    /** Удаление позиции */
    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success'=>false,'error'=>'Нет активной покупки.'];

        $m = PriceEntry::findOne(['id'=>(int)$id, 'user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id]);
        if (!$m) return ['success'=>false,'error'=>'Запись не найдена'];

        $m->delete();

        $total = (float) PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->sum('amount * qty');

        return ['success'=>true, 'total'=>number_format($total, 2, '.', '')];
    }
}
