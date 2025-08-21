<?php

use yii\db\Migration;

class m250821_115225_recreate_purchase_session_all extends Migration
{
    private function tableExists(string $table): bool {
        return $this->db->schema->getTableSchema($table, true) !== null;
    }
    private function columnExists(string $table, string $col): bool {
        $t = $this->db->schema->getTableSchema($table, true);
        return $t && $t->getColumn($col) !== null;
    }
    private function indexExists(string $table, string $name): bool {
        $dbName = $this->db->createCommand('SELECT DATABASE()')->queryScalar();
        $raw    = $this->db->schema->getRawTableName($table);
        return (new \yii\db\Query())
            ->from('information_schema.statistics')
            ->where([
                'table_schema' => $dbName,
                'table_name'   => $raw,
                'index_name'   => $name,
            ])->exists();
    }

    public function safeUp()
    {
        $ps = '{{%purchase_session}}';
        $pe = '{{%price_entry}}';

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        // 1) создаём purchase_session с нуля (данных нет — можно дропнуть, если вдруг осталась)
        if ($this->tableExists($ps)) {
            $this->dropTable($ps);
        }
        $this->createTable($ps, [
            'id'           => $this->primaryKey(),
            'user_id'      => $this->integer()->notNull(),
            'shop'         => $this->string(120)->notNull(),
            'category'     => $this->string(120)->notNull(),
            'limit_amount' => $this->integer()->null(),           // копейки
            'status'       => $this->tinyInteger()->notNull()->defaultValue(1), // 1=ACTIVE, 9=CLOSED
            'started_at'   => $this->integer()->notNull(),
            'updated_at'   => $this->integer()->notNull(),
            // кэш (в копейках)
            'total_amount' => $this->integer()->notNull()->defaultValue(0),
            'limit_left'   => $this->integer()->null(),
            'closed_at'    => $this->integer()->null(),
        ], $tableOptions);

        // индексы
        $this->createIndex('idx_ps_user_status',   $ps, ['user_id','status']);
        $this->createIndex('idx_ps_user_closed',   $ps, ['user_id','closed_at']);
        $this->createIndex('idx_ps_user_category', $ps, ['user_id','category']);
        $this->createIndex('idx_ps_status',        $ps, 'status');

        // 2) обеспечить поле/индекс в price_entry (не добавляем, если уже есть)
        if ($this->tableExists($pe)) {
            if (!$this->columnExists($pe, 'session_id')) {
                $this->addColumn($pe, 'session_id', $this->integer()->null()->after('user_id'));
            }
            if (!$this->indexExists($pe, 'idx_price_entry_session')) {
                $this->createIndex('idx_price_entry_session', $pe, ['session_id']);
            }
            // FK по желанию:
            // $this->addForeignKey('fk_price_entry_session', $pe, 'session_id', $ps, 'id', 'SET NULL', 'CASCADE');
        }
        // 3) инициализация кэша не нужна — данных нет
    }

    public function safeDown()
    {
        $pe = '{{%price_entry}}';
        $ps = '{{%purchase_session}}';

        if ($this->tableExists($pe)) {
            if ($this->indexExists($pe, 'idx_price_entry_session')) {
                $this->dropIndex('idx_price_entry_session', $pe);
            }
            if ($this->columnExists($pe, 'session_id')) {
                $this->dropColumn($pe, 'session_id');
            }
        }

        if ($this->tableExists($ps)) {
            $this->dropTable($ps);
        }
    }
}
