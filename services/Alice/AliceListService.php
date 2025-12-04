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
        // вытаскиваем всё после "добавь/добавить"
        if (!preg_match('~^добав(ь|ить)\b(.*)$~u', $command, $m)) {
            return [];
        }

        $tail = trim($m[2]);
        if ($tail === '') {
            return [];
        }

        // убираем "в список" в начале, если есть
        $tail = preg_replace('~^в\s+список\s+~u', '', $tail);

        // нормализуем разделители:
        // "молоко и яйца и краску" -> "молоко, яйца, краску"
        $tail = preg_replace('~\s+и\s+~u', ', ', $tail);

        // если в итоге нет ни одной запятой — считем это одним товаром
        // (чтобы не порезать "масло 2 литра" и т.п.)
        if (mb_strpos($tail, ',') === false) {
            return [$this->addItem($userId, $tail)];
        }

        // режем по запятым
        $parts = preg_split('~\s*,\s*~u', $tail);
        $added = [];

        foreach ($parts as $part) {
            $title = trim($part, " \t\n\r\0\x0B.,;");
            if ($title === '') {
                continue;
            }

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
     * Отметить все как купленные (для команды «очисти список»).
     */
    public function completeAll(int $userId): int
    {
        return AliceItem::updateAll(
            ['is_done' => 1, 'updated_at' => time()],
            ['user_id' => $userId, 'is_done' => 0]
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
}

