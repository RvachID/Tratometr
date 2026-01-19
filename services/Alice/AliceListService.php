<?php

namespace app\services\Alice;

use app\models\AliceItem;
use DomainException;

class AliceListService
{
    /* =========================================================
       PUBLIC API (используется контроллерами)
       ========================================================= */

    /**
     * Главная точка входа для навыка Алисы.
     * Возвращает текст ответа или null, если команда не распознана.
     */
    public function handleCommand(int $userId, string $command): ?string
    {
        $cmd = $this->normalizeCommand($command);
        if ($cmd === '') {
            return null;
        }

        // 1. Очистка списка
        if ($reply = $this->tryClearList($userId, $cmd)) {
            return $reply;
        }

        // 2. Показать список
        if ($reply = $this->tryShowList($userId, $cmd)) {
            return $reply;
        }

        // 3. Добавление товаров
        $added = $this->addFromCommand($userId, $cmd);
        if (!empty($added)) {
            $titles = array_map(fn($i) => $i->title, $added);
            $count  = count($this->getActiveList($userId));

            if ($count === 1 && count($titles) === 1) {
                return 'Добавила в список: ' . $titles[0] . '. В списке одна позиция.';
            }

            return 'Добавила в список: ' . implode(', ', $titles) . ". Сейчас в списке {$count} позиций.";
        }

        return null;
    }

    /**
     * Добавить один товар (используется и Алиской, и web-интерфейсом).
     */
    public function addItem(int $userId, string $title): AliceItem
    {
        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Пустое название товара');
        }

        // Проверка на дубликат в активном списке
        $existing = AliceItem::find()
            ->where([
                'user_id' => $userId,
                'is_done' => 0,
                'title'   => $title,
            ])
            ->one();

        if ($existing !== null) {
            return $existing;
        }

        $item = new AliceItem();
        $item->user_id = $userId;
        $item->title = mb_substr($title, 0, 255);
        $item->is_done = 0;
        $item->created_at = time();
        $item->updated_at = time();

        if (!$item->save()) {
            throw new DomainException('Не удалось сохранить товар');
        }

