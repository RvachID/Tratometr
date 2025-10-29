
# Tratometr — наблюдения по проекту

## Технологический стек и инфраструктура
- Фреймворк: Yii2 (конфигурация в `config/web.php`), PHP 7.4+, MySQL.
- Структура по стандарту basic template: директории `controllers`, `models`, `views`, `components`, `config`.
- В `config/web.php` подключены компоненты: `ocr` (`app\components\OcrClient`), `ps` (`app\components\PurchaseSessionService`), `alice`, `formatter`, `cache`, `mailer`, `timezone` middleware.
- В `vendor/` есть зависимости (Composer), есть `migrations/`, `tests/`, `assets/`, `commands/`.

## Доменные модели
- `app\models\User` — сущность пользователя, реализует авторизацию и начисление OCR-квоты в `User::EVENT_AFTER_LOGIN`.
- `app\models\PurchaseSession` — покупательская сессия: поля `shop`, `category`, `limit_amount`, `total_amount`, `status`, `closed_at`. Содержит бизнес-метод `finalize()`, но логика также дублируется в сервисе `PurchaseSessionService`.
- `app\models\PriceEntry` — строка в чек-листе: `amount`, `qty`, `store`, `category`, `source`, `recognized_text/amount`. Перед сохранением устанавливает `created_at`, `updated_at`, рассчитывает `total`.

## Существующие компоненты (частичный бизнес-слой)
- `app\components\PurchaseSessionService` — управляет жизненным циклом сессий: активная сессия, begin, finalize, touch, расчёт суммы (через SQL). Сейчас контроллеры вызывают методы напрямую, но в них остаётся логика валидации/форматирования.
- `app\components\OcrClient` — работа с внешним OCR API (OCR.space), вызов `parseImage`, есть метод `extractPriceFromImage`.
- `app\components\TimezoneMiddleware` — проставляет временную зону.

## Контроллеры и ответственность
- `controllers/ScanController.php` (~800 строк): загружает изображения, вызывает OCR, содержит тяжёлую бизнес-логику:
  - `actionRecognize`: загрузка файла, препроцессинг (Imagick resize, normalize), OCR, fallback на собственные методы `recognizeText`, `extractAmountByOverlay`, `refinePriceFromCrop`, вычисление чисел, обработка ошибок.
  - `actionStore`, `actionUpdate`, `actionDelete`: CRUD по `PriceEntry` + пересчёт totals, привязка к активной сессии, валидация чисел, форматирование ответа.
  - Доп. приватные методы: `normalizeOcrNumber`, `extractAmount`, `preprocessImage`, `enforceSizeLimit`, `stripStrikethroughText` — чистая бизнес-логика распознавания и постобработки.
- `controllers/PriceController.php`: SPA API для ручного ввода.
  - `actionList`: выборка данных + форматирование.
  - `actionSave`: смешение бизнес-правил (привязка к active session, авто-источник, qty, totals).
  - `actionQty`: смена количества с разными режимами, пересчёт totals.
  - `actionDelete`, `actionGetLast`: бизнес-правила по удалению/выборке.
- `controllers/SiteController.php` (~400 строк): сценарии UI.
  - `actionBeginAjax`, `actionSessionStatus`, `actionCloseSession`: управление сессиями (валидации, парсинг лимитов, форматирование).
  - `actionHistory`: сложный запрос (join purchase_session + price_entry), подготовка данных для view.
  - `actionStats`/`actionStatsData`: бизнес-логика агрегаций, расчёт периодов с учётом time zone, сбор категорий.
  - `parseMoney`: парсинг строк в деньги.
- `controllers/AuthController.php`: авторизация/регистрация с собственными защитами (honeypot, rate-limit c использованием cache, backoff, фильтр disposable email, сохранение timezone).
- `controllers/LogController.php`: runtime-интерфейсы для логов, запуск миграций — инфраструктура.
- `controllers/SkillController.php`: минимальный вебхук, основная логика — проверка ключа и формирование JSON.
- `controllers/CameraController.php`: только render.

