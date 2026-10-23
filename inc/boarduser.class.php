<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Membros de um quadro do Tralla.
 *
 * Tabela de junção sem direitos ou UI próprios; por isso não estende
 * CommonDBTM.
 */
class PluginTrallaBoardUser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getMembers(int $boardId): array
    {
        global $DB;

        $members = [];
        $select = [
            'glpi_plugin_tralla_boards_users.users_id AS users_id',
            'glpi_plugin_tralla_boards_users.role AS role',
            'glpi_plugin_tralla_boards_users.date_creation AS date_creation',
            'glpi_users.name AS name',
            'glpi_users.realname AS realname',
            'glpi_users.firstname AS firstname',
            'glpi_users.is_deleted AS is_deleted',
        ];
        if (self::hasApproverColumn()) {
            $select[] = 'glpi_plugin_tralla_boards_users.can_approve AS can_approve';
        }

        $rows = $DB->request([
            'SELECT' => $select,
            'FROM'     => 'glpi_plugin_tralla_boards_users',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'FKEY' => [
                        'glpi_plugin_tralla_boards_users' => 'users_id',
                        'glpi_users'                      => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_tralla_boards_users.plugin_tralla_boards_id' => $boardId,
            ],
        ]);

        foreach ($rows as $row) {
            $members[] = [
                'users_id'      => (int) $row['users_id'],
                'role'          => (string) $row['role'],
                'can_approve'   => (int) ($row['can_approve'] ?? 0),
                'date_creation' => $row['date_creation'],
                'name'          => (string) ($row['name'] ?? ''),
                'realname'      => (string) ($row['realname'] ?? ''),
                'firstname'     => (string) ($row['firstname'] ?? ''),
                'is_deleted'    => (int) ($row['is_deleted'] ?? 0),
            ];
        }

        usort($members, static function (array $a, array $b): int {
            $byRank = tralla_role_rank($b['role']) <=> tralla_role_rank($a['role']);
            if ($byRank !== 0) {
                return $byRank;
            }

            $byName = strcasecmp(self::displayName($a), self::displayName($b));
            if ($byName !== 0) {
                return $byName;
            }

            return $a['users_id'] <=> $b['users_id'];
        });

        return $members;
    }

    public static function getRole(int $boardId, int $userId): string
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['role'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => [
                'plugin_tralla_boards_id' => $boardId,
                'users_id'                => $userId,
            ],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) ? (string) $row['role'] : '';
    }

    public static function isApprover(int $boardId, int $userId): bool
    {
        global $DB;

        if ($boardId <= 0 || $userId <= 0) {
            return false;
        }

        if (!self::hasApproverColumn()) {
            return self::getRole($boardId, $userId) !== '';
        }

        $row = $DB->request([
            'SELECT' => ['can_approve'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => [
                'plugin_tralla_boards_id' => $boardId,
                'users_id'                => $userId,
            ],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) && (int) ($row['can_approve'] ?? 0) === 1;
    }

    /**
     * Define quem aprova manutenções/exclusões neste quadro.
     * Somente membros do quadro podem ser aprovadores.
     *
     * @param array<int, int|string> $userIds
     */
    public static function setApprovers(int $boardId, array $userIds): void
    {
        global $DB;

        if (!self::hasApproverColumn()) {
            throw new RuntimeException('Atualize o plugin Tralla para definir aprovadores.');
        }

        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn(int $userId): bool => $userId > 0
        )));

        $DB->beginTransaction();
        try {
            self::lockBoard($boardId);

            $memberIds = [];
            foreach ($DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_plugin_tralla_boards_users',
                'WHERE'  => ['plugin_tralla_boards_id' => $boardId],
            ]) as $row) {
                $memberIds[(int) $row['users_id']] = true;
            }

            $validIds = array_values(array_filter(
                $userIds,
                static fn(int $userId): bool => isset($memberIds[$userId])
            ));

            if ($validIds === [] && $memberIds !== []) {
                throw new RuntimeException('Selecione pelo menos um aprovador para o quadro.');
            }

            $now = tralla_now();
            $DB->update(
                'glpi_plugin_tralla_boards_users',
                [
                    'can_approve' => 0,
                    'date_mod'    => $now,
                ],
                ['plugin_tralla_boards_id' => $boardId]
            );

            if ($validIds !== []) {
                $DB->update(
                    'glpi_plugin_tralla_boards_users',
                    [
                        'can_approve' => 1,
                        'date_mod'    => $now,
                    ],
                    [
                        'plugin_tralla_boards_id' => $boardId,
                        'users_id'                => $validIds,
                    ]
                );
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function setRole(int $boardId, int $userId, string $role): void
    {
        global $DB;

        $role = tralla_normalize_board_role($role);
        if ($role === '') {
            throw new RuntimeException('Papel inválido.');
        }

        $user = new User();
        if (!$user->getFromDB($userId)) {
            throw new RuntimeException('Usuário inválido.');
        }

        $DB->beginTransaction();
        try {
            self::lockBoard($boardId);
            $current = self::currentMembership($boardId, $userId);
            $currentRole = is_array($current) ? (string) $current['role'] : '';

            if (
                $currentRole === 'superadmin'
                && $role !== 'superadmin'
                && self::countSuperadmins($boardId) <= 1
            ) {
                throw new RuntimeException('O último SuperAdmin do quadro não pode ser rebaixado.');
            }

            $now = tralla_now();
            if (is_array($current)) {
                $DB->update(
                    'glpi_plugin_tralla_boards_users',
                    [
                        'role'     => $role,
                        'date_mod' => $now,
                    ],
                    ['id' => (int) $current['id']]
                );
            } else {
                $DB->insert('glpi_plugin_tralla_boards_users', [
                    'plugin_tralla_boards_id' => $boardId,
                    'users_id'                => $userId,
                    'role'                    => $role,
                    'date_creation'           => $now,
                    'date_mod'                => $now,
                ]);
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function remove(int $boardId, int $userId): void
    {
        global $DB;

        $DB->beginTransaction();
        try {
            self::lockBoard($boardId);
            $current = self::currentMembership($boardId, $userId);
            if (!is_array($current)) {
                $DB->commit();
                return;
            }

            if (
                (string) $current['role'] === 'superadmin'
                && self::countSuperadmins($boardId) <= 1
            ) {
                throw new RuntimeException('O último SuperAdmin do quadro não pode ser removido.');
            }

            $DB->delete('glpi_plugin_tralla_boards_users', ['id' => (int) $current['id']]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function countSuperadmins(int $boardId): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['COUNT' => 'id AS total'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => [
                'plugin_tralla_boards_id' => $boardId,
                'role'                    => 'superadmin',
            ],
        ])->current();

        return is_array($row) ? (int) $row['total'] : 0;
    }

    public static function addCreator(int $boardId, int $userId): void
    {
        global $DB;

        if ($boardId <= 0 || $userId <= 0) {
            return;
        }

        $user = new User();
        if (!$user->getFromDB($userId)) {
            return;
        }

        if (is_array(self::currentMembership($boardId, $userId))) {
            return;
        }

        $now = tralla_now();
        $data = [
            'plugin_tralla_boards_id' => $boardId,
            'users_id'                => $userId,
            'role'                    => 'superadmin',
            'date_creation'           => $now,
            'date_mod'                => $now,
        ];
        if (self::hasApproverColumn()) {
            $data['can_approve'] = 1;
        }
        $DB->insert('glpi_plugin_tralla_boards_users', $data);
    }

    private static function hasApproverColumn(): bool
    {
        global $DB;

        static $exists = null;
        if ($exists === null) {
            $exists = $DB->fieldExists('glpi_plugin_tralla_boards_users', 'can_approve');
        }

        return $exists;
    }

    /**
     * Serializa alterações de membros do mesmo quadro.
     */
    private static function lockBoard(int $boardId): void
    {
        global $DB;

        $DB->doQuery(sprintf(
            'SELECT `id` FROM `glpi_plugin_tralla_boards` WHERE `id` = %d FOR UPDATE',
            $boardId
        ));
    }

    /**
     * @return array<string, mixed>|false
     */
    private static function currentMembership(int $boardId, int $userId)
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'role'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => [
                'plugin_tralla_boards_id' => $boardId,
                'users_id'                => $userId,
            ],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) ? $row : false;
    }

    private static function displayName(array $member): string
    {
        $name = trim((string) $member['realname'] . ' ' . (string) $member['firstname']);

        return $name !== '' ? $name : (string) $member['name'];
    }
}
