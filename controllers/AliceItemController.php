<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\services\Alice\AliceListService;

class AliceItemController extends Controller
{
    /**
     * CRUD-страница списка покупок
     * GET /alice-item
     */
    public function actionIndex()
    {
        $service = new AliceListService();

        return $this->render('index', [
            'items' => $service->getAll(Yii::$app->user->id),
        ]);
    }

    /**
     * Создание пункта вручную
     * POST /alice-item/create
     */
    public function actionCreate()
    {
        $service = new AliceListService();

        try {
            $service->addItem(
                Yii::$app->user->id,
                (string)Yii::$app->request->post('title')
            );
        } catch (\DomainException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->redirect(['index']);
    }

    /**
     * Обновление названия
     * POST /alice-item/update?id=123
     */
    public function actionUpdate($id)
    {
        $service = new AliceListService();

        try {
            $service->updateItem(
                Yii::$app->user->id,
                (int)$id,
                (string)Yii::$app->request->post('title')
            );
        } catch (\DomainException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->redirect(['index']);
    }

    /**
     * Удаление пункта
     * POST /alice-item/delete?id=123
     */
    public function actionDelete($id)
    {
        $service = new AliceListService();
        $service->deleteItem(Yii::$app->user->id, (int)$id);

        return $this->redirect(['index']);
    }

    /**
     * Toggle is_done
     * POST /alice-item/toggle-done?id=123
     */
    public function actionToggleDone($id)
    {
        $service = new AliceListService();
        $item = $service->toggleDone(Yii::$app->user->id, (int)$id);

        // AJAX → JSON
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return [
                'success' => true,
                'is_done' => (int)$item->is_done,
            ];
        }

        // Обычная форма → назад
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Toggle is_pinned
     * POST /alice-item/toggle-pinned?id=123
     */
    public function actionTogglePinned($id)
    {
        $service = new AliceListService();
        $item = $service->togglePinned(Yii::$app->user->id, (int)$id);

        // AJAX → JSON
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return [
                'success'    => true,
                'is_pinned'  => (int)$item->is_pinned,
            ];
        }

        // Обычная форма → назад
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * JSON для выпадающего списка на сканере
     * GET /alice-item/list-json
     */
    public function actionListJson()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $service = new AliceListService();
        $items = $service->getForDropdown(Yii::$app->user->id);

        return array_map(static fn($i) => [
            'id'        => $i->id,
            'title'     => $i->title,
            'is_done'   => (int)$i->is_done,
            'is_pinned' => (int)$i->is_pinned,
        ], $items);
    }
}
