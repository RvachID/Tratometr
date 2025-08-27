<?php

use yii\db\Migration;

class m250827_124946_add_timezone_to_user extends Migration

{
    public function safeUp()
    {
        // IANA-таймзона вида "Europe/Belgrade"
        $this->addColumn('{{%user}}', 'timezone', $this->string(64)->null()->after('email'));
        // можно выставить дефолт уже существующим пользователям
        // $this->update('{{%user}}', ['timezone' => 'Europe/Moscow']); // опционально
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'timezone');
    }
}
