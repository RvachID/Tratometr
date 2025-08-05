<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\web\NotFoundHttpException;
use yii\filters\AccessControl;
use app\models\PriceEntry;

class PriceController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['index','list','save','qty','delete'],
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    /** Рендерим один экран (SPA-like) */
    public function actionIndex()
    {
        return $this->render('index');
    }

    /** Список записей (новые сверху) + текущий итог; пагинация offset/limit */
    public function actionList($offset = 0, $limit = 50)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $query = PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->orderBy(['created_at' => SORT_DESC]);

        $items = $query->offset((int)$offset)->limit((int)$limit)->all();
        $total = (float) $query->sum('amount * qty');

        return [
            'total' => number_format($total, 2, '.', ''),
            'items' => array_map(function(PriceEntry $m){
                return [
                    'id' => $m->id,
                    'created_at' => Yii::$app->formatter->asDatetime($m->created_at),
                    'store' => (string)$m->store,
                    'category' => (string)$m->category,
                    'amount' => (float)$m->amount,
                    'qty' => (float)$m->qty,
                    'rowTotal' => number_format($m->amount * $m->qty, 2, '.', ''),
                    'source' => (string)$m->source,
                    'note' => (string)$m->note,
                ];
            }, $items),
            'hasMore' => $query->count() > ($offset + $limit),
        ];
    }

    /** Создание/обновление (id опционален). Возвращает обновлённые totals строки/списка. */
    public function actionSave()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $id = (int)Yii::$app->request->post('id', 0);

        $model = $id
            ? PriceEntry::findOne(['id' => $id, 'user_id' => Yii::$app->user->id])
            : new PriceEntry();

        if (!$model) {
            throw new NotFoundHttpException('Запись не найдена');
        }

        $model->load(Yii::$app->request->post(), '');
        if ($model->isNewRecord) {
            $model->source = $model->source ?: 'manual';
            $model->qty = $model->qty ?: 1;
        }

        if (!$model->validate()) {
            return ['error' => current($model->firstErrors) ?: 'Ошибка валидации'];
        }

        $model->save(false);

        $listTotal = (float) PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->sum('amount * qty');

        return [
            'id' => $model->id,
            'rowTotal' => number_format($model->amount * $model->qty, 2, '.', ''),
            'listTotal' => number_format($listTotal, 2, '.', ''),
        ];
    }

    /** + / − / set(дробное) для qty */
    public function actionQty($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id]);
        if (!$model) throw new NotFoundHttpException('Запись не найдена');

        $op = Yii::$app->request->post('op');
        $value = Yii::$app->request->post('value');

        if ($op === 'inc') {
            $model->qty += 1;
        } elseif ($op === 'dec') {
            $model->qty = max(0.001, $model->qty - 1);
        } elseif ($op === 'set') {
            $model->qty = max(0.001, (float)$value);
        }

        $model->save(false);

        $listTotal = (float) PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->sum('amount * qty');

        return [
            'qty' => (float)$model->qty,
            'rowTotal' => number_format($model->amount * $model->qty, 2, '.', ''),
            'listTotal' => number_format($listTotal, 2, '.', ''),
        ];
    }

    /** Удаление записи */
    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id]);
        if (!$model) throw new NotFoundHttpException('Запись не найдена');

        $model->delete();

        $listTotal = (float) PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->sum('amount * qty');

        return ['listTotal' => number_format($listTotal, 2, '.', '')];
    }
}
