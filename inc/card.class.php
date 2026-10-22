<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Card do Tralla (normal ou manutenção).
 */
class PluginTrallaCard extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public const MAX_MACHINE_QUANTITY = 999;

    public static function getTypeName($nb = 0)
    {
        return _n('Card', 'Cards', $nb, 'tralla');
    }

    public static function getCard(int $id): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    private static function assertBoardAccess(array $card): void
    {
        if (!PluginTrallaBoard::canViewBoard((int) ($card['plugin_tralla_boards_id'] ?? 0))) {
            throw new RuntimeException('Você não tem acesso a este card.');
        }
    }

    public static function getBoardType(int $boards_id): string
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['type'],
            'FROM'   => 'glpi_plugin_tralla_boards',
            'WHERE'  => ['id' => $boards_id],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) ? (string) $row['type'] : '';
    }

    public static function nextRankForList(int $lists_id): float
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'rank AS max_rank'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => ['plugin_tralla_lists_id' => $lists_id],
        ])->current();

        return (float) ($row['max_rank'] ?? 0) + 1024.0;
    }

    /**
     * Cards visíveis no quadro, com dados derivados para o Kanban.
     */
    public static function getCardsForBoard(int $boards_id): array
    {
        global $DB;

        if (!PluginTrallaBoard::canViewBoard($boards_id)) {
            throw new RuntimeException('Você não tem acesso a este quadro.');
        }

        $boardType = self::getBoardType($boards_id);
        $cards = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'plugin_tralla_boards_id' => $boards_id,
                'is_archived'             => 0,
            ],
            'ORDER' => 'rank ASC, id ASC',
        ]) as $row) {
            $cards[] = $row;
        }

        if ($cards === []) {
            return [];
        }

        $cardIds = array_map(static fn(array $row): int => (int) $row['id'], $cards);
        $machineCardIds = $cardIds;
        foreach ($cards as $card) {
            if ((int) $card['stage'] === 2 && (int) $card['parent_cards_id'] > 0) {
                $machineCardIds[] = (int) $card['parent_cards_id'];
            }
        }
        $machineCardIds = array_values(array_unique($machineCardIds));

        $machinesByCard = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_machines',
            'WHERE' => ['plugin_tralla_cards_id' => $machineCardIds],
            'ORDER' => 'rank ASC, id ASC',
        ]) as $machine) {
            $machinesByCard[(int) $machine['plugin_tralla_cards_id']][] = $machine;
        }

        $modelIds = [];
        foreach ($machinesByCard as $cardMachines) {
            foreach ($cardMachines as $machine) {
                $modelId = (int) ($machine['plugin_tralla_machine_models_id'] ?? 0);
                if ($modelId > 0) {
                    $modelIds[$modelId] = $modelId;
                }
            }
        }
        $modelNames = PluginTrallaMachineModel::getModelNames(array_values($modelIds));

        $lists = [];
        foreach (PluginTrallaList::getListsForBoard($boards_id) as $list) {
            $lists[(int) $list['id']] = $list;
        }

        $userId = (int) Session::getLoginUserID();
        $isAdmin = Session::haveRight(self::$rightname, PURGE);

        foreach ($cards as &$card) {
            $machineCardId = ((int) $card['stage'] === 2 && (int) $card['parent_cards_id'] > 0)
                ? (int) $card['parent_cards_id']
                : (int) $card['id'];
            $machines = $machinesByCard[$machineCardId] ?? [];
            $list = $lists[(int) $card['plugin_tralla_lists_id']] ?? [];
            $card['board_type'] = $boardType;
            $card['code'] = tralla_display_code($card, $boardType);
            $card['status_label'] = tralla_status_label((string) $card['status']);
            $card['source_label'] = tralla_source_label((string) $card['source']);
            $card['technician_name'] = tralla_user_name((int) $card['technician_id']);
            $card['creator_name'] = tralla_user_name((int) $card['users_id']);
            $card['category'] = (string) ($list['category'] ?? '');
            $card['list_name'] = (string) ($list['name'] ?? '');
            $card['machine_count'] = count($machines) ?: (int) $card['machines_total'];
            $card['machine_summary'] = PluginTrallaMachine::summarizeModels($machines, $modelNames);
            $card['progress'] = tralla_machines_progress($machines);
            $card['is_filled'] = tralla_all_machines_filled($machines);
            $card['permissions'] = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        }
        unset($card);

        return $cards;
    }

    /**
     * Detalhe completo do card (modal).
     */
    public static function getCardDetail(int $id, int $userId = 0): array
    {
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }

        $boards_id = (int) $card['plugin_tralla_boards_id'];
        if (!PluginTrallaBoard::canViewBoard($boards_id)) {
            throw new RuntimeException('Você não tem acesso a este card.');
        }

        $boardType = self::getBoardType($boards_id);
        $list = PluginTrallaList::getList((int) $card['plugin_tralla_lists_id']);
        $machines = $boardType === 'maintenance' ? PluginTrallaMachine::getMachinesForCard($id) : [];
        $parent = null;

        if ((int) $card['stage'] === 2 && (int) $card['parent_cards_id'] > 0) {
            $parentCard = self::getCard((int) $card['parent_cards_id']);
            if ($parentCard !== null) {
                $parent = [
                    'id'       => (int) $parentCard['id'],
                    'code'     => tralla_display_code($parentCard, $boardType),
                    'name'     => $parentCard['name'],
                    'status'   => $parentCard['status'],
                    'machines' => PluginTrallaMachine::getMachinesForCard((int) $parentCard['id']),
                ];
            }
        }

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }
        $isAdmin = Session::haveRight(self::$rightname, PURGE);

        $card['board_type'] = $boardType;
        $card['list_name'] = (string) ($list['name'] ?? '');
        $card['category'] = (string) ($list['category'] ?? '');
        $card['code'] = tralla_display_code($card, $boardType);
        $card['status_label'] = tralla_status_label((string) $card['status']);
        $card['source_label'] = tralla_source_label((string) $card['source']);
        $card['technician_name'] = tralla_user_name((int) $card['technician_id']);
        $card['creator_name'] = tralla_user_name((int) $card['users_id']);
        $card['approver_name'] = tralla_user_name((int) $card['users_id_approver']);
        $card['machines'] = $machines;
        $card['machines_progress'] = tralla_machines_progress($machines);
        $card['history'] = PluginTrallaHistory::getForCard($id);
        $card['parent'] = $parent;
        $card['assignature_url'] = ((string) $card['status'] === 'withdrawal')
            ? PluginTrallaConfig::assignatureCardUrl($id)
            : '';
        $card['permissions'] = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        $card['current_user_id'] = $userId;

        return $card;
    }

    public static function permissionsFor(array $card, string $boardType, int $userId, bool $isAdmin): array
    {
        $status = (string) ($card['status'] ?? '');
        $stage = (int) ($card['stage'] ?? 1);
        $locked = !empty($card['is_locked']);
        $isTechnician = (int) ($card['technician_id'] ?? 0) > 0
            && (int) $card['technician_id'] === $userId;
        $canUpdate = Session::haveRight(self::$rightname, UPDATE);

        $canEditMachines = $boardType === 'maintenance'
            && $stage === 1
            && $status === 'in_progress'
            && !$locked
            && ($isTechnician || $isAdmin);

        $canEditCard = $boardType === 'normal'
            ? $canUpdate
            : ($stage === 1 && $status === 'in_progress' && !$locked && ($isTechnician || $isAdmin));

        $canTake = $boardType === 'maintenance'
            && $stage === 1
            && $status === 'pending'
            && !$locked
            && $canUpdate;

        $canApprove = $boardType === 'maintenance'
            && $stage === 2
            && $status === 'waiting_approval'
            && self::userIsApprover($userId);

        $canConfirm = $boardType === 'maintenance'
            && $stage === 1
            && $status === 'withdrawal'
            && !$locked
            && ($isTechnician || $isAdmin || self::userIsApprover($userId));

        $canDelete = $stage !== 2
            && ($isAdmin
                || (Session::haveRight(self::$rightname, DELETE)
                    && ($boardType === 'normal'
                        || ($stage === 1 && $status === 'pending' && (int) $card['technician_id'] === 0))));

        return [
            'view'           => true,
            'edit_card'      => $canEditCard,
            'edit_machines'  => $canEditMachines,
            'add_machine'    => $canEditMachines,
            'delete_machine' => $canEditMachines,
            'take'           => $canTake,
            'finalize'       => $canEditMachines && tralla_all_machines_filled($card['machines'] ?? []),
            'approve'        => $canApprove,
            'reject'         => $canApprove,
            'withdraw'       => $canConfirm,
            'delete'         => $canDelete,
            'move'           => $boardType === 'normal' && $canUpdate,
        ];
    }

    public static function userIsApprover(int $userId = 0): bool
    {
        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        if (!class_exists('PluginTrallaProfile')
            || !Session::haveRight(PluginTrallaProfile::RIGHT_APPROVE, READ)) {
            return false;
        }

        $groupId = PluginTrallaConfig::approvalGroupId();
        if ($groupId > 0) {
            return countElementsInTable('glpi_groups_users', [
                'users_id'  => $userId,
                'groups_id' => $groupId,
            ]) > 0;
        }

        return true;
    }

    public static function create(array $data, int $userId = 0): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $boards_id = (int) ($data['boards_id'] ?? 0);
        if (!PluginTrallaBoard::canViewBoard($boards_id)) {
            throw new RuntimeException('Quadro inválido ou sem acesso.');
        }

        $boardType = self::getBoardType($boards_id);
        $name = tralla_normalize_label($data['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Informe o nome do card.');
        }

        $description = trim(strip_tags((string) ($data['description'] ?? '')));
        $source = (string) ($data['source'] ?? 'manual');
        if (!array_key_exists($source, tralla_source_choices())) {
            $source = 'manual';
        }

        $now = tralla_now();

        if ($boardType === 'maintenance') {
            $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_pending');
            if ($list === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Pendente".');
            }

            $requestedMachines = $data['machines'] ?? [];
            if (!is_array($requestedMachines) || $requestedMachines === []) {
                throw new RuntimeException('Informe ao menos uma máquina.');
            }

            $machines = [];
            foreach ($requestedMachines as $machine) {
                if (!is_array($machine)) {
                    throw new RuntimeException('Máquina inválida.');
                }

                $modelId = (int) ($machine['model_id'] ?? 0);
                if ($modelId <= 0 || !PluginTrallaMachineModel::canUseModel($modelId)) {
                    throw new RuntimeException('Selecione um modelo de máquina válido.');
                }

                $quantity = filter_var(
                    $machine['quantity'] ?? 1,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => self::MAX_MACHINE_QUANTITY]]
                );
                if ($quantity === false) {
                    throw new RuntimeException(
                        'Informe uma quantidade inteira entre 1 e ' . self::MAX_MACHINE_QUANTITY . '.'
                    );
                }

                $label = tralla_normalize_label($machine['label'] ?? '');
                $serial = tralla_normalize_label($machine['serial'] ?? '');
                for ($index = 0; $index < $quantity; $index++) {
                    $machines[] = [
                        'model_id' => $modelId,
                        'label'    => $quantity === 1 ? $label : '',
                        'serial'   => $quantity === 1 ? $serial : '',
                    ];
                }
            }

            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $list['id'],
                'name'                    => $name,
                'description'             => $description,
                'rank'                    => self::nextRankForList((int) $list['id']),
                'users_id'                => $userId,
                'technician_id'           => 0,
                'stage'                   => 1,
                'parent_cards_id'         => 0,
                'approval_cards_id'       => 0,
                'status'                  => 'pending',
                'is_locked'               => 0,
                'is_archived'             => 0,
                'source'                  => $source,
                'machines_total'          => count($machines),
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $cardId = (int) $DB->insertId();

            $rank = 1024.0;
            foreach ($machines as $machine) {
                PluginTrallaMachine::insertForCard($cardId, [
                    'model_id' => (int) $machine['model_id'],
                    'label'    => (string) ($machine['label'] ?? ''),
                    'serial'   => (string) ($machine['serial'] ?? ''),
                ], $rank);
                $rank += 1024.0;
            }

            PluginTrallaHistory::log($cardId, $userId, 'card_created', 'Card de manutenção criado com ' . count($machines) . ' máquina(s).');

            return self::getCardDetail($cardId, $userId);
        }

        $listId = (int) ($data['list_id'] ?? 0);
        $list = $listId > 0 ? PluginTrallaList::getList($listId) : null;
        if ($list === null || (int) $list['plugin_tralla_boards_id'] !== $boards_id) {
            $lists = PluginTrallaList::getListsForBoard($boards_id);
            $list = $lists[0] ?? null;
        }
        if ($list === null) {
            throw new RuntimeException('O quadro não possui listas.');
        }

        $DB->insert('glpi_plugin_tralla_cards', [
            'plugin_tralla_boards_id' => $boards_id,
            'plugin_tralla_lists_id'  => (int) $list['id'],
            'name'                    => $name,
            'description'             => $description,
            'rank'                    => self::nextRankForList((int) $list['id']),
            'users_id'                => $userId,
            'technician_id'           => 0,
            'stage'                   => 1,
            'parent_cards_id'         => 0,
            'approval_cards_id'       => 0,
            'status'                  => 'normal',
            'is_locked'               => 0,
            'is_archived'             => 0,
            'source'                  => $source,
            'machines_total'          => 0,
            'date_creation'           => $now,
            'date_mod'                => $now,
        ]);
        $cardId = (int) $DB->insertId();

        PluginTrallaHistory::log($cardId, $userId, 'card_created', 'Card criado.');

        return self::getCardDetail($cardId, $userId);
    }

    public static function updateCard(int $id, array $data, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boardType = self::getBoardType((int) $card['plugin_tralla_boards_id']);
        if ($boardType === 'maintenance') {
            $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
            if (empty($permissions['edit_card'])) {
                throw new RuntimeException('Somente o técnico responsável pode editar este card.');
            }
        } elseif (!Session::haveRight(self::$rightname, UPDATE)) {
            throw new RuntimeException('Sem permissão para editar o card.');
        }

        $fields = ['date_mod' => tralla_now()];
        if (array_key_exists('name', $data)) {
            $name = tralla_normalize_label($data['name']);
            if ($name === '') {
                throw new RuntimeException('Informe o nome do card.');
            }
            $fields['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = trim(strip_tags((string) $data['description']));
        }

        $DB->update('glpi_plugin_tralla_cards', $fields, ['id' => $id]);
        PluginTrallaHistory::log($id, $userId ?: (int) Session::getLoginUserID(), 'card_updated', 'Dados do card atualizados.');

        return self::getCardDetail($id, $userId);
    }

    public static function move(int $id, int $lists_id, float $rank = 0, int $userId = 0): array
    {
        global $DB;

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boards_id = (int) $card['plugin_tralla_boards_id'];
        $boardType = self::getBoardType($boards_id);
        if ($boardType !== 'normal') {
            throw new RuntimeException('Quadro de manutenção usa o fluxo controlado; arrastar está bloqueado.');
        }
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            throw new RuntimeException('Sem permissão para mover o card.');
        }

        $list = PluginTrallaList::getList($lists_id);
        if ($list === null || (int) $list['plugin_tralla_boards_id'] !== $boards_id) {
            throw new RuntimeException('Lista de destino inválida.');
        }

        $DB->update('glpi_plugin_tralla_cards', [
            'plugin_tralla_lists_id' => $lists_id,
            'rank'                   => $rank > 0 ? $rank : self::nextRankForList($lists_id),
            'date_mod'               => tralla_now(),
        ], ['id' => $id]);

        PluginTrallaHistory::log($id, $userId ?: (int) Session::getLoginUserID(), 'card_moved', 'Card movido para a lista "' . $list['name'] . '".');

        return self::getCardDetail($id, $userId);
    }

    public static function take(int $id, int $userId = 0): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);

        $boardType = self::getBoardType((int) $card['plugin_tralla_boards_id']);
        if (!tralla_can_transition($card, 'take', $boardType)) {
            throw new RuntimeException('Este card não está disponível para ser pego.');
        }
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            throw new RuntimeException('Sem permissão para pegar o card.');
        }

        $list = PluginTrallaBoard::findListByCategory((int) $card['plugin_tralla_boards_id'], 'maintenance_in_progress');
        if ($list === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Em Andamento".');
        }

        $now = tralla_now();
        $DB->update('glpi_plugin_tralla_cards', [
            'plugin_tralla_lists_id' => (int) $list['id'],
            'technician_id'          => $userId,
            'status'                 => 'in_progress',
            'date_taken'             => $now,
            'date_mod'               => $now,
            'rank'                   => self::nextRankForList((int) $list['id']),
        ], ['id' => $id]);

        PluginTrallaHistory::log($id, $userId, 'card_taken', 'Card pego por ' . tralla_user_name($userId) . '.');

        return self::getCardDetail($id, $userId);
    }

    public static function updateMachine(int $machineId, array $data, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        $machine = PluginTrallaMachine::getMachine($machineId);
        if ($machine === null) {
            throw new RuntimeException('Máquina não encontrada.');
        }
        $card = self::getCard((int) $machine['plugin_tralla_cards_id']);
        if ($card === null) {
            throw new RuntimeException('Card da máquina não encontrado.');
        }
        self::assertBoardAccess($card);
        $boardType = self::getBoardType((int) $card['plugin_tralla_boards_id']);
        $permissions = self::permissionsFor($card, $boardType, $userId ?: (int) Session::getLoginUserID(), $isAdmin);
        if (empty($permissions['edit_machines'])) {
            throw new RuntimeException('Somente o técnico responsável pode editar as máquinas.');
        }

        $oldStatus = (string) $machine['status'];
        $oldService = (int) $machine['service_done'];
        $oldInventory = (int) $machine['needs_inventory'];

        PluginTrallaMachine::updateMachine($machineId, $data);
        $DB->update('glpi_plugin_tralla_cards', ['date_mod' => tralla_now()], ['id' => (int) $card['id']]);

        if (array_key_exists('status', $data)
            || array_key_exists('service_done', $data)
            || array_key_exists('needs_inventory', $data)) {
            $new = PluginTrallaMachine::getMachine($machineId);
            if ($new !== null && (
                $oldStatus !== (string) $new['status']
                || $oldService !== (int) $new['service_done']
                || $oldInventory !== (int) $new['needs_inventory']
            )) {
                $machineLabel = (string) ($new['label'] ?? '');
                if ($machineLabel === '') {
                    $model = PluginTrallaMachineModel::getModel((int) $new['plugin_tralla_machine_models_id'], true);
                    $machineLabel = (string) ($model['name'] ?? ('#' . $machineId));
                }
                PluginTrallaHistory::log((int) $card['id'], $userId ?: (int) Session::getLoginUserID(), 'machine_updated', 'Máquina "' . $machineLabel . '" atualizada.');
            }
        }

        $machines = PluginTrallaMachine::getMachinesForCard((int) $card['id']);

        return [
            'machine'  => PluginTrallaMachine::getMachine($machineId),
            'progress' => tralla_machines_progress($machines),
            'all_filled' => tralla_all_machines_filled($machines),
        ];
    }

    public static function addMachine(int $cardId, array $data, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        $card = self::getCard($cardId);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boardType = self::getBoardType((int) $card['plugin_tralla_boards_id']);
        $permissions = self::permissionsFor($card, $boardType, $userId ?: (int) Session::getLoginUserID(), $isAdmin);
        if (empty($permissions['add_machine'])) {
            throw new RuntimeException('Não é possível adicionar máquinas neste estado.');
        }

        $modelId = (int) ($data['model_id'] ?? 0);
        if ($modelId <= 0 || !PluginTrallaMachineModel::canUseModel($modelId)) {
            throw new RuntimeException('Selecione um modelo de máquina válido.');
        }

        PluginTrallaMachine::insertForCard($cardId, [
            'model_id' => $modelId,
            'label'    => (string) ($data['label'] ?? ''),
            'serial'   => (string) ($data['serial'] ?? ''),
        ]);
        PluginTrallaMachine::refreshCardTotal($cardId);
        PluginTrallaHistory::log($cardId, $userId ?: (int) Session::getLoginUserID(), 'machine_added', 'Máquina adicionada ao card.');
        $DB->update('glpi_plugin_tralla_cards', ['date_mod' => tralla_now()], ['id' => $cardId]);

        return self::getCardDetail($cardId, $userId);
    }

    public static function deleteMachine(int $machineId, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        $machine = PluginTrallaMachine::getMachine($machineId);
        if ($machine === null) {
            throw new RuntimeException('Máquina não encontrada.');
        }
        $cardId = (int) $machine['plugin_tralla_cards_id'];
        $card = self::getCard($cardId);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boardType = self::getBoardType((int) $card['plugin_tralla_boards_id']);
        $permissions = self::permissionsFor($card, $boardType, $userId ?: (int) Session::getLoginUserID(), $isAdmin);
        if (empty($permissions['delete_machine'])) {
            throw new RuntimeException('Não é possível remover máquinas neste estado.');
        }

        PluginTrallaMachine::deleteMachine($machineId);
        PluginTrallaMachine::refreshCardTotal($cardId);
        PluginTrallaHistory::log($cardId, $userId ?: (int) Session::getLoginUserID(), 'machine_deleted', 'Máquina removida do card.');
        $DB->update('glpi_plugin_tralla_cards', ['date_mod' => tralla_now()], ['id' => $cardId]);

        return self::getCardDetail($cardId, $userId);
    }

    public static function finalize(int $id, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boards_id = (int) $card['plugin_tralla_boards_id'];
        $boardType = self::getBoardType($boards_id);
        if (!tralla_can_transition($card, 'finalize', $boardType)) {
            throw new RuntimeException('Este card não está em andamento.');
        }

        $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        if (empty($permissions['edit_machines'])) {
            throw new RuntimeException('Somente o técnico responsável pode finalizar o card.');
        }

        $machines = PluginTrallaMachine::getMachinesForCard($id);
        if (!tralla_all_machines_filled($machines)) {
            throw new RuntimeException('Preencha o relatório e o status de todas as máquinas antes de finalizar.');
        }

        $approvalList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
        if ($approvalList === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
        }

        $now = tralla_now();
        $DB->insert('glpi_plugin_tralla_cards', [
            'plugin_tralla_boards_id' => $boards_id,
            'plugin_tralla_lists_id'  => (int) $approvalList['id'],
            'name'                    => $card['name'],
            'description'             => $card['description'],
            'rank'                    => self::nextRankForList((int) $approvalList['id']),
            'users_id'                => $userId,
            'technician_id'           => (int) $card['technician_id'],
            'stage'                   => 2,
            'parent_cards_id'         => $id,
            'approval_cards_id'       => 0,
            'status'                  => 'waiting_approval',
            'is_locked'               => 0,
            'is_archived'             => 0,
            'source'                  => (string) $card['source'],
            'machines_total'          => count($machines),
            'date_finalized'          => $now,
            'date_creation'           => $now,
            'date_mod'                => $now,
        ]);
        $approvalId = (int) $DB->insertId();

        $DB->update('glpi_plugin_tralla_cards', [
            'status'            => 'waiting_approval',
            'is_locked'         => 1,
            'approval_cards_id' => $approvalId,
            'date_finalized'    => $now,
            'date_mod'          => $now,
        ], ['id' => $id]);

        PluginTrallaHistory::log($id, $userId, 'card_finalized', 'Manutenção finalizada; card de aprovação ' . $id . '/2 criado.');
        PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando decisão de aprovação.');

        return [
            'card'     => self::getCardDetail($id, $userId),
            'approval' => self::getCardDetail($approvalId, $userId),
        ];
    }

    public static function decide(int $approvalId, bool $approve, string $reason = '', int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $approval = self::getCard($approvalId);
        if ($approval === null || (int) $approval['stage'] !== 2) {
            throw new RuntimeException('Card de aprovação não encontrado.');
        }
        if (!tralla_can_transition($approval, $approve ? 'approve' : 'reject', 'maintenance')) {
            throw new RuntimeException('Este card não está aguardando aprovação.');
        }
        if (!self::userIsApprover($userId)) {
            throw new RuntimeException('Sem permissão para aprovar ou reprovar.');
        }

        $parentId = (int) $approval['parent_cards_id'];
        $parent = self::getCard($parentId);
        if ($parent === null) {
            throw new RuntimeException('Card de manutenção de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        if ((string) $parent['status'] !== 'waiting_approval') {
            throw new RuntimeException('O card de origem não está aguardando aprovação.');
        }
        $boards_id = (int) $parent['plugin_tralla_boards_id'];
        $now = tralla_now();
        $reason = trim(strip_tags($reason));

        if ($approve) {
            $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_withdrawal');
            if ($list === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Retirada".');
            }

            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'status'                 => 'withdrawal',
                'is_locked'              => 0,
                'users_id_approver'      => $userId,
                'date_approved'          => $now,
                'assignature_sent'       => 1,
                'withdrawal_reminded_at' => null,
                'rejection_reason'       => null,
                'date_mod'               => $now,
                'rank'                   => self::nextRankForList((int) $list['id']),
            ], ['id' => $parentId]);

            $DB->update('glpi_plugin_tralla_cards', [
                'status'            => 'approved',
                'is_archived'       => 1,
                'users_id_approver' => $userId,
                'date_mod'          => $now,
            ], ['id' => $approvalId]);

            PluginTrallaHistory::log($parentId, $userId, 'approval_approved', 'Manutenção aprovada. Card liberado para retirada.');
            PluginTrallaHistory::log($approvalId, $userId, 'approval_approved', 'Aprovação concluída.');
        } else {
            $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_in_progress');
            if ($list === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Em Andamento".');
            }

            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'status'                 => 'in_progress',
                'is_locked'              => 0,
                'users_id_approver'      => $userId,
                'rejection_reason'       => $reason !== '' ? $reason : null,
                'date_mod'               => $now,
                'rank'                   => self::nextRankForList((int) $list['id']),
            ], ['id' => $parentId]);

            $DB->update('glpi_plugin_tralla_cards', [
                'status'            => 'rejected',
                'is_archived'       => 1,
                'users_id_approver' => $userId,
                'rejection_reason'  => $reason !== '' ? $reason : null,
                'date_mod'          => $now,
            ], ['id' => $approvalId]);

            PluginTrallaHistory::log($parentId, $userId, 'approval_rejected', 'Manutenção reprovada; card voltou para Em Andamento.' . ($reason !== '' ? ' Motivo: ' . $reason : ''));
            PluginTrallaHistory::log($approvalId, $userId, 'approval_rejected', 'Aprovação recusada.');
        }

        return self::getCardDetail($parentId, $userId);
    }

    public static function confirmWithdrawal(int $id, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boards_id = (int) $card['plugin_tralla_boards_id'];
        $boardType = self::getBoardType($boards_id);
        if (!tralla_can_transition($card, 'withdraw', $boardType)) {
            throw new RuntimeException('Este card não está aguardando retirada.');
        }

        $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        if (empty($permissions['withdraw'])) {
            throw new RuntimeException('Sem permissão para confirmar a retirada.');
        }

        $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_done');
        if ($list === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Concluído".');
        }

        $now = tralla_now();
        $DB->update('glpi_plugin_tralla_cards', [
            'plugin_tralla_lists_id' => (int) $list['id'],
            'status'                 => 'done',
            'date_withdrawn'         => $now,
            'date_mod'               => $now,
            'rank'                   => self::nextRankForList((int) $list['id']),
        ], ['id' => $id]);

        PluginTrallaHistory::log($id, $userId, 'withdrawal_confirmed', 'Retirada confirmada; manutenção concluída.');

        return self::getCardDetail($id, $userId);
    }

    public static function deleteCard(int $id): void
    {
        global $DB;

        $card = self::getCard($id);
        if ($card === null) {
            return;
        }

        $childIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => [
                'parent_cards_id' => $id,
                'stage'           => 2,
            ],
        ]) as $child) {
            $childIds[] = (int) $child['id'];
        }
        foreach ($childIds as $childId) {
            self::deleteCard($childId);
        }

        $DB->delete('glpi_plugin_tralla_machines', ['plugin_tralla_cards_id' => $id]);
        $DB->delete('glpi_plugin_tralla_history', ['plugin_tralla_cards_id' => $id]);
        $DB->delete('glpi_plugin_tralla_notifications', ['plugin_tralla_cards_id' => $id]);
        $DB->delete('glpi_plugin_tralla_cards', ['id' => $id]);
    }
}
