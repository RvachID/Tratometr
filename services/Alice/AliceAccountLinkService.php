<?php

namespace app\services\Alice;

use app\models\AliceUserLink;
use DomainException;

class AliceAccountLinkService
{
    private const CODE_TTL_SECONDS = 600;

    public function findUserIdByApplicationId(?string $applicationId): ?int
    {
        $applicationId = $this->normalizeApplicationId($applicationId);
        if ($applicationId === null) {
            return null;
        }

        $link = AliceUserLink::find()
            ->where(['application_id' => $applicationId])
            ->andWhere(['not', ['user_id' => null]])
            ->one();

        return $link ? (int)$link->user_id : null;
    }

    public function createCode(string $applicationId): string
    {
        $applicationId = $this->normalizeApplicationId($applicationId);
        if ($applicationId === null) {
            throw new DomainException('Не удалось получить идентификатор приложения Алисы');
        }

        $code = (string)random_int(100000, 999999);
        $now = time();

        $link = AliceUserLink::findOne(['application_id' => $applicationId]) ?: new AliceUserLink();
        if ($link->isNewRecord) {
            $link->application_id = $applicationId;
            $link->created_at = $now;
        }

        $link->link_code_hash = $this->hashCode($code);
        $link->code_expires_at = $now + self::CODE_TTL_SECONDS;
        $link->updated_at = $now;

        if (!$link->save()) {
            throw new DomainException('Не удалось создать код привязки Алисы');
        }

        return $code;
    }

    public function claimCode(int $userId, string $code): AliceUserLink
    {
        $code = $this->normalizeCode($code);
        if ($code === null) {
            throw new DomainException('Введите код из шести цифр');
        }

        $link = AliceUserLink::find()
            ->where(['link_code_hash' => $this->hashCode($code)])
            ->andWhere(['>=', 'code_expires_at', time()])
            ->one();

        if (!$link) {
            throw new DomainException('Код не найден или уже истек');
        }

        if ($link->user_id !== null && (int)$link->user_id !== $userId) {
            throw new DomainException('Этот аккаунт Алисы уже привязан к другому пользователю');
        }

        $link->user_id = $userId;
        $link->link_code_hash = null;
        $link->code_expires_at = null;
        $link->updated_at = time();

        if (!$link->save()) {
            throw new DomainException('Не удалось привязать Алису');
        }

        return $link;
    }

    public function getLinkForUser(int $userId): ?AliceUserLink
    {
        return AliceUserLink::findOne(['user_id' => $userId]);
    }

    public function unlinkUser(int $userId): void
    {
        AliceUserLink::deleteAll(['user_id' => $userId]);
    }

    private function normalizeApplicationId(?string $applicationId): ?string
    {
        $applicationId = trim((string)$applicationId);
        if ($applicationId === '') {
            return null;
        }

        return mb_substr($applicationId, 0, 128);
    }

    private function normalizeCode(string $code): ?string
    {
        $code = preg_replace('~\D+~', '', $code);
        return preg_match('~^\d{6}$~', $code) ? $code : null;
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }
}
