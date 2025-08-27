<?php

namespace app\components;

use Yii;
use yii\base\Application;
use yii\base\Behavior;

class TimezoneMiddleware extends Behavior
{
    public function events(): array
    {
        return [Application::EVENT_BEFORE_REQUEST => 'apply'];
    }

    public function apply(): void
    {
        // по умолчанию всё вычисляем и храним в UTC
        Yii::$app->timeZone = 'UTC';

        $tz = 'UTC';
        if (!Yii::$app->user->isGuest && !empty(Yii::$app->user->identity->timezone)) {
            $tz = Yii::$app->user->identity->timezone;
        } else {
            $cookieTz = Yii::$app->request->cookies->getValue('tz');
            if ($cookieTz) $cookieTz = urldecode($cookieTz);
            if ($cookieTz) $tz = $cookieTz;
        }

        // валидация IANA-идентификатора
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }

        // показываем даты/время в TZ пользователя
        Yii::$app->formatter->defaultTimeZone = 'UTC';
        Yii::$app->formatter->timeZone = $tz;
    }
}
