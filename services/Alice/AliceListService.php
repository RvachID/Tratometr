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

        // 2. Добавление товаров
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
        if (!preg_match('~^добав(ь|ить)\b(.*)$~u', $command, $m)) {
            return [];
        }

        $tail = trim($m[2]);
        if ($tail === '') {
            return [];
        }

        // спец-режимы
        $separateMode = false;

        if (preg_match('~по\s+отдельности~u', $tail)) {
            $separateMode = true;
            $tail = preg_replace('~по\s+отдельности~u', ' ', $tail);
        }
        if (preg_match('~по\s+пунктам~u', $tail)) {
            $separateMode = true;
            $tail = preg_replace('~по\s+пунктам~u', ' ', $tail);
        }

        $tail = preg_replace('~\bв\s+список\b~u', ' ', $tail);
        $tail = trim(preg_replace('~\s+~u', ' ', $tail));

        if ($tail === '') {
            return [];
        }

        $added = [];

        // режим "по отдельности"
        if ($separateMode) {
            $words = preg_split('~\s+~u', $tail);
            $stopWords = ['и', 'в', 'во', 'на', 'к', 'по', 'с', 'со', 'список'];

            foreach ($words as $w) {
                $w = trim($w, " \t\n\r\0\x0B.,;");
                if ($w === '') continue;

                if (in_array(mb_strtolower($w, 'UTF-8'), $stopWords, true)) {
                    continue;
                }

                $added[] = $this->addItem($userId, $w);
            }

            return $added;
        }

        // обычный режим: "молоко и яйца"
        if (preg_match('~\s+и\s+~u', $tail)) {
            foreach (preg_split('~\s+и\s+~u', $tail) as $part) {
                $title = trim($part, " \t\n\r\0\x0B.,;");
                if ($title !== '') {
                    $added[] = $this->addItem($userId, $title);
                }
            }
            return $added;
        }

        return [$this->addItem($userId, $tail)];
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
}