        return $item;
    }

    /**
     * Добавить несколько позиций из голосовой команды Алисы.
     */
    public function addFromCommand(int $userId, string $command): array
    {
        $items = $this->extractItemsFromAddCommand($command);
        if (empty($items)) {
            return [];
        }

        $added = [];

        foreach ($items as $title) {
            $added[] = $this->addItem($userId, $title);
        }

        return $added;
    }


    /**
     * Активный список (не купленные).
     */
    public function getActiveList(int $userId): array
    {
        return AliceItem::find()
            ->where(['user_id' => $userId, 'is_done' => 0])
            ->orderBy(['created_at' => SORT_ASC])
            ->all();
    }

    /**
     * Отметить все как купленные (кроме закреплённых).
     */
    public function completeAll(int $userId): int
    {
        return AliceItem::updateAll(
            ['is_done' => 1, 'updated_at' => time()],
            [
                'user_id'    => $userId,
                'is_done'    => 0,
                'is_archived'=> 0,
                'is_pinned'  => 0,
            ]
        );
    }

    /**
     * Используется кнопкой "Обнулить расходники".
     */
    public static function resetPinnedDoneItems(): int
    {
        return AliceItem::updateAll(
            ['is_done' => 0],
            ['is_done' => 1, 'is_pinned' => 1]
        );
    }

    /**
     * Для dropdown на экране сканирования.
     */
    public function getForDropdown(int $userId): array
    {
        return AliceItem::find()
            ->where([
                'user_id'     => $userId,
                'is_archived' => 0,
                'is_done'     => 0,
            ])
            ->orderBy([
                'is_pinned' => SORT_DESC,
                'title'     => SORT_ASC,
            ])
            ->all();
    }

    public function updateItem(int $userId, int $id, string $title): AliceItem
    {
        $item = $this->requireOwned($userId, $id);

        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Пустое название товара');
        }

        $item->title = mb_substr($title, 0, 255);
        $item->updated_at = time();

        if (!$item->save()) {
            throw new DomainException('Не удалось обновить товар');
        }

        return $item;
    }

    public function deleteItem(int $userId, int $id): void
    {
        $this->requireOwned($userId, $id)->delete();
    }

    public function toggleDone(int $userId, int $id): AliceItem
    {
        $item = $this->requireOwned($userId, $id);
        $item->is_done = $item->is_done ? 0 : 1;
        $item->updated_at = time();
        $item->save(false);
        return $item;
    }

    public function togglePinned(int $userId, int $id): AliceItem
    {
        $item = $this->requireOwned($userId, $id);
        $item->is_pinned = $item->is_pinned ? 0 : 1;
        $item->updated_at = time();
        $item->save(false);
        return $item;
    }

    public function getAll(int $userId): array
    {
        return AliceItem::find()
            ->where(['user_id' => $userId])
            ->orderBy([
                'is_archived' => SORT_ASC,
                'is_done'     => SORT_ASC,
                'is_pinned'   => SORT_DESC,
                'created_at'  => SORT_ASC,
            ])
            ->all();
    }

    /* =========================================================
       INTERNAL HELPERS (Алиса)
       ========================================================= */

    private function normalizeCommand(string $command): string
    {
        $cmd = mb_strtolower($command, 'utf-8');

        $cmd = preg_replace(
            '~\b(алиса|яндекс|слушай|пожалуйста|плиз|ok|ок)\b~u',
            '',
            $cmd
        );

        $cmd = trim($cmd, " \t\n\r\0\x0B.!?,");
        return preg_replace('~\s+~u', ' ', $cmd);
    }

    private function tryClearList(int $userId, string $cmd): ?string
    {
        $pattern = '~\b(
            очист(и|ить|ка|и всё)? |
            удал(и|ить|яй)? |
            сброс(ь|ить)? |
            убер(и|ать)? |
            отметь.*куплен |
            всё\s+куплен
        )\b
        .*\b(
            список |
            всё |
            все |
            покупки
        )\b~ux';

        if (!preg_match($pattern, $cmd)) {
            return null;
        }

        $affected = $this->completeAll($userId);

        if ($affected === 0) {
            return 'В списке уже нет активных покупок.';
        }

        return "Отметила как купленные {$affected} позиций. Список очищен.";
    }

    private function requireOwned(int $userId, int $id): AliceItem
    {
        $item = AliceItem::findOne(['id' => $id, 'user_id' => $userId]);
        if (!$item) {
            throw new DomainException('Пункт списка не найден');
        }
        return $item;
    }

    private function tryShowList(int $userId, string $cmd): ?string
    {
        // "что в списке", "покажи список", "что купить", "список покупок"
        if (
            !preg_match(
                '~\b(что|покаж(и|и)|какой|какие)\b.*\b(списк\w*|покупк\w*|купить)\b~u',
                $cmd
            )
            && !preg_match(
                '~^списк\w*(\s+покупк\w*)?$~u',
                $cmd
            )
        ) {
            return null;
        }

        $items = $this->getActiveList($userId);

        if (empty($items)) {
            return 'Список покупок пуст.';
        }

        $titles = array_map(fn($i) => $i->title, $items);

        // Алисе комфортно слушать до ~7 пунктов
        $short = array_slice($titles, 0, 7);
        $text  = implode(', ', $short);

        if (count($titles) > count($short)) {
            $rest = count($titles) - count($short);
            $text .= " и ещё {$rest}";
        }

        return 'В списке: ' . $text . '.';
    }

    private function extractItemsFromAddCommand(string $command): array
    {
        $cmd = mb_strtolower($command, 'UTF-8');
        $cmd = trim($cmd);

        // === 1. Убираем стартовые служебные фразы ===
        $serviceStarts = [
            'добавь в список покупок',
            'добавить в список покупок',
            'добавь в список',
            'добавить в список',
            'добавь',
            'добавить',
            'добавь добавь',
            'добавить добавить',
        ];

        foreach ($serviceStarts as $start) {
            if (mb_strpos($cmd, $start) === 0) {
                $cmd = trim(mb_substr($cmd, mb_strlen($start)));
                break;
            }
        }

        // === 2. Убираем хвостовые служебные фразы ===
        $serviceTails = [
            'в список покупок',
            'в список',
        ];

        foreach ($serviceTails as $tail) {
            $cmd = preg_replace('~\b' . preg_quote($tail, '~') . '\b~u', '', $cmd);
        }

        // === 3. Нормализуем разделители товаров ===
        $separators = [
            ' и ещё ',
            ' и еще ',
            ' ещё ',
            ' еще ',
            ' и плюсом ',
            ' и плюс ',
            ' плюсом ',
            ' плюс ',
            ' и ',
            ',',
            ';',
        ];

        foreach ($separators as $sep) {
            $cmd = str_replace($sep, '|', $cmd);
        }

        // === 4. Финальная чистка ===
        $cmd = trim($cmd, " \t\n\r\0\x0B.|!?");

        if ($cmd === '') {
            return [];
        }

        // === 5. Разбиваем на товары ===
        $parts = array_map(
            fn($p) => trim($p, " \t\n\r\0\x0B.!?"),
            explode('|', $cmd)
        );

        // === 6. Убираем пустые и дубли ===
        $parts = array_filter($parts, fn($p) => $p !== '');
        $parts = array_values(array_unique($parts));

        return $parts;
    }

}
