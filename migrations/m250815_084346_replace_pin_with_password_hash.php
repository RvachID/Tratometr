<?php

use yii\db\Migration;

class m250815_084346_replace_pin_with_password_hash extends Migration
{
    public function safeUp()
    {
        // 1) Добавляем колонку под хеш пароля
        $this->addColumn('user', 'password_hash', $this->string()->notNull());

        // 2) Удаляем pin целиком
        $this->dropColumn('user', 'pin_code');

        // 3) (опционально) поле для аудита смены пароля
        if ($this->db->schema->getTableSchema('user')->getColumn('password_updated_at') === null) {
            $this->addColumn('user', 'password_updated_at', $this->integer()->notNull()->defaultValue(0));
        }

        // 4) Убедимся, что e-mail уникален (если уже есть — тихо пропустит)
        try { $this->createIndex('idx_user_email_unique', 'user', 'email', true); } catch (\Throwable $e) {}
    }

    public function safeDown()
    {
        // Вернуть pin (если вдруг откатываешь)
        $this->addColumn('user', 'pin_code', $this->string(6)->notNull());
        if ($this->db->schema->getTableSchema('user')->getColumn('password_updated_at')) {
            $this->dropColumn('user', 'password_updated_at');
        }
        $this->dropColumn('user', 'password_hash');
    }
}
