<?php

namespace app\services\Price;

use app\components\PurchaseSessionService;
use app\models\PriceEntry;
use app\models\PurchaseSession;
use DomainException;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Сервис работы со списком покупок.
 */
class PriceEntryService
{
    private PurchaseSessionService $sessionService;

    public function __construct(?PurchaseSessionService $sessionService = null)
    {
        $this->sessionService = $sessionService ?? Yii::$app->ps;
    }

    /**
     * Возвращает список записей пользователя и агрегированную сумму.
     */
    public function getList(int $userId, int $offset, int $limit): array
    {
        $query = PriceEntry::find()
            ->where(['user_id' => $userId])
            ->orderBy(['created_at' => SORT_DESC]);

        $items = $query->offset($offset)->limit($limit)->all();

        $total = (float)$this->cloneQuery($query)->sum('amount * qty');
        $count = (int)$this->cloneQuery($query)->count();

        return [
            'items' => $items,
            'totalAmount' => $total,
            'hasMore' => $count > ($offset + $limit),
        ];
    }

    public function createFromScan(
        int $userId,
        PurchaseSession $session,
        float $amount,
        float $qty,
        ?string $note,
        ?string $parsedText
    ): array {
        if ($amount <= 0) {
            throw new DomainException('Некорректная сумма');
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }

        $entry = new PriceEntry();
        $entry->user_id = $userId;
        $entry->session_id = $session->id;
        $entry->amount = round($amount, 2);
        $entry->qty = round($qty, 3);
        $entry->store = $session->shop;
        $entry->category = $session->category ?: null;
        $entry->note = $note ?: null;
        $entry->recognized_text = $parsedText ?: null;
        $entry->recognized_amount = $entry->amount;
        $entry->source = 'price_tag';
        $entry->created_at = time();
        $entry->updated_at = time();

        if (!$entry->save(false)) {
            throw new DomainException('Не удалось сохранить запись');
        }

        $this->sessionService->touch($session);

        $listTotal = $this->getUserTotal($userId, $session->id);

        return [
            'entry' => $entry,
            'listTotal' => $listTotal,
        ];
    }

    public function saveManual(int $userId, PurchaseSession $session, PriceEntry $entry, array $data): array
    {
        $entry->load($data, '');
        $entry->user_id = $userId;
        $entry->session_id = $session->id;
        $entry->store = $session->shop;
        $entry->category = $session->category ?: null;

        if ($entry->isNewRecord) {
            $entry->source = $entry->source ?: 'manual';
            $entry->qty = $entry->qty ?: 1;
            $entry->created_at = $entry->created_at ?: time();
        }

        if (!$entry->validate()) {
            $first = current($entry->firstErrors);
            throw new DomainException($first ?: 'Ошибка валидации');
        }

        $entry->save(false);
        $this->sessionService->touch($session);

        $total = $this->getUserTotal($userId, $session->id);

        return [
            'entry' => $entry,
            'sessionTotal' => $total,
        ];
    }

    public function updateFromScan(int $userId, PurchaseSession $session, int $entryId, array $data): array
    {
        $entry = PriceEntry::findOne([
            'id' => $entryId,
            'user_id' => $userId,
            'session_id' => $session->id,
        ]);
        if (!$entry) {
            throw new DomainException('Запись не найдена');
        }

        $entry->load($data, '');
        $entry->user_id = $userId;
        $entry->session_id = $session->id;
        $entry->store = $session->shop;
        $entry->category = $session->category ?: null;
        $entry->updated_at = time();

        if (!$entry->save(false)) {
            throw new DomainException('Не удалось обновить запись');
        }

        $this->sessionService->touch($session);

        $total = $this->getUserTotal($userId, $session->id);

        return [
            'entry' => $entry,
            'sessionTotal' => $total,
        ];
    }

    public function deleteFromSession(int $userId, PurchaseSession $session, int $entryId): float
    {
        $entry = PriceEntry::findOne([
            'id' => $entryId,
            'user_id' => $userId,
            'session_id' => $session->id,
        ]);
        if (!$entry) {
            throw new DomainException('Запись не найдена');
        }

        $entry->delete();
        $this->sessionService->touch($session);

        return $this->getUserTotal($userId, $session->id);
    }

    public function adjustQuantity(int $userId, int $entryId, string $op, $value): array
    {
        $entry = PriceEntry::findOne([
            'id' => $entryId,
            'user_id' => $userId,
        ]);
        if (!$entry) {
            throw new DomainException('Запись не найдена');
        }

        if ($op === 'inc') {
            $entry->qty += 1;
        } elseif ($op === 'dec') {
            $entry->qty = max(0.001, $entry->qty - 1);
        } elseif ($op === 'set') {
            $entry->qty = max(0.001, (float)$value);
        }

        $entry->save(false);

        $listTotal = $this->getUserTotal($userId);

        return [
            'entry' => $entry,
            'listTotal' => $listTotal,
        ];
    }

    public function delete(int $userId, int $entryId): float
    {
        $entry = PriceEntry::findOne([
            'id' => $entryId,
            'user_id' => $userId,
        ]);
        if (!$entry) {
            throw new DomainException('Запись не найдена');
        }

        $sessionId = $entry->session_id ?: null;
        $entry->delete();

        return $this->getUserTotal($userId, $sessionId);
    }

    public function getLast(int $userId): ?array
    {
        $row = (new Query())
            ->from('price_entry')
            ->where(['user_id' => $userId])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$row) {
            return null;
        }

        return [
            'price' => $row['recognized_amount'] ?: $row['amount'],
            'text' => $row['recognized_text'],
        ];
    }

    public function getUserTotal(int $userId, ?int $sessionId = null): float
    {
        $query = PriceEntry::find()
            ->where(['user_id' => $userId]);
        if ($sessionId !== null) {
            $query->andWhere(['session_id' => $sessionId]);
        }

        return (float)$query->sum('amount * qty');
    }

    private function cloneQuery(ActiveQuery $query): ActiveQuery
    {
        return clone $query;
    }
}
