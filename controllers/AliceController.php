<?php

use app\services\Alice\AliceListService;
use yii\web\Response;
use yii\web\Controller;


class AliceController extends Controller
{
public function actionList()
{
$this->layout = false;
Yii::$app->response->format = Response::FORMAT_JSON;

$userId = Yii::$app->user->id;
$service = new AliceListService();
$items = $service->getActiveList($userId);

return array_map(fn($i) => [
'id'    => $i->id,
'title' => $i->title,
], $items);
}
}
