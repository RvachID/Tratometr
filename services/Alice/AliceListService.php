<?php

namespace app\services\Alice;

use app\models\AliceItem;
use DomainException;

class AliceListService
{
    public function addItem(int $userId, string $title): AliceItem
    {
        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Пустое название товара');
        }

        // --- проверка на дубликаты в активном списке ---
        $existing = AliceItem::find()
            ->where([
                'user_id' => $userId,
                'is_done' => 0,
                'title' => $title,   // с учётом коллации БД обычно и так case-insensitive
            ])
            ->one();

        if ($existing !== null) {
            // уже есть в активном списке – ничего не добавляем
            return $existing;
        }
        // -----------------------------------------------

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
     * Например: "добавь хлеб, яйца, муку и красный перец".
     *
     * @return AliceItem[]
     */
    public function addFromCommand(int $userId, string $command): array
    {
        // Забираем хвост после "добавь/добавить"
        if (!preg_match('~^добав(ь|ить)\b(.*)$~u', $command, $m)) {
            return [];
        }

        $tail = trim($m[2]);
        if ($tail === '') {
            return [];
        }

        // --- Определяем спец-режим: "по отдельности"/"по пунктам" ---
        $separateMode = false;

        if (preg_match('~по\s+отдельности~u', $tail)) {
            $separateMode = true;
            $tail = preg_replace('~по\s+отдельности~u', ' ', $tail);
        }
        if (preg_match('~по\s+пунктам~u', $tail)) {
            $separateMode = true;
            $tail = preg_replace('~по\s+пунктам~u', ' ', $tail);
        }

        // Убираем "в список" где бы ни встретилось
        $tail = preg_replace('~\bв\s+список\b~u', ' ', $tail);
        $tail = trim(preg_replace('~\s+~u', ' ', $tail));

        if ($tail === '') {
            return [];
        }

        $added = [];

        // ===== РЕЖИМ "ПО ОТДЕЛЬНОСТИ / ПО ПУНКТАМ" =====
        if ($separateMode) {
            // Каждое слово (кроме стоп-слов) — отдельная позиция
            $words = preg_split('~\s+~u', $tail);
            $stopWords = ['и', 'в', 'во', 'на', 'к', 'по', 'с', 'со', 'список'];

            foreach ($words as $w) {
                $w = trim($w, " \t\n\r\0\x0B.,;");
                if ($w === '') {
                    continue;
                }

                $lw = mb_strtolower($w, 'UTF-8');
                if (in_array($lw, $stopWords, true)) {
                    continue;
                }

                $added[] = $this->addItem($userId, $w);
            }

            return $added;
        }

        // ===== ОБЫЧНЫЙ РЕЖИМ "ДОБАВЬ МОЛОКО И ЯЙЦА" =====

        // "молоко и яйца и краску" → делим по "и"
        if (preg_match('~\s+и\s+~u', $tail)) {
            $parts = preg_split('~\s+и\s+~u', $tail);
            foreach ($parts as $part) {
                $title = trim($part, " \t\n\r\0\x0B.,;");
                if ($title === '') {
                    continue;
                }
                $added[] = $this->addItem($userId, $title);
            }
            return $added;
        }

        // Без "и" — считаем всё хвостом одного товара
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
     * Отметить все как купленные (для команды «очисти список»).
     */
    public function completeAll(int $userId): int
    {
        return AliceItem::updateAll(
            ['is_done' => 1, 'updated_at' => time()],
            [
                'user_id'    => $userId,
                'is_done'    => 0,
                'is_archived'=> 0,
                'is_pinned'  => 0, // закреплённых не трогаем
            ]
        );
    }


    /**
     * Разобрать текст команды на отдельные пункты списка.
     */
    private function extractItemsFromCommand(string $command): array
    {
        $cmd = mb_strtolower(trim($command));

        // срезаем "добавь", "добавить", "добавь в список/покупки"
        $cmd = preg_replace('~^(добав(ь|ить)( в (список|покупки))?\s+)~u', '', $cmd);
        $cmd = trim($cmd);

        if ($cmd === '') {
            return [];
        }

        // режем по запятым / ;  — основной разделитель
        $parts = preg_split('~[,;]+~u', $cmd);

        $items = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // если запятых несколько, внутри куска можно ещё порезать по " и "
            if (preg_match('~\sи\s~u', $part) && count($parts) > 1) {
                $subParts = preg_split('~\sи\s~u', $part);
                foreach ($subParts as $sub) {
                    $sub = trim($sub, " \t\n\r\0\x0B,.!?");
                    if ($sub !== '') {
                        $items[] = $sub;
                    }
                }
            } else {
                $part = trim($part, " \t\n\r\0\x0B,.!?");
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        // убираем дубли
        $items = array_values(array_unique($items));

        return $items;
    }

    /**
     * Список для выпадающего списка на странице скана.
     * Логика:
     *  - не архивные
     *  - либо ещё не куплены, либо закреплённые
     */
    public function getForDropdown(int $userId): array
    {
        $items = AliceItem::find()
            ->where([
                'user_id'     => $userId,
                'is_archived' => 0,
                'is_done'     => 0,
            ])
            ->orderBy([
                'is_pinned' => SORT_DESC,  // закреплённые выше
                'title'     => SORT_ASC,
            ])
            ->all();

        return $items;
    }

    private function requireOwned(int $userId, int $id): AliceItem
    {
        $item = AliceItem::findOne([
            'id' => $id,
            'user_id' => $userId,
        ]);

        if (!$item) {
            throw new DomainException('Пункт списка не найден');
        }

        return $item;
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
        $item = $this->requireOwned($userId, $id);
        $item->delete();
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

}