## Основные участки бизнес-логики в контроллерах
- Управление сессиями покупки (`SiteController`, `ScanController`, `PriceController`) — дублирование правил: поиск активной, закрытие, пересчёт totals, парсинг лимитов, каскадные обновления магазина/категории, хранение в сессии.
- Обработка результатов OCR (`ScanController`):
  - Предобработка изображений, ограничение размеров, ROI-детектирование, OCR c fallback.
  - Парсинг чисел (`normalizeOcrNumber`, `extractAmount`, `extractAmountByOverlay`, `refinePriceFromCrop`, `stripStrikethroughText`).
  - Перекладывание результата в `PriceEntry`.
- Статистика (`SiteController`): подготовка диапазонов дат в локальной таймзоне, группировка категорий, агрегации сумм, подготовка данных для диаграмм.
- Авторизация (`AuthController`): тонкий контроллер желателен — вынести rate limit/backoff, honeypot, обновление timezone в сервис.
- Работа с `PriceEntry` (`PriceController`, `ScanController`) — повторяющиеся запросы, форматирование totals.

## Предварительное видение бизнес-слоя
- Создать пространство имён `app\services`:
  - `OcrScanService` — загрузка/предобработка изображений, взаимодействие с `OcrClient`, извлечение суммы (перенос приватных методов из `ScanController`).
  - `PriceEntryService` — CRUD операций над `PriceEntry`, пересчёт totals, форматирование ответов, работа с активными сессиями (`PurchaseSessionService`).
  - `PurchaseSessionManager` — обёртка над существующим `PurchaseSessionService` + парсинг лимитов, хранение в сессии, подготовка DTO.
  - `StatsService` — агрегации по периодам и категориям.
  - `AuthSecurityService` — антибот-правила, rate-limit, Disposable email check, сохранение timezone.
- Внедрение через DI контейнер / компоненты Yii (`Yii::$app->container->set`, либо регистрация в `config/web.php`).
- Контроллеры становятся thin: валидация входных данных, вызов сервиса, подготовка HTTP-ответа.

## Потенциальные задачи при рефакторинге
- Перенести повторяющиеся запросы totals (`sum(amount*qty)`) в сервис.
- Унифицировать формат JSON-ответов (успех/ошибка).
- Обновить `config/web.php`: регистрация сервисов как компонентов (`scanService`, `priceService`, `sessionManager`, `statsService`, `authService`).
- Покрыть бизнес-слой тестами (при наличии инфраструктуры).
- Минимизировать дублирование `parseMoney` (одна реализация в бизнес-слое, использовать в контроллерах и сервисах).

## Проектирование бизнес-слоя

### 1. Сервисы и их обязанности
- `app\services\Scan\ScanService`
  - Методы `recognize(UploadedFile $image): RecognizeResult`, `extractFromPath(string $path): RecognizeResult`.
  - Инкапсулирует работу с `OcrClient`, препроцессинг изображений (Imagick), нормализацию чисел, fallback-алгоритмы (`extractAmountByOverlay`, `refinePriceFromCrop`, `stripStrikethroughText`).
  - Возвращает DTO (`RecognizeResult`) с полями `success`, `amount`, `error`, `reason`, `parsedText`, `pass`.
- `app\services\Price\PriceEntryService`
  - `createFromScan(int $userId, PurchaseSession $session, float $amount, float $qty, string $note, string $parsedText): PriceEntry`.
  - `saveManual(int $userId, PurchaseSession $session, PriceEntry $entry, array $data): PriceEntry`.
  - `changeQty(int $userId, int $entryId, string $op, $value): PriceEntry`.
  - `delete(int $userId, int $entryId): void`.
  - `getTotals(int $userId, ?int $sessionId = null): float`.
  - Выставляет `store/category` по сессии, проставляет `source`, `created_at`, `updated_at`, вызывает `PurchaseSessionService::touch`.
