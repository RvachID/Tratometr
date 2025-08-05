<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            // !!! не забывай хранить секрет в окружении/секрете
            'cookieValidationKey' => 'JnrKGc4dsJmo_uU1hCj-k7W2Ettg3Y8A',
            // чтобы Yii умел принимать JSON в $_POST (удобно для /auth/tg-login)
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],

        // важно для Telegram webview: cookie сессии должны быть SameSite=None; Secure
        'session' => [
            'cookieParams' => [
                'sameSite' => 'None',
                'secure'   => true,
            ],
        ],

        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],

        'user' => [
            'identityClass' => \app\models\User::class,
            'enableAutoLogin' => true,
            'loginUrl' => null, // не редиректим на форму логина — авторизация через Telegram
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
                // как было: в файл (можно оставить)
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
                // НОВОЕ: в stdout — видно в Railway Logs
                [
                    'class' => yii\log\FileTarget::class,
                    'logFile' => 'php://stdout',
                    'levels' => ['error', 'warning', 'info'],
                    'categories' => ['tgLogin', 'webhook', 'app'], // наши категории
                    'logVars' => [], // не засоряем окружением
                ],
            ],
        ],

        'db' => $db,

        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'webhook'         => 'webhook/index',
                // мини‑апп и авторизация через Telegram
                'site/app'        => 'site/app',
                'auth/tg-login'   => 'auth/tg-login',
                'auth/profile'    => 'auth/profile',
            ],
        ],
    ],
    'defaultRoute' => 'site/app',
    'params' => $params,
];

if (YII_ENV_DEV && class_exists('yii\debug\Module')) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
    ];
}


return $config;
