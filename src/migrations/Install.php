<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%quickchat_conversations}}', [
            'id' => $this->primaryKey(),
            'token' => $this->char(32)->notNull(),
            'siteId' => $this->integer(),
            'email' => $this->string(),
            'pageUrl' => $this->string(500),
            'status' => $this->string(10)->notNull()->defaultValue('open'),
            'lastVisitorAt' => $this->dateTime(),
            'agentReadAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%quickchat_conversations}}', ['token'], true);
        $this->createIndex(null, '{{%quickchat_conversations}}', ['status', 'dateUpdated']);
        $this->addForeignKey(null, '{{%quickchat_conversations}}', ['siteId'], '{{%sites}}', ['id'], 'SET NULL');

        $this->createTable('{{%quickchat_messages}}', [
            'id' => $this->primaryKey(),
            'conversationId' => $this->integer()->notNull(),
            'fromAgent' => $this->boolean()->notNull()->defaultValue(false),
            'userId' => $this->integer(),
            'body' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%quickchat_messages}}', ['conversationId', 'id']);
        $this->addForeignKey(null, '{{%quickchat_messages}}', ['conversationId'], '{{%quickchat_conversations}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%quickchat_messages}}', ['userId'], '{{%users}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%quickchat_messages}}');
        $this->dropTableIfExists('{{%quickchat_conversations}}');
        return true;
    }
}
