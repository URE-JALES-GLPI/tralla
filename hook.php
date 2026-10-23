<?php
/**
 * hook.php — instalação, atualização e desinstalação do [URE] Tralla.
 */

/**
 * Cria o schema e os dados padrão. Idempotente: pode rodar em updates.
 */
function plugin_tralla_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_tralla_boards')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_boards` (
                `id`           INT {$sign} NOT NULL AUTO_INCREMENT,
                `name`         VARCHAR(255) NOT NULL DEFAULT '',
                `type`         VARCHAR(20)  NOT NULL DEFAULT 'normal' COMMENT 'normal|maintenance',
                `color`        VARCHAR(20)  NOT NULL DEFAULT '#0079bf',
                `background`   VARCHAR(40)  NOT NULL DEFAULT 'ocean',
                `comment`      TEXT         DEFAULT NULL,
                `entities_id`  INT {$sign} NOT NULL DEFAULT '0',
                `is_recursive` TINYINT(1)   NOT NULL DEFAULT '0',
                `is_archived`  TINYINT(1)   NOT NULL DEFAULT '0',
                `users_id`     INT {$sign} NOT NULL DEFAULT '0',
                `date_creation` DATETIME    DEFAULT NULL,
                `date_mod`      DATETIME    DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `type` (`type`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_boards_users')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_boards_users` (
                `id`                      INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_tralla_boards_id` INT {$sign} NOT NULL DEFAULT '0',
                `users_id`                INT {$sign} NOT NULL DEFAULT '0',
                `role`                    VARCHAR(20)  NOT NULL DEFAULT '',
                `date_creation`           DATETIME     DEFAULT NULL,
                `date_mod`                DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_board_user` (`plugin_tralla_boards_id`, `users_id`),
                KEY `users_id` (`users_id`),
                KEY `role` (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_lists')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_lists` (
                `id`                       INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_tralla_boards_id`  INT {$sign} NOT NULL DEFAULT '0',
                `name`                     VARCHAR(255) NOT NULL DEFAULT '',
                `rank`                     DOUBLE       NOT NULL DEFAULT '0',
                `category`                 VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'categoria de manutenção; vazio = normal',
                `color`                    VARCHAR(20)  DEFAULT NULL,
                `is_archived`              TINYINT(1)   NOT NULL DEFAULT '0',
                `users_id`                 INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`            DATETIME     DEFAULT NULL,
                `date_mod`                 DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_tralla_boards_id` (`plugin_tralla_boards_id`),
                KEY `category` (`category`),
                KEY `rank` (`rank`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    $cards_table_existed = $DB->tableExists('glpi_plugin_tralla_cards');
    if (!$cards_table_existed) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_cards` (
                `id`                      INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_tralla_boards_id` INT {$sign} NOT NULL DEFAULT '0',
                `plugin_tralla_lists_id`  INT {$sign} NOT NULL DEFAULT '0',
                `name`                    VARCHAR(255) NOT NULL DEFAULT '',
                `description`             LONGTEXT     DEFAULT NULL,
                `rank`                    DOUBLE       NOT NULL DEFAULT '0',
                `users_id`                INT {$sign} NOT NULL DEFAULT '0',
                `technician_id`           INT {$sign} NOT NULL DEFAULT '0',
                `stage`                   TINYINT(1)   NOT NULL DEFAULT '1' COMMENT '1=manutenção, 2=aprovação',
                `parent_cards_id`         INT {$sign} NOT NULL DEFAULT '0' COMMENT 'card /1 de origem quando stage=2',
                `approval_cards_id`       INT {$sign} NOT NULL DEFAULT '0' COMMENT 'card /2 gerado pelo /1',
                `status`                  VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'normal,pending,in_progress,waiting_approval,withdrawal,done,approved,rejected,deletion_pending,deleted',
                `is_locked`               TINYINT(1)   NOT NULL DEFAULT '0',
                `is_archived`             TINYINT(1)   NOT NULL DEFAULT '0',
                `source`                  VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'notebook_desktop|tablet_mobile|manual',
                `machines_total`          INT {$sign} NOT NULL DEFAULT '0',
                `date_taken`              DATETIME     DEFAULT NULL,
                `date_finalized`          DATETIME     DEFAULT NULL,
                `date_approved`           DATETIME     DEFAULT NULL,
                `date_withdrawn`          DATETIME     DEFAULT NULL,
                `pending_notified`        TINYINT(1)   NOT NULL DEFAULT '0',
                `withdrawal_reminded_at`  DATETIME     DEFAULT NULL,
                `assignature_sent`        TINYINT(1)   NOT NULL DEFAULT '0',
                `rejection_reason`        TEXT         DEFAULT NULL,
                `users_id_approver`       INT {$sign} NOT NULL DEFAULT '0',
                `is_deleted`              TINYINT(1)   NOT NULL DEFAULT 0,
                `approval_kind`           VARCHAR(20)  NOT NULL DEFAULT 'maintenance' COMMENT 'maintenance|deletion',
                `deletion_reason`         TEXT         DEFAULT NULL,
                `deletion_requested_at`   DATETIME     DEFAULT NULL,
                `deletion_requested_by`   INT {$sign} NOT NULL DEFAULT 0,
                `deletion_decided_at`     DATETIME     DEFAULT NULL,
                `deletion_decided_by`     INT {$sign} NOT NULL DEFAULT 0,
                `deletion_previous_lists_id` INT {$sign} NOT NULL DEFAULT 0,
                `deletion_previous_status`   VARCHAR(30)  NOT NULL DEFAULT '',
                `deletion_previous_is_locked` TINYINT(1)  NOT NULL DEFAULT 0,
                `date_creation`           DATETIME     DEFAULT NULL,
                `date_mod`                DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_tralla_boards_id` (`plugin_tralla_boards_id`),
                KEY `plugin_tralla_lists_id` (`plugin_tralla_lists_id`),
                KEY `parent_cards_id` (`parent_cards_id`),
                KEY `stage` (`stage`),
                KEY `status` (`status`),
                KEY `rank` (`rank`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // Colunas de exclusão em tabelas de cards já existentes.
    $cards_columns = [
        'is_deleted'                  => 'TINYINT(1)   NOT NULL DEFAULT 0',
        'approval_kind'               => "VARCHAR(20)  NOT NULL DEFAULT 'maintenance' COMMENT 'maintenance|deletion'",
        'deletion_reason'             => 'TEXT         DEFAULT NULL',
        'deletion_requested_at'       => 'DATETIME     DEFAULT NULL',
        'deletion_requested_by'       => "INT {$sign} NOT NULL DEFAULT 0",
        'deletion_decided_at'         => 'DATETIME     DEFAULT NULL',
        'deletion_decided_by'         => "INT {$sign} NOT NULL DEFAULT 0",
        'deletion_previous_lists_id'  => "INT {$sign} NOT NULL DEFAULT 0",
        'deletion_previous_status'    => "VARCHAR(30)  NOT NULL DEFAULT ''",
        'deletion_previous_is_locked' => 'TINYINT(1)   NOT NULL DEFAULT 0',
    ];
    $added_is_deleted = false;
    $added_approval_kind = false;
    foreach ($cards_columns as $field => $definition) {
        if (!$DB->fieldExists('glpi_plugin_tralla_cards', $field)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tralla_cards` ADD COLUMN `{$field}` {$definition}") or die($DB->error());
            if ($field === 'is_deleted') {
                $added_is_deleted = true;
            } elseif ($field === 'approval_kind') {
                $added_approval_kind = true;
            }
        }
    }
    // Backfill somente quando a coluna acabou de ser criada: nunca reseta
    // dados em atualizações posteriores.
    if ($added_is_deleted) {
        $DB->update(
            'glpi_plugin_tralla_cards',
            ['is_deleted' => 0],
            ['id' => ['>', 0]]
        );
    }
    if ($added_approval_kind) {
        $DB->update(
            'glpi_plugin_tralla_cards',
            ['approval_kind' => 'maintenance'],
            ['id' => ['>', 0]]
        );
    }
    if ($cards_table_existed) {
        $DB->doQuery("
            ALTER TABLE `glpi_plugin_tralla_cards`
            MODIFY `status` VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'normal,pending,in_progress,waiting_approval,withdrawal,done,approved,rejected,deletion_pending,deleted'
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_machine_models')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_machine_models` (
                `id`           INT {$sign} NOT NULL AUTO_INCREMENT,
                `name`         VARCHAR(255) NOT NULL DEFAULT '',
                `entities_id`  INT {$sign} NOT NULL DEFAULT '0',
                `is_recursive` TINYINT(1)   NOT NULL DEFAULT '0',
                `comment`      TEXT         DEFAULT NULL,
                `is_archived`  TINYINT(1)   NOT NULL DEFAULT '0',
                `users_id`     INT {$sign} NOT NULL DEFAULT '0',
                `date_creation` DATETIME    DEFAULT NULL,
                `date_mod`      DATETIME    DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_machines')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_machines` (
                `id`                            INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_tralla_cards_id`        INT {$sign} NOT NULL DEFAULT '0',
                `plugin_tralla_machine_models_id` INT {$sign} NOT NULL DEFAULT '0',
                `label`                         VARCHAR(255) NOT NULL DEFAULT '',
                `serial`                        VARCHAR(255) NOT NULL DEFAULT '',
                `report`                        LONGTEXT     DEFAULT NULL,
                `status`                        VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'pending,in_progress,done,not_repairable',
                `service_done`                  TINYINT(1)   NOT NULL DEFAULT '0',
                `needs_inventory`               TINYINT(1)   NOT NULL DEFAULT '0',
                `inventory_items_id`            INT {$sign} NOT NULL DEFAULT '0',
                `rank`                          DOUBLE       NOT NULL DEFAULT '0',
                `report_saved_at`               DATETIME     DEFAULT NULL,
                `date_creation`                 DATETIME     DEFAULT NULL,
                `date_mod`                      DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_tralla_cards_id` (`plugin_tralla_cards_id`),
                KEY `plugin_tralla_machine_models_id` (`plugin_tralla_machine_models_id`),
                KEY `status` (`status`),
                KEY `rank` (`rank`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_history')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_history` (
                `id`                     INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_tralla_cards_id` INT {$sign} NOT NULL DEFAULT '0',
                `users_id`               INT {$sign} NOT NULL DEFAULT '0',
                `action`                 VARCHAR(60)  NOT NULL DEFAULT '',
                `details`                TEXT         DEFAULT NULL,
                `date_creation`          DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_tralla_cards_id` (`plugin_tralla_cards_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_configs')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_configs` (
                `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                `config_key`    VARCHAR(100) NOT NULL DEFAULT '',
                `config_value`  LONGTEXT     DEFAULT NULL,
                `date_creation` DATETIME     DEFAULT NULL,
                `date_mod`      DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_config_key` (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_tralla_notifications')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_tralla_notifications` (
                `id`                     INT {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_tralla_cards_id` INT {$sign} NOT NULL DEFAULT '0',
                `type`                   VARCHAR(40)  NOT NULL DEFAULT '',
                `channel`                VARCHAR(20)  NOT NULL DEFAULT 'log',
                `recipient`              VARCHAR(255) NOT NULL DEFAULT '',
                `status`                 VARCHAR(20)  NOT NULL DEFAULT 'sent',
                `message`                TEXT         DEFAULT NULL,
                `date_creation`          DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_tralla_cards_id` (`plugin_tralla_cards_id`),
                KEY `type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // Garante que o criador de cada quadro existente seja SuperAdmin.
    require_once __DIR__ . '/inc/boarduser.class.php';
    foreach ($DB->request([
        'SELECT' => ['id', 'users_id'],
        'FROM'   => 'glpi_plugin_tralla_boards',
        'WHERE'  => ['users_id' => ['>', 0]],
    ]) as $board) {
        $memberCount = countElementsInTable('glpi_plugin_tralla_boards_users', [
            'plugin_tralla_boards_id' => (int) $board['id'],
        ]);
        if (!tralla_should_backfill_creator($memberCount, (int) $board['users_id'])) {
            continue;
        }
        PluginTrallaBoardUser::addCreator((int) $board['id'], (int) $board['users_id']);
    }

    // Configurações padrão — nunca sobrescreve ajuste do administrador.
    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    $defaults = [
        'assignature_url'       => '',
        'assignature_token'     => bin2hex(random_bytes(24)),
        'approval_group'        => '0',
        'notification_enabled'  => '1',
        'notification_emails'   => '',
        'pending_hours'         => '1',
        'withdrawal_hours'      => '24',
    ];
    foreach ($defaults as $key => $value) {
        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_configs',
            'WHERE'  => ['config_key' => $key],
            'LIMIT'  => 1,
        ])->current();
        if (!$exists) {
            $DB->insert('glpi_plugin_tralla_configs', [
                'config_key'    => $key,
                'config_value'  => $value,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }
    }

    require_once __DIR__ . '/inc/profile.class.php';
    PluginTrallaProfile::install();

    require_once __DIR__ . '/inc/cron.class.php';
    if (class_exists('CronTask')) {
        CronTask::register(
            'PluginTrallaCron',
            'notify',
            HOUR_TIMESTAMP,
            [
                'state'        => CronTask::STATE_WAITING,
                'mode'         => CronTask::MODE_EXTERNAL,
                'comment'      => 'Tralla: notificações de cards pendentes e retiradas',
                'logs_lifetime' => 30,
            ]
        );
    }

    return true;
}

/**
 * Atualização: schema é idempotente.
 */
function plugin_tralla_update(): bool
{
    return plugin_tralla_install();
}

/**
 * Desinstalação completa.
 */
function plugin_tralla_uninstall(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/profile.class.php';
    PluginTrallaProfile::uninstall();

    if (class_exists('CronTask')) {
        CronTask::unregister('PluginTrallaCron', 'notify');
    }

    $tables = [
        'glpi_plugin_tralla_notifications',
        'glpi_plugin_tralla_history',
        'glpi_plugin_tralla_machines',
        'glpi_plugin_tralla_machine_models',
        'glpi_plugin_tralla_cards',
        'glpi_plugin_tralla_boards_users',
        'glpi_plugin_tralla_lists',
        'glpi_plugin_tralla_boards',
        'glpi_plugin_tralla_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    return true;
}
