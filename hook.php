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
                `can_approve`             TINYINT(1)   NOT NULL DEFAULT '0' COMMENT 'aprova manutenções/exclusões neste quadro',
                `date_creation`           DATETIME     DEFAULT NULL,
                `date_mod`                DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_board_user` (`plugin_tralla_boards_id`, `users_id`),
                KEY `users_id` (`users_id`),
                KEY `role` (`role`),
                KEY `can_approve` (`can_approve`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // Aprovadores por quadro: migração idempotente para instalações existentes.
    if (!$DB->fieldExists('glpi_plugin_tralla_boards_users', 'can_approve')) {
        $DB->doQuery("
            ALTER TABLE `glpi_plugin_tralla_boards_users`
            ADD COLUMN `can_approve` TINYINT(1) NOT NULL DEFAULT '0' COMMENT 'aprova manutenções/exclusões neste quadro'
        ") or die($DB->error());
        $DB->doQuery("
            ALTER TABLE `glpi_plugin_tralla_boards_users`
            ADD KEY `can_approve` (`can_approve`)
        ") or die($DB->error());
        // Mantém o comportamento anterior: SuperAdmins do quadro já aprovavam.
        $DB->update(
            'glpi_plugin_tralla_boards_users',
            ['can_approve' => 1],
            ['role' => 'superadmin']
        );
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
                `family_cards_id`         INT {$sign} NOT NULL DEFAULT '0' COMMENT 'card raiz da família',
                `code_root_cards_id`      INT {$sign} NOT NULL DEFAULT '0' COMMENT 'raiz da numeração visual',
                `family_seq`              INT {$sign} NOT NULL DEFAULT '1' COMMENT 'posição na numeração visual',
                `split_from_cards_id`     INT {$sign} NOT NULL DEFAULT '0' COMMENT 'card que originou a separação',
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
                `approval_kind`           VARCHAR(20)  NOT NULL DEFAULT 'maintenance' COMMENT 'maintenance|deletion|edit|split',
                `edit_payload`            LONGTEXT     DEFAULT NULL COMMENT 'JSON de edição pendente',
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
                KEY `family_cards_id` (`family_cards_id`),
                KEY `code_root_cards_id` (`code_root_cards_id`),
                KEY `family_seq` (`family_seq`),
                KEY `split_from_cards_id` (`split_from_cards_id`),
                KEY `stage` (`stage`),
                KEY `status` (`status`),
                KEY `rank` (`rank`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // Colunas de exclusão em tabelas de cards já existentes.
    $cards_columns = [
        'is_deleted'                  => 'TINYINT(1)   NOT NULL DEFAULT 0',
        'approval_kind'               => "VARCHAR(20)  NOT NULL DEFAULT 'maintenance' COMMENT 'maintenance|deletion|edit|split'",
        'edit_payload'                => 'LONGTEXT     DEFAULT NULL',
        'family_cards_id'             => "INT {$sign} NOT NULL DEFAULT 0",
        'code_root_cards_id'          => "INT {$sign} NOT NULL DEFAULT 0",
        'family_seq'                  => "INT {$sign} NOT NULL DEFAULT 1",
        'split_from_cards_id'         => "INT {$sign} NOT NULL DEFAULT 0",
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
            if ($field === 'code_root_cards_id') {
                $DB->doQuery('ALTER TABLE `glpi_plugin_tralla_cards` ADD KEY `code_root_cards_id` (`code_root_cards_id`)') or die($DB->error());
            }
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
    // Famílias: todo card stage 1 é raiz da própria família; stage 2 herda do pai.
    // Idempotente: só toca linhas ainda não preenchidas.
    $DB->doQuery("
        UPDATE `glpi_plugin_tralla_cards`
        SET `family_cards_id` = `id`, `family_seq` = 1
        WHERE `stage` = 1 AND (`family_cards_id` = 0 OR `family_seq` = 0)
    ") or die($DB->error());
    $DB->doQuery("
        UPDATE `glpi_plugin_tralla_cards` AS c2
        INNER JOIN `glpi_plugin_tralla_cards` AS c1 ON c1.id = c2.parent_cards_id
        SET c2.family_cards_id = c1.family_cards_id, c2.family_seq = c1.family_seq
        WHERE c2.stage = 2 AND (c2.family_cards_id = 0 OR c2.family_seq = 0)
    ") or die($DB->error());
    $DB->doQuery("
        UPDATE `glpi_plugin_tralla_cards`
        SET `family_cards_id` = `parent_cards_id`, `family_seq` = 1
        WHERE `stage` = 2 AND `parent_cards_id` > 0 AND (`family_cards_id` = 0 OR `family_seq` = 0)
    ") or die($DB->error());

    // A retirada usa family_cards_id; a numeração também inclui cards pendentes
    // separados que continuam o código visual da origem. Reconstitui a ordem de
    // criação, incluindo aprovações antigas e cards arquivados.
    $visualRoots = [];
    $visualNumbers = [];
    $codeFixes = [];
    foreach ($DB->request([
        'SELECT' => ['id', 'stage', 'parent_cards_id', 'split_from_cards_id', 'family_cards_id', 'code_root_cards_id', 'family_seq'],
        'FROM'   => 'glpi_plugin_tralla_cards',
        'ORDER'  => 'id ASC',
    ]) as $codeCard) {
        $cardId = (int) $codeCard['id'];
        $sourceId = (int) $codeCard['stage'] === 2
            ? (int) $codeCard['parent_cards_id']
            : (int) $codeCard['split_from_cards_id'];
        $root = $sourceId > 0 && isset($visualRoots[$sourceId])
            ? $visualRoots[$sourceId]
            : (int) $codeCard['code_root_cards_id'];
        if ($root <= 0) {
            $root = (int) $codeCard['family_cards_id'] ?: $cardId;
        }
        $visualRoots[$cardId] = $root;
        $visualNumbers[$root] = ($visualNumbers[$root] ?? 0) + 1;
        if ((int) $codeCard['code_root_cards_id'] !== $root
            || (int) $codeCard['family_seq'] !== $visualNumbers[$root]) {
            $codeFixes[$cardId] = [
                'code_root_cards_id' => $root,
                'family_seq' => $visualNumbers[$root],
            ];
        }
    }
    if ($codeFixes !== []) {
        $DB->beginTransaction();
        try {
            foreach ($codeFixes as $cardId => $codeFields) {
                if ($DB->update('glpi_plugin_tralla_cards', $codeFields, ['id' => $cardId]) === false) {
                    throw new RuntimeException('Falha ao corrigir a numeração visual dos cards.');
                }
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    if ($cards_table_existed) {
        $DB->doQuery("
            ALTER TABLE `glpi_plugin_tralla_cards`
            MODIFY `status` VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'normal,pending,in_progress,waiting_approval,withdrawal,done,approved,rejected,deletion_pending,deleted'
        ") or die($DB->error());
        $DB->doQuery("
            ALTER TABLE `glpi_plugin_tralla_cards`
            MODIFY `approval_kind` VARCHAR(20) NOT NULL DEFAULT 'maintenance' COMMENT 'maintenance|deletion|edit|split'
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
