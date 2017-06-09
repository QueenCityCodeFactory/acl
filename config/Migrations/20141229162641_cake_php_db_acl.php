<?php

use Phinx\Migration\AbstractMigration;

class CakePhpDbAcl extends AbstractMigration
{

    /**
     * Migrate Up.
     */
    public function up()
    {
        $table = $this->table('acos');
        $table
            ->addColumn('parent_id', 'integer', ['null' => true])
            ->addColumn('model', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('foreign_key', 'integer', ['null' => true])
            ->addColumn('alias', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lft', 'integer', ['null' => true])
            ->addColumn('rght', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('node_type', 'string', ['limit' => 25, 'null' => true])
            ->addColumn('hidden', 'boolean', ['default' => 0, 'null' => false])
            ->addIndex(['alias'])
            ->addIndex(['lft'])
            ->addIndex(['rght'])
            ->create();

        $table = $this->table('aros');
        $table
            ->addColumn('parent_id', 'integer', ['null' => true])
            ->addColumn('model', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('foreign_key', 'integer', ['null' => true])
            ->addColumn('alias', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lft', 'integer', ['null' => true])
            ->addColumn('rght', 'integer', ['null' => true])
            ->addColumn('hidden', 'boolean', ['default' => 0, 'null' => false])
            ->addIndex(['alias'])
            ->addIndex(['lft'])
            ->addIndex(['rght'])
            ->create();

        $table = $this->table('aros_acos');
        $table
            ->addColumn('aro_id', 'integer', ['null' => true])
            ->addColumn('aco_id', 'integer', ['null' => true])
            ->addColumn('_create', 'string', ['default' => 0, 'limit' => 2, 'null' => false])
            ->addColumn('_read', 'string', ['default' => 0, 'limit' => 2, 'null' => false])
            ->addColumn('_update', 'string', ['default' => 0, 'limit' => 2, 'null' => false])
            ->addColumn('_delete', 'string', [ 'default' => 0, 'limit' => 2, 'null' => false])
            ->addIndex(['aro_id', 'aco_id'], ['unique' => true])
            ->addIndex(['aco_id'])
            ->addIndex(['aro_id'])
            ->create();
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $this->dropTable('aros_acos');
        $this->dropTable('aros');
        $this->dropTable('acos');
    }
}