- `app\services\Purchase\SessionManager`
  - `begin(int $userId, string $store, ?string $category, ?string $limitRaw): PurchaseSession`.
  - `active(int $userId): ?PurchaseSession`, обёртка над `Yii::$app->ps`.
  - `closeActive(int $userId, string $reason = 'manual'): void`.
  - `statusDto(?PurchaseSession $session): array` (idle, limit, store, category).
  - Использует единый метод `parseMoney(string $raw): ?float`.
- `app\services\Stats\StatsService`
  - Методы `getHistory(int $userId, int $limit)`, `collectStats(int $userId, \DateTimeInterface $from, \DateTimeInterface $to, array $categories)`.
  - Возвращает структуры для `history` и `statsData`, учитывает таймзону через `DateTimeZone`.
- `app\services\Auth\AuthSecurityService`
  - `validateLoginAttempt(LoginData $input): AuthAttemptResult` — honeypot, too fast submit, rate limiting.
  - `validateSignup(SignupForm $form, AuthMeta $meta): SignupResult`.
  - `rememberTimezone(int $userId, ?string $tz): void`.
  - Работает с `Yii::$app->cache`, `Yii::$app->db`.

### 2. DTO/значимые объекты
- `app\services\Scan\RecognizeResult` — объект результата сканирования (amount, parsedText, pass, errors).
- `app\services\Auth\AuthAttemptResult`, `SignupResult` — для передачи сообщений/ошибок наружу.
- Возможный общий класс `ServiceResponse` (код успеха, сообщение, полезная нагрузка) для унификации ответов в контроллерах.

### 3. Интеграция с Yii
- Регистрация сервисов как singleton-компонентов в `config/web.php`, например:
  ```php
  'components' => [
      'scanService' => ['class' => app\services\Scan\ScanService::class],
      'priceService' => ['class' => app\services\Price\PriceEntryService::class],
      'sessionManager' => ['class' => app\services\Purchase\SessionManager::class],
      'statsService' => ['class' => app\services\Stats\StatsService::class],
      'authSecurity' => ['class' => app\services\Auth\AuthSecurityService::class],
  ]
  ```
- Используем DI-контейнер для зависимостей сервисов (`OcrClient`, `PurchaseSessionService`, `Formatter`).
- Контроллеры получают сервисы через `Yii::$app->get('scanService')` либо через внедрение в свойства.

### 4. План рефакторинга контроллеров
1. `ScanController`
   - Удалить приватные методы с OCR-логикой, заменить на вызовы `ScanService`.
   - `actionRecognize`: делегировать сервису, контроллер формирует HTTP-ответ.
   - `actionStore/actionUpdate/actionDelete`: использовать `PriceEntryService`.
2. `PriceController`
   - `actionList` -> метод `PriceEntryService::listForUser`.
   - `actionSave` -> `PriceEntryService::saveManual`.
   - `actionQty`/`actionDelete`/`actionGetLast` -> соответствующие методы сервиса.
3. `SiteController`
   - Методы сессий -> `SessionManager`, `StatsService`.
   - Удалить `parseMoney`, перенос в сервис.
4. `AuthController`
   - Делегировать rate limiting, honeypot и т.п. в `AuthSecurityService`.
5. Поддерживающие контроллеры (`LogController`, `SkillController`, `CameraController`) оставить без изменений.

### 5. Дополнительные соображения
- Потребуется обновление тестов/добавление новых (юнит для сервисов).
- Следует инкапсулировать работу с `Yii::$app` внутри сервисов по минимуму, оставив основную зависимость через конструкторы (упрощает тестирование).
- При переносе приватных методов из `ScanController` следует позаботиться о повторном использовании (например, `parseMoney` доступна и `ScanService`, и `SessionManager`).
