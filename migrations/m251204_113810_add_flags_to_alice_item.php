<?php

use yii\db\Migration;

class m251204_113810_add_flags_to_alice_item extends Migration
{
    public function safeUp()
    {
        $this->addColumn('alice_item', 'is_pinned', $this->boolean()->notNull()->defaultValue(0)->after('is_done'));
        $this->addColumn('alice_item', 'is_archived', $this->boolean()->notNull()->defaultValue(0)->after('is_pinned'));

        // Часто будем фильтровать по этим полям
        $this->createIndex(
            'idx_alice_item_user_flags',
            'alice_item',
            ['user_id', 'is_archived', 'is_done', 'is_pinned']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('idx_alice_item_user_flags', 'alice_item');
        $this->dropColumn('alice_item', 'is_archived');
        $this->dropColumn('alice_item', 'is_pinned');
    }
}
