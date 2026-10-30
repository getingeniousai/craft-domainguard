<?php

namespace ingeniousai\domainguard\migrations;

use craft\db\Migration;

/**
 * Creates the plugin's scan table. One row per finished scan job:
 * completed scans carry the full gateway report (JSON); failed scans
 * carry only the error, so they can be displayed as failures but can
 * never feed the score card, the badge, or monitoring.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%domainguard_scans}}')) {
            $this->createTable('{{%domainguard_scans}}', [
                'id' => $this->primaryKey(),
                'domain' => $this->string(255)->notNull()->defaultValue(''),
                'score' => $this->smallInteger(),
                'report' => $this->longText(),
                'error' => $this->string(255),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->char(36)->notNull()->defaultValue(''),
            ]);
            $this->createIndex(null, '{{%domainguard_scans}}', ['dateCreated']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%domainguard_scans}}');
        return true;
    }
}
