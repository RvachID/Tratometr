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
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        // Активная серверная сессия покупки (ожидается метод getActivePurchaseSession() в этом контроллере)
        $ps = $this->getActivePurchaseSession();
        if (!$ps) {
            return ['error' => 'Нет активной покупки. Начните или возобновите сессию.'];
        }

        $id = (int)Yii::$app->request->post('id', 0);

        // Разрешаем редактировать только записи текущей активной сессии
        $model = $id
            ? \app\models\PriceEntry::findOne([
                'id' => $id,
                'user_id' => Yii::$app->user->id,
                'session_id' => $ps->id,
            ])
            : new \app\models\PriceEntry();

        if (!$model) {
            throw new \yii\web\NotFoundHttpException('Запись не найдена');
        }

        $model->load(Yii::$app->request->post(), '');

        if ($model->isNewRecord) {
            // Никогда не доверяем user_id из POST
            $model->user_id = Yii::$app->user->id;
            // Привязываем к активной серверной сессии
            $model->session_id = $ps->id;

            // Значения по умолчанию
            $model->source = $model->source ?: 'manual';
            $model->qty    = $model->qty ?: 1;

            // Синхронизируем магазин/категорию с сессией
            $model->store    = $ps->shop;
            $model->category = $ps->category ?: null;

            // (Если у тебя есть created_at) — на всякий случай
            if (property_exists($model, 'created_at') && empty($model->created_at)) {
                $model->created_at = time();
            }
        } else {
            // При редактировании не даём "переехать" в другую сессию/юзера
            $model->user_id    = Yii::$app->user->id;
            $model->session_id = $ps->id;
        }

        if (!$model->validate()) {
            return ['error' => current($model->firstErrors) ?: 'Ошибка валидации'];
        }

        $model->save(false);

        // Обновляем "пульс" сессии
        $ps->updateAttributes(['updated_at' => time()]);

        // Итог ТОЛЬКО по текущей активной сессии
        $listTotal = (float)\app\models\PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
            ->sum('amount * qty');

        return [
            'id'        => $model->id,
            'rowTotal'  => number_format($model->amount * $model->qty, 2, '.', ''),
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

    public function actionGetLast()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->user->isGuest) {
            return ['error' => 'Not authorized'];
        }

        $row = (new \yii\db\Query())
            ->from('price_entry')
            ->where(['user_id' => Yii::$app->user->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(1)
            ->one();

        if ($row) {
            return [
                'price' => $row['recognized_amount'] ?: $row['amount'],
                'text' => $row['recognized_text']
            ];
        }
        return ['price' => null];
    }

}
