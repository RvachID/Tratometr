<?php

use yii\db\Migration;

class m250805_160335_add_tg_profile_fields_to_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'tg_username',  $this->string()->null());
        $this->addColumn('{{%user}}', 'first_name',   $this->string()->null());
        $this->addColumn('{{%user}}', 'last_name',    $this->string()->null());
        $this->addColumn('{{%user}}', 'language_code',$this->string(16)->null());
        $this->addColumn('{{%user}}', 'is_premium',   $this->boolean()->defaultValue(false));
        // по желанию: уникальный индекс на tg_username (его можно менять, поэтому обычно НЕ делаем UNIQUE)
        $this->createIndex('idx_user_tg_username', '{{%user}}', 'tg_username', false);
    }
    public function safeDown()
    {
        $this->dropIndex('idx_user_tg_username', '{{%user}}');
        $this->dropColumn('{{%user}}', 'is_premium');
        $this->dropColumn('{{%user}}', 'language_code');
        $this->dropColumn('{{%user}}', 'last_name');
        $this->dropColumn('{{%user}}', 'first_name');
        $this->dropColumn('{{%user}}', 'tg_username');
    }
}
