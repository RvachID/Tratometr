<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'language' => 'ru-RU',
    'sourceLanguage' => 'en-US',
    'components' => [
        'request' => [
            'cookieValidationKey' => 'JnrKGc4dsJmo_uU1hCj-k7W2Ettg3Y8A',
            'enableCsrfValidation' => true,
            'enableCsrfCookie' => true,
            'csrfParam' => '_csrf',
            'csrfCookie' => [
                'httpOnly' => true,
                'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
                'secure' => YII_ENV_PROD,
                'path' => '/',
            ],
        ],

        'session' => [
            'cookieParams' => [
                'httpOnly' => true,
                'sameSite' => 'Lax',
                'secure' => YII_ENV_PROD,
            ],
            'timeout' => 3600 * 24 * 7,
        ],

        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],

        'errorHandler' => [
            'errorAction' => 'site/error',
        ],

        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@app/mail',
            'useFileTransport' => true,
        ],

        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                // всё как раньше в файл (можно оставить/убрать)
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
                // ВАЖНО: всё в stdout — видно в Railway → Logs
                [
                    'class' => yii\log\FileTarget::class,
                    'logFile' => 'php://stdout',
                    'levels' => ['error', 'warning', 'info'],
                    // без categories — ловим вообще все события
                    'logVars' => [], // не спамим суперглобалами
                ],
            ],
        ],

        'db' => $db,

        'user' => [
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => true,
            'loginUrl' => ['auth/login'],
            'identityCookie' => [
                'name' => '_identity',
                'httpOnly' => true,
                'secure' => YII_ENV_PROD,
                'sameSite' => 'Lax',
            ],
            'on ' . \yii\web\User::EVENT_AFTER_LOGIN => function ($e) {
                /** @var app\models\User $u */
                $u = $e->identity;
                $u->updateAttributes([
                    'ocr_allowance' => 50,
                    'ocr_allowance_updated_at' => time(),
                ]);
            },

        ],
        'urlManager' => [
            'enablePrettyUrl' => false,
            'showScriptName' => true,
        ],

        'ocr' => [
            'class' => \app\components\OcrClient::class,
        ],
        'ps' => [
            'class' => \app\components\PurchaseSessionService::class,
            'autocloseSeconds' => 10800, // 3 часа
        ],

        'formatter' => [
            'class' => yii\i18n\Formatter::class,
            'defaultTimeZone' => 'UTC',
            'timeZone' => 'UTC',
            'locale' => 'ru-RU',
            'currencyCode' => 'RUB',
            'thousandSeparator' => ' ',
            'decimalSeparator' => ',',
        ],

        'i18n' => [
            'translations' => [
                // Встроенные сообщения фреймворка (валидаторы, ошибки и т.п.)
                'yii*' => [
                    'class' => yii\i18n\PhpMessageSource::class,
                    'basePath' => '@yii/messages',
                    'sourceLanguage' => 'en-US',
                ],
                'app*' => [
                    'class' => yii\i18n\PhpMessageSource::class,
                    'basePath' => '@app/messages',
                    'fileMap' => [
                        'app' => 'app.php',
                        'app/error' => 'error.php',
                    ],
                ],
            ],
        ],

        'as timezone' => [
            'class' => app\components\TimezoneMiddleware::class,
        ],

        'name' => 'Тратометр',
        'defaultRoute' => 'site/index',
        'params' => $params,
    ]
];

if (YII_ENV_DEV && class_exists('yii\debug\Module')) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
    ];
}


return $config;
