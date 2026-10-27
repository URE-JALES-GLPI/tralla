<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Histórico de ações dos cards.
 */
class PluginTrallaHistory extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTypeName($nb = 0)
    {
        return _n('Histórico', 'Históricos', $nb, 'tralla');
    }

    public static function log(int $cards_id, int $users_id, string $action, string $details = ''): void
    {
        global $DB;

        if ($cards_id <= 0) {
            return;
        }

        $DB->insert('glpi_plugin_tralla_history', [
            'plugin_tralla_cards_id' => $cards_id,
            'users_id'               => $users_id,
            'action'                 => substr($action, 0, 60),
            'details'                => $details,
            'date_creation'          => tralla_now(),
        ]);
    }

    public static function getForCard(int $cards_id): array
    {
        global $DB;

        $history = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_history',
            'WHERE' => ['plugin_tralla_cards_id' => $cards_id],
            'ORDER' => 'date_creation DESC, id DESC',
        ]);

        foreach ($iterator as $row) {
            $row['user_name'] = tralla_user_name((int) $row['users_id']);
            $row['action_label'] = self::getActionLabel((string) $row['action']);
            $row['date_formatted'] = tralla_date($row['date_creation']);
            $history[] = $row;
        }

        return $history;
    }

    private static function boardFilterOptions(int $boardId): array
    {
        global $DB;

        $users = [];
        foreach (PluginTrallaBoardUser::getMembers($boardId) as $member) {
            if ((int) ($member['is_deleted'] ?? 0) === 1) {
                continue;
            }
            $name = trim((string) ($member['realname'] ?? '') . ' ' . (string) ($member['firstname'] ?? ''));
            $users[] = [
                'id' => (int) $member['users_id'],
                'name' => $name !== '' ? $name : (string) ($member['name'] ?? ''),
            ];
        }

        $machineRows = [];
        $modelIds = [];
        foreach ($DB->request([
            'SELECT' => [
                'm.id AS machine_id',
                'm.plugin_tralla_cards_id AS card_id',
                'm.plugin_tralla_machine_models_id AS model_id',
                'm.label AS machine_label',
                'm.serial AS machine_serial',
            ],
            'FROM' => 'glpi_plugin_tralla_machines AS m',
            'INNER JOIN' => [
                'glpi_plugin_tralla_cards AS c' => [
                    'ON' => ['m' => 'plugin_tralla_cards_id', 'c' => 'id'],
                ],
            ],
            'WHERE' => ['c.plugin_tralla_boards_id' => $boardId],
            'ORDER' => 'm.id ASC',
        ]) as $row) {
            $machineRows[] = $row;
            $modelId = (int) $row['model_id'];
            if ($modelId > 0) {
                $modelIds[$modelId] = $modelId;
            }
        }
        $modelNames = PluginTrallaMachineModel::getModelNames(array_values($modelIds));
        $models = [];
        foreach ($modelNames as $id => $name) {
            $models[] = ['id' => (int) $id, 'name' => $name];
        }
        usort($models, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $machines = [];
        foreach ($machineRows as $row) {
            $id = (int) $row['machine_id'];
            $modelName = (string) ($modelNames[(int) $row['model_id']] ?? 'Máquina');
            $label = trim((string) ($row['machine_label'] ?? ''));
            $serial = trim((string) ($row['machine_serial'] ?? ''));
            $display = $modelName . ($label !== '' ? ' · ' . $label : '')
                . ($serial !== '' ? ' · ' . $serial : '') . ' (#' . $id . ')';
            $machines[] = [
                'id' => $id,
                'card_id' => (int) $row['card_id'],
                'model_id' => (int) $row['model_id'],
                'name' => $display,
            ];
        }
        usort($machines, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $actions = [];
        foreach ($DB->request([
            'SELECT' => ['h.action AS action'],
            'FROM' => 'glpi_plugin_tralla_history AS h',
            'INNER JOIN' => [
                'glpi_plugin_tralla_cards AS c' => [
                    'ON' => ['h' => 'plugin_tralla_cards_id', 'c' => 'id'],
                ],
            ],
            'WHERE' => ['c.plugin_tralla_boards_id' => $boardId],
            'GROUPBY' => ['h.action'],
        ]) as $row) {
            $action = (string) ($row['action'] ?? '');
            if ($action !== '') {
                $actions[] = ['value' => $action, 'label' => self::getActionLabel($action)];
            }
        }
        usort($actions, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return ['users' => $users, 'machines' => $machines, 'models' => $models, 'actions' => $actions];
    }

    public static function getForBoard(int $boardId, array $filters): array
    {
        global $DB;

        if ($boardId <= 0 || !PluginTrallaBoard::canViewBoard($boardId)) {
            throw new RuntimeException('Você não tem acesso a este quadro.');
        }

        $cardFilter = null;
        $cardId = trim((string) ($filters['card_id'] ?? ''));
        if ($cardId !== '') {
            $parsed = tralla_parse_history_card_filter($cardId);
            if (($parsed['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($parsed['error'] ?? 'Card inválido.'));
            }
            $cardFilter = $parsed;
        }

        $dates = tralla_validate_history_dates(
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? '')
        );
        if (($dates['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($dates['error'] ?? 'Data inválida.'));
        }

        $options = self::boardFilterOptions($boardId);
        $userId = trim((string) ($filters['users_id'] ?? ''));
        $machineId = trim((string) ($filters['machine_id'] ?? ''));
        $modelId = trim((string) ($filters['model_id'] ?? ''));
        $action = trim((string) ($filters['action'] ?? ''));
        foreach ([
            ['value' => $userId, 'options' => $options['users'], 'label' => 'Usuário'],
            ['value' => $machineId, 'options' => $options['machines'], 'label' => 'Máquina'],
            ['value' => $modelId, 'options' => $options['models'], 'label' => 'Modelo'],
        ] as $selection) {
            if ($selection['value'] !== '' && (!ctype_digit($selection['value'])
                || !in_array((int) $selection['value'], array_column($selection['options'], 'id'), true))) {
                throw new RuntimeException($selection['label'] . ' não disponível neste quadro.');
            }
        }
        if ($action !== '' && !in_array($action, array_column($options['actions'], 'value'), true)) {
            throw new RuntimeException('Tipo de atualização não disponível neste quadro.');
        }

        $page = (int) ($filters['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $where = [
            'c.plugin_tralla_boards_id' => $boardId,
        ];

        if ($cardFilter !== null) {
            $type = (string) ($cardFilter['type'] ?? 'all');
            $id = (int) ($cardFilter['id'] ?? 0);

            if ($type === 'stage') {
                $where['c.code_root_cards_id'] = $id;
                $where['c.family_seq'] = (int) ($cardFilter['stage'] ?? 1);
            } else {
                $where[] = [
                    'OR' => [
                        'c.code_root_cards_id' => $id,
                        'c.id'              => $id,
                    ],
                ];
            }
        }

        if ((string) $dates['from'] !== '') {
            $where[] = ['h.date_creation' => ['>=', (string) $dates['from']]];
        }
        if ((string) $dates['to'] !== '') {
            $where[] = ['h.date_creation' => ['<=', (string) $dates['to']]];
        }
        if ($userId !== '') {
            $where['h.users_id'] = (int) $userId;
        }
        if ($action !== '') {
            $where['h.action'] = $action;
        }
        if ($machineId !== '' || $modelId !== '') {
            $ownerIds = [];
            foreach ($options['machines'] as $machine) {
                if (($machineId === '' || (int) $machine['id'] === (int) $machineId)
                    && ($modelId === '' || (int) $machine['model_id'] === (int) $modelId)) {
                    $ownerIds[(int) $machine['card_id']] = (int) $machine['card_id'];
                }
            }
            $where[] = $ownerIds === []
                ? ['c.id' => -1]
                : ['OR' => [
                    'c.id' => array_values($ownerIds),
                    'c.parent_cards_id' => array_values($ownerIds),
                ]];
        }

        $joins = [
            'INNER JOIN' => [
                'glpi_plugin_tralla_cards AS c' => [
                    'ON' => [
                        'h' => 'plugin_tralla_cards_id',
                        'c' => 'id',
                    ],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_users AS u' => [
                    'ON' => [
                        'u' => 'id',
                        'h' => 'users_id',
                    ],
                ],
            ],
        ];

        $countRow = $DB->request($joins + [
            'COUNT' => 'total',
            'FROM'  => 'glpi_plugin_tralla_history AS h',
            'WHERE' => $where,
        ])->current();
        $total = (int) ($countRow['total'] ?? 0);
        $pages = max(1, (int) ceil($total / 50));

        $rows = [];
        if ($page <= $pages) {
            $boardRow = $DB->request([
                'SELECT' => ['type'],
                'FROM'   => 'glpi_plugin_tralla_boards',
                'WHERE'  => ['id' => $boardId],
                'LIMIT'  => 1,
            ])->current();
            $boardType = is_array($boardRow) ? (string) ($boardRow['type'] ?? '') : '';

            $iterator = $DB->request($joins + [
                'SELECT' => [
                    'h.plugin_tralla_cards_id AS card_id',
                    'c.name AS card_name',
                    'c.stage AS stage',
                    'c.parent_cards_id AS parent_cards_id',
                    'c.family_cards_id AS family_cards_id',
                    'c.code_root_cards_id AS code_root_cards_id',
                    'c.family_seq AS family_seq',
                    'c.is_deleted AS is_deleted',
                    'h.action AS action',
                    'h.details AS details',
                    'h.date_creation AS date_creation',
                    'h.users_id AS users_id',
                    'u.name AS user_login',
                    'u.realname AS user_realname',
                    'u.firstname AS user_firstname',
                    'u.is_deleted AS user_deleted',
                ],
                'FROM'  => 'glpi_plugin_tralla_history AS h',
                'WHERE' => $where,
                'ORDER' => 'h.date_creation DESC, h.id DESC',
                'START' => ($page - 1) * 50,
                'LIMIT' => 50,
            ]);

            foreach ($iterator as $row) {
                $userName = '';
                if ((int) ($row['user_deleted'] ?? 0) !== 1) {
                    $userName = trim(
                        (string) ($row['user_realname'] ?? '') . ' ' . (string) ($row['user_firstname'] ?? '')
                    );
                    if ($userName === '') {
                        $userName = (string) ($row['user_login'] ?? '');
                    }
                }

                $cardRow = [
                    'id'              => (int) $row['card_id'],
                    'stage'           => (int) $row['stage'],
                    'parent_cards_id' => (int) $row['parent_cards_id'],
                    'family_cards_id' => (int) $row['family_cards_id'],
                    'code_root_cards_id' => (int) $row['code_root_cards_id'],
                    'family_seq'      => (int) $row['family_seq'],
                ];

                $rows[] = [
                    'card_id'        => (int) $row['card_id'],
                    'card_code'      => tralla_display_code($cardRow, $boardType),
                    'card_name'      => (string) $row['card_name'],
                    'action'         => (string) $row['action'],
                    'action_label'   => self::getActionLabel((string) $row['action']),
                    'details'        => (string) $row['details'],
                    'date_creation'  => (string) $row['date_creation'],
                    'date_formatted' => tralla_date($row['date_creation']),
                    'user_name'      => $userName,
                    'is_deleted'     => (int) $row['is_deleted'],
                ];
            }
        }

        return [
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'options' => [
                'users' => $options['users'],
                'machines' => array_map(static fn(array $machine): array => [
                    'id' => $machine['id'], 'name' => $machine['name'],
                ], $options['machines']),
                'models' => $options['models'],
                'actions' => $options['actions'],
            ],
        ];
    }

    /**
     * Maior ID de histórico do quadro, usado como cursor inicial do polling.
     */
    public static function getLatestIdForBoard(int $boardId): int
    {
        global $DB;

        if ($boardId <= 0 || !$DB->tableExists('glpi_plugin_tralla_history')) {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['h.id AS max_id'],
            'FROM'   => 'glpi_plugin_tralla_history AS h',
            'INNER JOIN' => [
                'glpi_plugin_tralla_cards AS c' => [
                    'ON' => [
                        'h' => 'plugin_tralla_cards_id',
                        'c' => 'id',
                    ],
                ],
            ],
            'WHERE'  => ['c.plugin_tralla_boards_id' => $boardId],
            'ORDER'  => 'h.id DESC',
            'LIMIT'  => 1,
        ])->current();

        return (int) ($row['max_id'] ?? 0);
    }

    /**
     * Eventos novos do quadro para o log de notificações em tempo quase real.
     *
     * @return array{rows: array<int, array<string, mixed>>, last_id: int}
     */
    public static function getActivityForBoard(int $boardId, int $afterId = 0, int $limit = 20): array
    {
        global $DB;

        if ($boardId <= 0 || !PluginTrallaBoard::canViewBoard($boardId)) {
            throw new RuntimeException('Você não tem acesso a este quadro.');
        }

        $afterId = max(0, $afterId);
        if (!$DB->tableExists('glpi_plugin_tralla_history')) {
            return ['rows' => [], 'last_id' => $afterId];
        }

        $limit = max(1, min(50, $limit));
        $boardRow = $DB->request([
            'SELECT' => ['type'],
            'FROM'   => 'glpi_plugin_tralla_boards',
            'WHERE'  => ['id' => $boardId],
            'LIMIT'  => 1,
        ])->current();
        $boardType = is_array($boardRow) ? (string) ($boardRow['type'] ?? '') : '';

        $rows = [];
        $lastId = max(0, $afterId);
        $iterator = $DB->request([
            'SELECT' => [
                'h.id AS history_id',
                'h.plugin_tralla_cards_id AS card_id',
                'c.name AS card_name',
                'c.stage AS stage',
                'c.parent_cards_id AS parent_cards_id',
                'c.family_cards_id AS family_cards_id',
                'c.code_root_cards_id AS code_root_cards_id',
                'c.family_seq AS family_seq',
                'h.action AS action',
                'h.details AS details',
                'h.date_creation AS date_creation',
                'h.users_id AS users_id',
                'u.name AS user_login',
                'u.realname AS user_realname',
                'u.firstname AS user_firstname',
                'u.is_deleted AS user_deleted',
            ],
            'FROM'  => 'glpi_plugin_tralla_history AS h',
            'INNER JOIN' => [
                'glpi_plugin_tralla_cards AS c' => [
                    'ON' => [
                        'h' => 'plugin_tralla_cards_id',
                        'c' => 'id',
                    ],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_users AS u' => [
                    'ON' => [
                        'u' => 'id',
                        'h' => 'users_id',
                    ],
                ],
            ],
            'WHERE' => [
                'c.plugin_tralla_boards_id' => $boardId,
                'h.id'                      => ['>', $afterId],
            ],
            'ORDER' => 'h.id ASC',
            'LIMIT' => $limit,
        ]);

        foreach ($iterator as $row) {
            $lastId = max($lastId, (int) $row['history_id']);

            $userName = '';
            if ((int) ($row['users_id'] ?? 0) > 0 && (int) ($row['user_deleted'] ?? 0) !== 1) {
                $userName = trim(
                    (string) ($row['user_realname'] ?? '') . ' ' . (string) ($row['user_firstname'] ?? '')
                );
                if ($userName === '') {
                    $userName = (string) ($row['user_login'] ?? '');
                }
            }
            if ($userName === '') {
                $userName = (int) ($row['users_id'] ?? 0) > 0 ? 'Usuário removido' : 'Sistema';
            }

            $cardRow = [
                'id'              => (int) $row['card_id'],
                'stage'           => (int) $row['stage'],
                'parent_cards_id' => (int) $row['parent_cards_id'],
                'family_cards_id' => (int) $row['family_cards_id'],
                'code_root_cards_id' => (int) $row['code_root_cards_id'],
                'family_seq'      => (int) $row['family_seq'],
            ];

            $rows[] = [
                'id'             => (int) $row['history_id'],
                'card_id'        => (int) $row['card_id'],
                'card_code'      => tralla_display_code($cardRow, $boardType),
                'card_name'      => (string) $row['card_name'],
                'action'         => (string) $row['action'],
                'action_label'   => self::getActionLabel((string) $row['action']),
                'details'        => (string) $row['details'],
                'date_creation'  => (string) $row['date_creation'],
                'date_formatted' => tralla_date($row['date_creation']),
                'user_name'      => $userName,
                'users_id'       => (int) $row['users_id'],
            ];
        }

        return [
            'rows'    => $rows,
            'last_id' => $lastId,
        ];
    }

    public static function getActionLabel(string $action): string
    {
        $labels = [
            'board_created'         => 'Quadro criado',
            'card_created'          => 'Card criado',
            'card_updated'          => 'Card atualizado',
            'card_deleted'          => 'Card excluído',
            'card_deletion_requested' => 'Exclusão solicitada',
            'card_deletion_approved'  => 'Exclusão aprovada',
            'card_deletion_rejected'  => 'Exclusão recusada',
            'card_deletion_restored'  => 'Card restaurado',
            'card_moved'            => 'Card movido',
            'card_taken'            => 'Card pego pelo técnico',
            'call_release_requested' => 'Liberação do chamado solicitada',
            'call_released'         => 'Chamado liberado',
            'call_release_rejected' => 'Liberação do chamado recusada',
            'call_report_updated'   => 'Relatório do chamado atualizado',
            'machine_added'         => 'Máquina adicionada',
            'machine_updated'       => 'Máquina atualizada',
            'machine_deleted'       => 'Máquina removida',
            'card_finalized'        => 'Manutenção finalizada',
            'approval_created'      => 'Card de aprovação criado',
            'approval_approved'     => 'Manutenção aprovada',
            'approval_rejected'     => 'Manutenção reprovada',
            'withdrawal_confirmed'  => 'Retirada confirmada',
            'withdrawal_reminded'   => 'Lembrete de retirada enviado',
            'pending_notified'      => 'Notificação de pendente enviada',
            'card_split'            => 'Card separado',
            'split_requested'       => 'Separação solicitada',
            'split_approved'        => 'Separação aprovada',
            'split_rejected'        => 'Separação recusada',
            'family_merged'         => 'Máquinas reunidas na origem',
            'edit_requested'        => 'Edição solicitada',
            'edit_approved'         => 'Edição aprovada',
            'edit_rejected'         => 'Edição recusada',
            'family_withdrawal_ready' => 'Família liberada para retirada',
            'update_schedule'       => 'Atualização movida para Pendente',
            'update_take'           => 'Atualização assumida',
            'update_finish'         => 'Atualização concluída',
            'update_item_add'       => 'Item adicionado à checklist',
            'update_item_toggle'    => 'Item atualizado na checklist',
            'update_item_rename'    => 'Item renomeado',
            'update_item_delete'    => 'Item excluído da checklist',
            'file_added'            => 'Arquivo vinculado',
            'file_deleted'          => 'Arquivo removido',
        ];

        return $labels[$action] ?? $action;
    }
}
