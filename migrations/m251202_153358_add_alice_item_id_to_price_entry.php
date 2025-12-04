<?php

use yii\db\Migration;

class m251202_153358_add_alice_item_id_to_price_entry extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('price_entry', 'alice_item_id', $this->integer()->null());
        $this->createIndex('idx_price_entry_alice_item', 'price_entry', 'alice_item_id');
    }

    public function safeDown()
    {
        $this->dropIndex('idx_price_entry_alice_item', 'price_entry');
        $this->dropColumn('price_entry', 'alice_item_id');
    }

}
