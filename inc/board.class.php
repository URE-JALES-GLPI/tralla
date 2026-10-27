<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/boarduser.class.php';
require_once __DIR__ . '/file.class.php';

/**
 * Quadro do Tralla.
 */
class PluginTrallaBoard extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTypeName($nb = 0)
    {
        return _n('Quadro', 'Quadros', $nb, 'tralla');
    }

    public static function getMenuName()
    {
        return 'Tralla';
    }

    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        $menu['icon'] = 'ti ti-layout-kanban';
        $menu['title'] = 'Tralla';
        $menu['page'] = tralla_web_dir() . '/front/board.php';

        return $menu;
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public function canViewItem(): bool
    {
        return self::canViewBoard((int) $this->getID());
    }

    public function canCreateItem(): bool
    {
        return self::canCreate();
    }

    public function canUpdateItem(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE) && self::canAdminBoard((int) $this->getID());
    }

    public function canDeleteItem(): bool
    {
        return Session::haveRight(self::$rightname, DELETE) && self::canGovernBoard((int) $this->getID());
    }

    public function canPurgeItem(): bool
    {
        return Session::haveRight(self::$rightname, PURGE) && self::canGovernBoard((int) $this->getID());
    }

    public static function getTypeChoices(): array
    {
        return [
            'normal'      => 'Normal (listas e cards livres)',
            'maintenance' => 'Manutenção (fluxo controlado)',
            'updates'     => 'Atualizações (tarefas e checklist)',
        ];
    }

    public static function getDefaultLists(string $type): array
    {
        if ($type === 'maintenance') {
            return [
                ['name' => 'Manutenção Pendente', 'category' => 'maintenance_pending', 'color' => tralla_category_color('maintenance_pending')],
                ['name' => 'Manutenção Em Andamento', 'category' => 'maintenance_in_progress', 'color' => tralla_category_color('maintenance_in_progress')],
                ['name' => 'Manutenção Aprovação', 'category' => 'maintenance_approval', 'color' => tralla_category_color('maintenance_approval')],
                ['name' => 'Manutenção Retirada', 'category' => 'maintenance_withdrawal', 'color' => tralla_category_color('maintenance_withdrawal')],
                ['name' => 'Manutenção Concluído', 'category' => 'maintenance_done', 'color' => tralla_category_color('maintenance_done')],
            ];
        }

        if ($type === 'updates') {
            return array_map(static function (string $category): array {
                return [
                    'name' => tralla_category_label($category),
                    'category' => $category,
                    'color' => tralla_category_color($category),
                ];
            }, tralla_update_categories());
        }

        return [
            ['name' => 'A Fazer', 'category' => '', 'color' => '#97a0af'],
            ['name' => 'Em Progresso', 'category' => '', 'color' => '#0079bf'],
            ['name' => 'Concluído', 'category' => '', 'color' => '#61bd4f'],
        ];
    }

    public function prepareInputForAdd($input)
    {
        $input['name'] = tralla_normalize_label($input['name'] ?? '');
        if ($input['name'] === '') {
            Session::addMessageAfterRedirect('Informe o nome do quadro.', false, ERROR);
            return false;
        }

        $type = (string) ($input['type'] ?? 'normal');
        $input['type'] = array_key_exists($type, self::getTypeChoices()) ? $type : 'normal';
        $input['background'] = tralla_valid_background((string) ($input['background'] ?? 'ocean'));
        $input['color'] = tralla_background_color($input['background']);
        $input['comment'] = trim(strip_tags((string) ($input['comment'] ?? '')));
        $input['is_archived'] = !empty($input['is_archived']) ? 1 : 0;
        $input['is_recursive'] = !empty($input['is_recursive']) ? 1 : 0;
        $input['users_id'] = (int) Session::getLoginUserID();

        $entitiesId = (int) ($input['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        if (!Session::haveAccessToEntity($entitiesId, (bool) $input['is_recursive'])) {
            $entitiesId = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        }
        $input['entities_id'] = $entitiesId;

        $input['date_creation'] = tralla_now();
        $input['date_mod'] = $input['date_creation'];

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = tralla_normalize_label($input['name']);
            if ($input['name'] === '') {
                unset($input['name']);
            }
        }

        if (array_key_exists('background', $input)) {
            $input['background'] = tralla_valid_background((string) $input['background']);
            $input['color'] = tralla_background_color($input['background']);
        } else {
            unset($input['color']);
        }
        if (array_key_exists('comment', $input)) {
            $input['comment'] = trim(strip_tags((string) $input['comment']));
        }
        if (array_key_exists('is_archived', $input)) {
            $input['is_archived'] = !empty($input['is_archived']) ? 1 : 0;
        }

        // Tipo e entidade definem o schema de listas e a visibilidade: não mudam.
        unset($input['type'], $input['entities_id'], $input['users_id']);

        $input['date_mod'] = tralla_now();

        return $input;
    }

    public function post_addItem()
    {
        $boardId = (int) $this->getID();
        $type = (string) ($this->fields['type'] ?? 'normal');
        $rank = 1000.0;

        // O criador precisa ser membro antes da validação de acesso das listas padrão.
        PluginTrallaBoardUser::addCreator($boardId, (int) Session::getLoginUserID());

        foreach (self::getDefaultLists($type) as $list) {
            $item = new PluginTrallaList();
            $item->add([
                'plugin_tralla_boards_id' => $boardId,
                'name'                    => $list['name'],
                'category'                => $list['category'],
                'color'                   => $list['color'],
                'rank'                    => $rank,
                'users_id'                => (int) Session::getLoginUserID(),
                'date_creation'           => tralla_now(),
                'date_mod'                => tralla_now(),
            ]);
            $rank += 1000.0;
        }

    }

    public function cleanDBonPurge()
    {
        global $DB;

        $boardId = (int) $this->getID();
        if ($boardId <= 0) {
            return;
        }

        $cardIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => ['plugin_tralla_boards_id' => $boardId],
        ]) as $row) {
            $cardIds[] = (int) $row['id'];
        }

        if ($cardIds !== []) {
            PluginTrallaFile::purgeForCards($cardIds);
            $DB->delete('glpi_plugin_tralla_machines', ['plugin_tralla_cards_id' => $cardIds]);
            $DB->delete('glpi_plugin_tralla_history', ['plugin_tralla_cards_id' => $cardIds]);
            $DB->delete('glpi_plugin_tralla_notifications', ['plugin_tralla_cards_id' => $cardIds]);
            $DB->delete('glpi_plugin_tralla_cards', ['id' => $cardIds]);
        }

        $DB->delete('glpi_plugin_tralla_boards_users', ['plugin_tralla_boards_id' => $boardId]);
        $DB->delete('glpi_plugin_tralla_lists', ['plugin_tralla_boards_id' => $boardId]);
    }

    public static function isMaintenance(int $boards_id): bool
    {
        $board = new self();
        if (!$board->getFromDB($boards_id)) {
            return false;
        }

        return ($board->fields['type'] ?? '') === 'maintenance';
    }

    public static function roleFor(int $boardId, int $userId = 0): string
    {
        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();

        $board = new self();
        if ($boardId <= 0 || !$board->getFromDB($boardId)) {
            return '';
        }

        if (!Session::haveAccessToEntity(
            (int) $board->fields['entities_id'],
            (bool) $board->fields['is_recursive']
        )) {
            return '';
        }

        return PluginTrallaBoardUser::getRole($boardId, $userId);
    }

    public static function canViewBoard(int $boards_id): bool
    {
        if (!self::canView() || $boards_id <= 0) {
            return false;
        }

        $board = new self();
        if (!$board->getFromDB($boards_id)) {
            return false;
        }

        $role = self::roleFor($boards_id);
        $hasEntity = Session::haveAccessToEntity(
            (int) $board->fields['entities_id'],
            (bool) $board->fields['is_recursive']
        );

        return tralla_role_can_view(
            $role !== '',
            $hasEntity,
            Session::haveRight(self::$rightname, READ)
        );
    }

    public static function canOperateBoard(int $boardId, int $userId = 0): bool
    {
        if (!self::canViewBoard($boardId)) {
            return false;
        }

        return self::roleFor($boardId, $userId) !== '';
    }

    public static function canAdminBoard(int $boardId, int $userId = 0): bool
    {
        if (!self::canOperateBoard($boardId, $userId)) {
            return false;
        }

        return tralla_role_at_least(self::roleFor($boardId, $userId), 'admin');
    }

    public static function canGovernBoard(int $boardId, int $userId = 0): bool
    {
        if (!self::canOperateBoard($boardId, $userId)) {
            return false;
        }

        return self::roleFor($boardId, $userId) === 'superadmin';
    }

    /**
     * Quadros visíveis para a sessão, ordenados por modificação.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getVisibleBoards(bool $includeArchived = false): array
    {
        global $DB;

        $where = [];
        if (!$includeArchived) {
            $where['is_archived'] = 0;
        }

        $membershipIds = [];
        foreach ($DB->request([
            'SELECT' => ['plugin_tralla_boards_id'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => ['users_id' => (int) Session::getLoginUserID()],
        ]) as $membership) {
            $membershipIds[] = (int) $membership['plugin_tralla_boards_id'];
        }

        if ($membershipIds === []) {
            return [];
        }

        $where['id'] = $membershipIds;

        $boards = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_boards',
            'WHERE' => $where,
            'ORDER' => 'date_mod DESC, name ASC',
        ]) as $row) {
            if (Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive'])) {
                $boards[] = $row;
            }
        }

        return $boards;
    }

    public static function findListByCategory(int $boards_id, string $category): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_lists',
            'WHERE' => [
                'plugin_tralla_boards_id' => $boards_id,
                'category'                => $category,
                'is_archived'             => 0,
            ],
            'ORDER' => 'rank ASC, id ASC',
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    public static function getStats(int $boards_id): array
    {
        global $DB;

        $stats = [
            'cards'       => 0,
            'pending'     => 0,
            'in_progress' => 0,
            'approval'    => 0,
            'withdrawal'  => 0,
            'done'        => 0,
            'lists'       => countElementsInTable('glpi_plugin_tralla_lists', [
                'plugin_tralla_boards_id' => $boards_id,
                'is_archived'             => 0,
            ]),
        ];

        foreach ($DB->request([
            'SELECT' => ['status', 'COUNT' => 'id AS total'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => [
                'plugin_tralla_boards_id' => $boards_id,
                'is_archived'             => 0,
                'is_deleted'              => 0,
            ],
            'GROUPBY' => ['status'],
        ]) as $row) {
            $status = (string) $row['status'];
            if ($status === 'deletion_pending') {
                continue;
            }
            $total = (int) $row['total'];
            $stats['cards'] += $total;
            if (array_key_exists($status, $stats)) {
                $stats[$status] += $total;
            }
        }

        return $stats;
    }

    /**
     * Resumo de cards de manutenção por usuário (painel do topo da lista de quadros).
     *
     * Pendente  = cards ainda não assumidos, contados para quem os criou;
     * demais    = cards contados para o técnico que assumiu a manutenção.
     *
     * @param string[] $emails   E-mails dos usuários a exibir (na ordem desejada).
     * @param int[]    $boardIds Quadros de manutenção visíveis ao usuário logado.
     * @return array<int, array{email:string, users_id:int, name:string, pending:int, in_progress:int, withdrawal:int, done:int}>
     */
    public static function getUserMaintenanceSummary(array $emails, array $boardIds): array
    {
        global $DB;

        $summary = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || isset($summary[$email])) {
                continue;
            }

            $usersId = 0;
            foreach ($DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => ['email' => $email],
                'LIMIT'  => 1,
            ]) as $row) {
                $usersId = (int) $row['users_id'];
            }
            if ($usersId === 0) {
                foreach ($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['name' => $email],
                    'LIMIT'  => 1,
                ]) as $row) {
                    $usersId = (int) $row['id'];
                }
            }

            $item = [
                'email'       => $email,
                'users_id'    => $usersId,
                'name'        => $usersId > 0 ? tralla_user_name($usersId) : $email,
                'pending'     => 0,
                'in_progress' => 0,
                'withdrawal'  => 0,
                'done'        => 0,
            ];

            $item = array_merge($item, self::getUserMaintenanceStats($usersId, $boardIds));

            $summary[$email] = $item;
        }

        return array_values($summary);
    }

    /** @param int[] $boardIds */
    public static function getUserMaintenanceStats(int $usersId, array $boardIds): array
    {
        $stats = ['pending' => 0, 'in_progress' => 0, 'withdrawal' => 0, 'done' => 0];
        if ($usersId <= 0 || $boardIds === []) {
            return $stats;
        }

        $base = [
            'plugin_tralla_boards_id' => array_values(array_map('intval', $boardIds)),
            'stage'                   => 1,
            'is_deleted'              => 0,
        ];
        $stats['pending'] = countElementsInTable('glpi_plugin_tralla_cards', $base + [
            'status' => 'pending', 'is_archived' => 0, 'technician_id' => 0, 'users_id' => $usersId,
        ]);
        foreach (['in_progress', 'withdrawal'] as $status) {
            $stats[$status] = countElementsInTable('glpi_plugin_tralla_cards', $base + [
                'status' => $status, 'is_archived' => 0, 'technician_id' => $usersId,
            ]);
        }
        $stats['done'] = countElementsInTable('glpi_plugin_tralla_cards', $base + [
            'status' => 'done', 'technician_id' => $usersId,
        ]);

        return $stats;
    }

    /**
     * Hash leve do estado do quadro para detecção de mudanças (polling).
     */
    public static function getVersion(int $boards_id): string
    {
        global $DB;

        if ($boards_id <= 0) {
            return '';
        }

        $boardId = (int) $boards_id;
        $queries = [
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', id, name, category, color, is_archived, rank, date_mod))), 0) AS s
                FROM `glpi_plugin_tralla_lists`
                WHERE `plugin_tralla_boards_id` = {$boardId}",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', id, plugin_tralla_lists_id, name, description, update_mode, update_solution, update_observation, status, stage, parent_cards_id, approval_cards_id, family_cards_id, code_root_cards_id, family_seq, split_from_cards_id, approval_kind, source, is_locked, is_archived, is_deleted, rank, users_id, technician_id, users_id_approver, machines_total, date_taken, date_finalized, date_approved, date_withdrawn, rejection_reason, date_mod))), 0) AS s
                FROM `glpi_plugin_tralla_cards`
                WHERE `plugin_tralla_boards_id` = {$boardId}",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', m.id, m.plugin_tralla_cards_id, m.plugin_tralla_machine_models_id, m.label, m.serial, m.report, m.status, m.service_done, m.needs_inventory, m.inventory_items_id, m.rank, m.report_saved_at, m.date_mod))), 0) AS s
                FROM `glpi_plugin_tralla_machines` AS m
                INNER JOIN `glpi_plugin_tralla_cards` AS c ON c.id = m.plugin_tralla_cards_id
                WHERE c.plugin_tralla_boards_id = {$boardId}",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', users_id, role, can_approve))), 0) AS s
                FROM `glpi_plugin_tralla_boards_users`
                WHERE `plugin_tralla_boards_id` = {$boardId}",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', i.id, i.title, i.is_done, i.rank, i.date_mod))), 0) AS s
                FROM `glpi_plugin_tralla_update_items` AS i
                INNER JOIN `glpi_plugin_tralla_cards` AS c ON c.id = i.plugin_tralla_cards_id
                WHERE c.plugin_tralla_boards_id = {$boardId}",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', f.id, f.plugin_tralla_cards_id, f.plugin_tralla_update_items_id, f.original_name, f.file_size))), 0) AS s
                FROM `glpi_plugin_tralla_files` AS f
                INNER JOIN `glpi_plugin_tralla_cards` AS c ON c.id = f.plugin_tralla_cards_id
                WHERE c.plugin_tralla_boards_id = {$boardId}",
        ];

        $parts = [];
        foreach ($queries as $query) {
            try {
                $result = $DB->doQuery($query);
                $row = $result->fetch_assoc();
                $parts[] = (string) ($row['c'] ?? '0') . ':' . (string) ($row['s'] ?? '0');
            } catch (Throwable $e) {
                $parts[] = '0:0';
            }
        }

        return md5(implode('|', $parts));
    }

    public static function countCards(int $boards_id): int
    {
        return countElementsInTable('glpi_plugin_tralla_cards', [
            'plugin_tralla_boards_id' => $boards_id,
            'is_archived'             => 0,
            'is_deleted'              => 0,
        ]);
    }

    public static function countMaintenanceByStatus(int $boards_id, string $status): int
    {
        if ($status === 'deletion_pending') {
            return 0;
        }

        return countElementsInTable('glpi_plugin_tralla_cards', [
            'plugin_tralla_boards_id' => $boards_id,
            'stage'                   => 1,
            'status'                  => $status,
            'is_archived'             => 0,
            'is_deleted'              => 0,
        ]);
    }
}
