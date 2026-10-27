<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/file.class.php';
require_once __DIR__ . '/update.class.php';

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
                'is_deleted'              => 0,
            ],
            'ORDER' => 'rank ASC, id ASC',
        ]) as $row) {
            if ((string) ($row['status'] ?? '') === 'deletion_pending') {
                continue;
            }
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

        $updateProgress = [];
        if ($boardType === 'updates') {
            foreach ($DB->request([
                'FROM' => 'glpi_plugin_tralla_update_items',
                'WHERE' => ['plugin_tralla_cards_id' => $cardIds],
            ]) as $item) {
                $itemCardId = (int) $item['plugin_tralla_cards_id'];
                $updateProgress[$itemCardId]['total'] = ($updateProgress[$itemCardId]['total'] ?? 0) + 1;
                $updateProgress[$itemCardId]['done'] = ($updateProgress[$itemCardId]['done'] ?? 0)
                    + (int) $item['is_done'];
            }
        }

        $lists = [];
        foreach (PluginTrallaList::getListsForBoard($boards_id) as $list) {
            $lists[(int) $list['id']] = $list;
        }

        $userId = (int) Session::getLoginUserID();
        $isAdmin = Session::haveRight(self::$rightname, PURGE);

        $familyBusy = [];
        $pendingApprovalKinds = [];
        foreach ($cards as $row) {
            $root = self::familyRoot($row);
            if ($root <= 0) {
                continue;
            }
            if ((int) ($row['stage'] ?? 1) === 2) {
                $familyBusy[$root] = true;
                if ((int) ($row['parent_cards_id'] ?? 0) > 0) {
                    $pendingApprovalKinds[(int) $row['parent_cards_id']]
                        = (string) ($row['approval_kind'] ?? 'maintenance');
                }
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (!in_array($status, ['pending', 'in_progress'], true)
                || !empty($row['is_locked'])
                || (int) ($row['approval_cards_id'] ?? 0) > 0) {
                $familyBusy[$root] = true;
            }
        }

        foreach ($cards as &$card) {
            $machineCardId = ((int) $card['stage'] === 2 && (int) $card['parent_cards_id'] > 0)
                ? (int) $card['parent_cards_id']
                : (int) $card['id'];
            $machines = $machinesByCard[$machineCardId] ?? [];
            if ((int) $card['stage'] === 2 && (string) ($card['approval_kind'] ?? '') === 'split') {
                $payload = json_decode((string) ($card['edit_payload'] ?? ''), true);
                $selectedIds = is_array($payload)
                    ? array_fill_keys(array_map('intval', (array) ($payload['machine_ids'] ?? [])), true)
                    : [];
                $machines = array_values(array_filter(
                    $machines,
                    static fn (array $machine): bool => isset($selectedIds[(int) $machine['id']])
                ));
            }
            $list = $lists[(int) $card['plugin_tralla_lists_id']] ?? [];
            $card['board_type'] = $boardType;
            $card['code'] = tralla_display_code($card, $boardType);
            $card['approval_kind_label'] = '';
            $card['pending_approval_kind'] = '';
            if ((int) $card['stage'] === 2) {
                $card['approval_kind_label'] = tralla_approval_kind_label(
                    (string) ($card['approval_kind'] ?? 'maintenance')
                );
                $card['status_label'] = $card['approval_kind_label'];
            } else {
                $card['status_label'] = tralla_status_label((string) $card['status']);
                if ((string) ($card['workflow_type'] ?? '') === 'call'
                    && (string) $card['status'] === 'pending' && !empty($card['is_locked'])) {
                    $card['status_label'] = (int) ($card['approval_cards_id'] ?? 0) > 0
                        ? 'Aguardando liberação' : 'Liberação recusada';
                }
                $card['pending_approval_kind'] = $pendingApprovalKinds[(int) $card['id']] ?? '';
            }
            $card['source_label'] = tralla_source_label((string) $card['source']);
            $card['technician_name'] = tralla_user_name((int) $card['technician_id']);
            $card['technician_picture_url'] = tralla_user_picture_url((int) $card['technician_id']);
            $card['creator_name'] = tralla_user_name((int) $card['users_id']);
            $card['category'] = (string) ($list['category'] ?? '');
            $card['list_name'] = (string) ($list['name'] ?? '');
            $card['assignature_term_url'] = (string) $card['status'] === 'done' && !empty($card['assignature_sent'])
                ? PluginTrallaConfig::assignatureTermUrl((int) $card['id'])
                : '';
            $card['machine_count'] = count($machines) ?: (int) $card['machines_total'];
            $card['machine_summary'] = PluginTrallaMachine::summarizeModels($machines, $modelNames);
            $card['progress'] = tralla_machines_progress($machines);
            $card['is_filled'] = tralla_all_machines_filled($machines);
            if ($boardType === 'updates') {
                $card['update_progress'] = $updateProgress[(int) $card['id']] ?? ['total' => 0, 'done' => 0];
            }
            $card['permissions'] = self::permissionsFor($card, $boardType, $userId, $isAdmin);
            if ((int) $card['stage'] === 1) {
                $root = self::familyRoot($card);
                if (!empty($familyBusy[$root]) || (int) $card['machine_count'] < 2) {
                    $card['permissions']['split'] = false;
                }
            }
        }
        unset($card);

        return $cards;
    }

    /**
     * Detalhe completo do card (modal).
     */
    public static function getCardDetail(int $id, int $userId = 0): array
    {
        global $DB;

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
        $parentCard = null;

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
        if ((int) $card['stage'] === 1 && (string) ($card['workflow_type'] ?? '') === 'call'
            && (string) $card['status'] === 'pending' && !empty($card['is_locked'])) {
            $card['status_label'] = (int) ($card['approval_cards_id'] ?? 0) > 0
                ? 'Aguardando liberação' : 'Liberação recusada';
        }
        $card['approval_kind_label'] = '';
        if ((int) $card['stage'] === 2) {
            $card['approval_kind_label'] = tralla_approval_kind_label(
                (string) ($card['approval_kind'] ?? 'maintenance')
            );
            $card['status_label'] = $card['approval_kind_label'];
        }
        $card['source_label'] = tralla_source_label((string) $card['source']);
        $card['technician_name'] = tralla_user_name((int) $card['technician_id']);
        $card['technician_picture_url'] = tralla_user_picture_url((int) $card['technician_id']);
        $card['creator_name'] = tralla_user_name((int) $card['users_id']);
        $card['approver_name'] = tralla_user_name((int) $card['users_id_approver']);
        $card['machines'] = $machines;
        $card['machines_progress'] = tralla_machines_progress($machines);
        $card['history'] = PluginTrallaHistory::getForCard($id);
        $card['parent'] = $parent;
        $card['family_ready'] = false;
        $card['family_members'] = [];
        $card['pending_approval_kind'] = '';
        $card['pending_approval_id'] = 0;
        $card['split_from'] = null;
        $rawEditPayload = (string) ($card['edit_payload'] ?? '');
        $card['edit_payload'] = null;
        $card['edit_changes'] = [];
        $card['split_machine_ids'] = [];

        if ((int) $card['stage'] === 1) {
            $root = self::familyRoot($card);
            if ($root > 0) {
                foreach ($DB->request([
                    'FROM'  => 'glpi_plugin_tralla_cards',
                    'WHERE' => [
                        'family_cards_id' => $root,
                        'stage'           => 1,
                        'is_deleted'      => 0,
                        'is_archived'     => 0,
                    ],
                    'ORDER' => 'family_seq ASC, id ASC',
                ]) as $member) {
                    $memberList = PluginTrallaList::getList((int) ($member['plugin_tralla_lists_id'] ?? 0));
                    $card['family_members'][] = [
                        'id'              => (int) $member['id'],
                        'code'            => tralla_display_code($member, $boardType),
                        'name'            => (string) $member['name'],
                        'status'          => (string) $member['status'],
                        'status_label'    => tralla_status_label((string) $member['status']),
                        'technician_name' => tralla_user_name((int) ($member['technician_id'] ?? 0)),
                        'list_name'       => (string) ($memberList['name'] ?? ''),
                        'is_current'      => ((int) $member['id'] === $id),
                    ];
                }
            }
        }

        if ((int) $card['stage'] === 1 && (int) ($card['approval_cards_id'] ?? 0) > 0) {
            $pendingApproval = self::getCard((int) $card['approval_cards_id']);
            if ($pendingApproval !== null && empty($pendingApproval['is_archived'])) {
                $card['pending_approval_kind'] = (string) ($pendingApproval['approval_kind'] ?? 'maintenance');
                $card['pending_approval_id'] = (int) $pendingApproval['id'];
            }
        }

        if ((int) ($card['split_from_cards_id'] ?? 0) > 0) {
            $origin = self::getCard((int) $card['split_from_cards_id']);
            if ($origin !== null) {
                $card['split_from'] = [
                    'id'   => (int) $origin['id'],
                    'code' => tralla_display_code($origin, $boardType),
                    'name' => (string) $origin['name'],
                ];
            }
        }

        if ((int) $card['stage'] === 2 && (string) ($card['approval_kind'] ?? '') === 'edit') {
            $payload = json_decode($rawEditPayload, true);
            if (is_array($payload)) {
                $card['edit_payload'] = $payload;
                if ($parentCard !== null) {
                    $card['edit_changes'] = self::buildEditChanges($parentCard, $payload);
                }
            }
        }

        if ((int) $card['stage'] === 2 && (string) ($card['approval_kind'] ?? '') === 'split') {
            $payload = json_decode($rawEditPayload, true);
            if (is_array($payload)) {
                $card['split_machine_ids'] = array_values(array_filter(array_map(
                    'intval', (array) ($payload['machine_ids'] ?? [])
                )));
            }
        }

        $card['assignature_url'] = ((string) $card['status'] === 'withdrawal' || !empty($card['assignature_sent']))
            ? PluginTrallaConfig::assignatureCardUrl($id)
            : '';
        $card['assignature_term_url'] = (string) $card['status'] === 'done' && !empty($card['assignature_sent'])
            ? PluginTrallaConfig::assignatureTermUrl($id)
            : '';
        $card['permissions'] = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        $card['update_items'] = [];
        $card['update_files'] = [];
        if ($boardType === 'updates' && (string) ($card['workflow_type'] ?? '') === 'update') {
            $card['update_items'] = PluginTrallaUpdate::items($id);
            $card['update_files'] = PluginTrallaFile::listForCard($id, $userId);
            $card['permissions']['upload_update_file'] = PluginTrallaFile::canUpload($card, $userId);
        }
        $card['call_files'] = [];
        $card['permissions']['upload_call_file'] = false;
        if ($boardType === 'maintenance' && (string) ($card['workflow_type'] ?? '') === 'call') {
            $fileCard = (int) $card['stage'] === 2 ? $parentCard : $card;
            if (is_array($fileCard) && (int) ($fileCard['stage'] ?? 0) === 1) {
                $fileCardId = (int) $fileCard['id'];
                $card['call_files'] = PluginTrallaFile::listForCard($fileCardId, $userId);
                if ((int) $card['stage'] === 2) {
                    foreach ($card['call_files'] as &$callFile) {
                        $callFile['can_delete'] = false;
                    }
                    unset($callFile);
                }
                $card['permissions']['upload_call_file'] = (int) $card['stage'] === 1
                    && PluginTrallaFile::canUpload($card, $userId);
            }
        }
        if ((int) $card['stage'] === 1 && !empty($card['permissions']['split'])) {
            $root = self::familyRoot($card);
            if (count($machines) < 2 || !self::familyIsIdle($root)) {
                $card['permissions']['split'] = false;
            }
        }
        $card['current_user_id'] = $userId;

        return $card;
    }

    public static function permissionsFor(array $card, string $boardType, int $userId, bool $isAdmin): array
    {
        $boardId = (int) ($card['plugin_tralla_boards_id'] ?? 0);
        $role = $isAdmin ? 'superadmin' : PluginTrallaBoard::roleFor($boardId, $userId);
        $isMember = $role !== '';
        $status = (string) ($card['status'] ?? '');
        $isCall = (string) ($card['workflow_type'] ?? 'maintenance') === 'call';
        $stage = (int) ($card['stage'] ?? 1);
        $locked = !empty($card['is_locked']);
        $isTechnician = (int) ($card['technician_id'] ?? 0) > 0
            && (int) $card['technician_id'] === $userId;
        $canUpdate = Session::haveRight(self::$rightname, UPDATE);
        $isBoardAdmin = $isAdmin || tralla_role_at_least($role, 'admin');

        if ($boardType === 'updates') {
            $manager = $isBoardAdmin || (int) ($card['users_id'] ?? 0) === $userId;
            $inProgressOwner = $status === 'update_in_progress' && $isTechnician;
            return [
                'view' => true,
                'update_schedule' => $isMember && $canUpdate && $manager && $status === 'update_future',
                'update_take' => $isMember && $canUpdate && $status === 'update_pending',
                'update_finish' => $isMember && $canUpdate && $inProgressOwner,
                'update_edit_brief' => $isMember && $canUpdate && $manager
                    && in_array($status, ['update_future', 'update_pending'], true),
                'update_edit_work' => $isMember && $canUpdate && $inProgressOwner,
                'request_deletion' => false,
                'move' => false,
            ];
        }

        $canEditMachines = $isMember
            && $boardType === 'maintenance'
            && $stage === 1
            && $status === 'in_progress'
            && !$locked
            && $isTechnician;
        $canFinalize = $isMember
            && $boardType === 'maintenance'
            && $stage === 1
            && $status === 'in_progress'
            && !$locked
            && ($isTechnician || $isAdmin)
            && ($isCall && ($card['machines'] ?? []) === []
                ? trim((string) ($card['service_report'] ?? '')) !== ''
                : (tralla_all_machines_filled($card['machines'] ?? [])
                    && tralla_finalization_partition($card['machines'] ?? [])['ready'] !== []
                    && (!$isCall || tralla_finalization_partition($card['machines'] ?? [])['pending'] === [])));

        $canEditCard = $isMember
            && $boardType === 'normal'
            && $canUpdate;

        $canSplit = $isMember
            && !$isCall
            && $boardType === 'maintenance'
            && $stage === 1
            && in_array($status, ['pending', 'in_progress'], true)
            && !$locked
            && (int) ($card['approval_cards_id'] ?? 0) === 0
            && $canUpdate;
        $canEditRequest = $isMember
            && $boardType === 'maintenance'
            && $stage === 1
            && in_array($status, ['in_progress', 'withdrawal'], true)
            && !$locked
            && (int) ($card['approval_cards_id'] ?? 0) === 0
            && $canUpdate
            && ($isTechnician || $isBoardAdmin);

        $canTake = $isMember
            && $boardType === 'maintenance'
            && $stage === 1
            && $status === 'pending'
            && !$locked
            && $canUpdate;

        $canRequestCallRelease = PluginTrallaBoardUser::getRole($boardId, $userId) === 'superadmin'
            && $boardType === 'maintenance'
            && $isCall
            && $stage === 1
            && $status === 'pending'
            && $locked
            && empty($card['is_archived'])
            && (int) ($card['approval_cards_id'] ?? 0) === 0;

        $canApprove = $isMember
            && $boardType === 'maintenance'
            && $stage === 2
            && $status === 'waiting_approval'
            && self::userIsApprover($userId, $boardId);

        $canConfirm = $isMember
            && !$isCall
            && $boardType === 'maintenance'
            && $stage === 1
            && $status === 'withdrawal'
            && !$locked
            && (PluginTrallaConfig::assignatureUrl() === '' || !empty($card['assignature_sent']))
            && ($isTechnician || $isAdmin || self::userIsApprover($userId, $boardId));

        $canRequestDeletion = tralla_can_request_deletion(
            $card,
            $isMember,
            Session::haveRight(self::$rightname, DELETE),
            $isAdmin
        );

        return [
            'view'             => true,
            'edit_card'        => $canEditCard,
            'edit_machines'    => $canEditMachines,
            'add_machine'      => $canEditMachines && !$isCall,
            'delete_machine'   => $canEditMachines && !$isCall,
            'split'            => $canSplit,
            'edit_request'     => $canEditRequest,
            'take'             => $canTake,
            'request_call_release' => $canRequestCallRelease,
            'finalize'         => $canFinalize,
            'approve'          => $canApprove,
            'reject'           => $canApprove,
            'withdraw'         => $canConfirm,
            'request_deletion' => $canRequestDeletion,
            'move'             => $isMember && $boardType === 'normal' && $canUpdate,
        ];
    }

    public static function userIsApprover(int $userId = 0, int $boardId = 0): bool
    {
        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        if (!class_exists('PluginTrallaProfile')
            || !Session::haveRight(PluginTrallaProfile::RIGHT_APPROVE, READ)) {
            return false;
        }

        if ($boardId <= 0) {
            return false;
        }

        return PluginTrallaBoardUser::isApprover($boardId, $userId);
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
        if ($boardType === 'updates') {
            throw new RuntimeException('Use o fluxo de Atualizações para criar cards neste quadro.');
        }
        $name = tralla_normalize_label($data['name'] ?? '');
        $workflowType = $boardType === 'maintenance' ? (string) ($data['workflow_type'] ?? 'maintenance') : 'maintenance';
        if (!in_array($workflowType, ['maintenance', 'call'], true)) {
            throw new RuntimeException('Tipo de card inválido.');
        }
        if ($name === '') {
            throw new RuntimeException('Informe o nome do card.');
        }
        if ($workflowType === 'call') {
            $entityId = (int) ($data['entity_id'] ?? 0);
            $entity = $entityId > 0 ? $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM' => 'glpi_entities',
                'WHERE' => ['id' => $entityId],
                'LIMIT' => 1,
            ])->current() : null;
            if (!is_array($entity) || tralla_normalize_label((string) $entity['name']) !== $name) {
                throw new RuntimeException('Selecione uma entidade da lista para o nome do chamado.');
            }
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
            $deferCall = $workflowType === 'call' && (string) ($data['release_mode'] ?? 'now') === 'later';
            $approvalList = null;
            if ($deferCall) {
                if (PluginTrallaBoardUser::getRole($boards_id, $userId) !== 'superadmin') {
                    throw new RuntimeException('Somente quem tem o papel SuperAdmin neste quadro pode disponibilizar chamados depois.');
                }
                $approvalList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
                if ($approvalList === null) {
                    throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
                }
            }

            $requestedMachines = $data['machines'] ?? [];
            if ($workflowType === 'call' && $description === '') {
                throw new RuntimeException('Descreva a situação do chamado.');
            }
            if ($workflowType !== 'call' && (!is_array($requestedMachines) || $requestedMachines === [])) {
                throw new RuntimeException('Informe ao menos uma máquina.');
            }
            if ($workflowType === 'call') {
                $requestedMachines = [];
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

            if ($deferCall) {
                $DB->beginTransaction();
            }
            try {
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
                'family_cards_id'         => 0,
                'code_root_cards_id'      => 0,
                'family_seq'              => 1,
                'split_from_cards_id'     => 0,
                'status'                  => 'pending',
                'workflow_type'           => $workflowType,
                'is_locked'               => $deferCall ? 1 : 0,
                'is_archived'             => 0,
                'source'                  => $source,
                'machines_total'          => count($machines),
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $cardId = (int) $DB->insertId();

            $DB->update('glpi_plugin_tralla_cards', [
                'family_cards_id' => $cardId,
                'code_root_cards_id' => $cardId,
                'family_seq'      => 1,
            ], ['id' => $cardId]);

            $rank = 1024.0;
            foreach ($machines as $machine) {
                PluginTrallaMachine::insertForCard($cardId, [
                    'model_id' => (int) $machine['model_id'],
                    'label'    => (string) ($machine['label'] ?? ''),
                    'serial'   => (string) ($machine['serial'] ?? ''),
                ], $rank);
                $rank += 1024.0;
            }

            if ($deferCall) {
                $approvalId = self::createCallReleaseApproval($cardId, $boards_id, $approvalList, $userId, $now);
                $DB->update('glpi_plugin_tralla_cards', ['approval_cards_id' => $approvalId], ['id' => $cardId]);
                PluginTrallaHistory::log($cardId, $userId, 'call_release_requested', 'Chamado criado bloqueado; aguardando liberação.');
            }

            PluginTrallaHistory::log($cardId, $userId, 'card_created',
                ($workflowType === 'call' ? 'Chamado' : 'Card de manutenção') . ' criado com ' . count($machines) . ' máquina(s).');
            if ($deferCall) {
                $DB->commit();
            }
            } catch (Throwable $e) {
                if ($deferCall) {
                    $DB->rollBack();
                }
                throw $e;
            }

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

    private static function createCallReleaseApproval(int $cardId, int $boardId, array $list, int $userId, string $now): int
    {
        global $DB;
        $card = self::getCard($cardId);
        if ($card === null) {
            throw new RuntimeException('Chamado não encontrado.');
        }
        $sequence = self::nextCodeSequence($cardId);
        $DB->insert('glpi_plugin_tralla_cards', [
            'plugin_tralla_boards_id' => $boardId,
            'plugin_tralla_lists_id' => (int) $list['id'],
            'name' => (string) $card['name'],
            'description' => (string) $card['description'],
            'rank' => self::nextRankForList((int) $list['id']),
            'users_id' => $userId,
            'technician_id' => 0,
            'stage' => 2,
            'parent_cards_id' => $cardId,
            'family_cards_id' => $cardId,
            'code_root_cards_id' => $cardId,
            'family_seq' => $sequence,
            'approval_kind' => 'call_release',
            'workflow_type' => 'call',
            'status' => 'waiting_approval',
            'is_locked' => 0,
            'is_archived' => 0,
            'source' => (string) $card['source'],
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        $approvalId = (int) $DB->insertId();
        PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando liberação do chamado.');
        return $approvalId;
    }

    public static function requestCallRelease(int $cardId, int $userId = 0): array
    {
        global $DB;
        $userId = $userId ?: (int) Session::getLoginUserID();
        $card = self::getCard($cardId);
        if ($card === null) {
            throw new RuntimeException('Chamado não encontrado.');
        }
        self::assertBoardAccess($card);
        $boardId = (int) $card['plugin_tralla_boards_id'];
        if (PluginTrallaBoardUser::getRole($boardId, $userId) !== 'superadmin') {
            throw new RuntimeException('Somente quem tem o papel SuperAdmin neste quadro pode solicitar a liberação.');
        }
        $approvalList = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_approval');
        if ($approvalList === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($cardId);
            $card = self::getCard($cardId);
            if ($card === null || (string) ($card['workflow_type'] ?? '') !== 'call'
                || (int) $card['stage'] !== 1 || (string) $card['status'] !== 'pending'
                || empty($card['is_locked']) || !empty($card['is_archived'])
                || (int) ($card['approval_cards_id'] ?? 0) !== 0) {
                throw new RuntimeException('Este chamado não está disponível para uma nova solicitação.');
            }
            $now = tralla_now();
            $approvalId = self::createCallReleaseApproval($cardId, $boardId, $approvalList, $userId, $now);
            $DB->update('glpi_plugin_tralla_cards', [
                'approval_cards_id' => $approvalId,
                'rejection_reason' => null,
                'date_mod' => $now,
            ], ['id' => $cardId]);
            PluginTrallaHistory::log($cardId, $userId, 'call_release_requested', 'Nova solicitação de liberação enviada para aprovação.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

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
        if ($boardType === 'updates') {
            throw new RuntimeException('Use os campos do fluxo de Atualizações para editar este card.');
        }
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

    public static function updateCallReport(int $id, string $report, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;
        $userId = $userId ?: (int) Session::getLoginUserID();
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Chamado não encontrado.');
        }
        self::assertBoardAccess($card);
        if ((string) ($card['workflow_type'] ?? 'maintenance') !== 'call'
            || empty(self::permissionsFor($card, 'maintenance', $userId, $isAdmin)['edit_machines'])) {
            throw new RuntimeException('Somente o técnico responsável pode editar o relatório do chamado em andamento.');
        }
        $DB->update('glpi_plugin_tralla_cards', [
            'service_report' => tralla_normalize_report($report),
            'date_mod' => tralla_now(),
        ], ['id' => $id]);
        PluginTrallaHistory::log($id, $userId, 'call_report_updated', 'Relatório do chamado atualizado.');

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
        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || !tralla_can_transition($card, 'take', $boardType)) {
                throw new RuntimeException('Este card já foi pego ou não está disponível.');
            }
            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'technician_id'          => $userId,
                'status'                 => 'in_progress',
                'date_taken'             => $now,
                'date_mod'               => $now,
                'rank'                   => self::nextRankForList((int) $list['id']),
            ], ['id' => $id]);
            PluginTrallaHistory::log($id, $userId, 'card_taken', 'Card pego por ' . tralla_user_name($userId) . '.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

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
            'can_finalize' => tralla_all_machines_filled($machines)
                && tralla_finalization_partition($machines)['ready'] !== []
                && ((string) ($card['workflow_type'] ?? 'maintenance') !== 'call'
                    || tralla_finalization_partition($machines)['pending'] === []),
        ];
    }

    public static function bulkUpdateMachines(int $cardId, array $machineIds, array $changes, int $userId = 0, bool $isAdmin = false): array
    {
        global $DB;

        $ids = [];
        foreach ($machineIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new RuntimeException('Seleção de máquinas inválida.');
            }
            $ids[$id] = $id;
        }
        if ($ids === []) {
            throw new RuntimeException('Selecione ao menos uma máquina.');
        }
        $allowed = ['status', 'report', 'service_done', 'needs_inventory'];
        if ($changes === [] || array_diff(array_keys($changes), $allowed) !== []) {
            throw new RuntimeException('Selecione os campos que deseja alterar.');
        }
        foreach ($changes as $value) {
            if (!is_scalar($value)) {
                throw new RuntimeException('Valor inválido para a edição em massa.');
            }
        }
        if (array_key_exists('status', $changes)
            && (string) $changes['status'] !== ''
            && !array_key_exists((string) $changes['status'], tralla_machine_status_choices())) {
            throw new RuntimeException('Status inválido.');
        }
        foreach (['service_done', 'needs_inventory'] as $field) {
            if (array_key_exists($field, $changes) && !in_array((string) $changes[$field], ['0', '1'], true)) {
                throw new RuntimeException('Valor inválido para ' . $field . '.');
            }
        }
        if (array_key_exists('report', $changes)) {
            $changes['report'] = tralla_normalize_report($changes['report']);
        }

        $userId = $userId ?: (int) Session::getLoginUserID();
        $DB->beginTransaction();
        try {
            self::lockCardRow($cardId);
            $card = self::getCard($cardId);
            if ($card === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            self::assertBoardAccess($card);
            $boardType = self::getBoardType((int) $card['plugin_tralla_boards_id']);
            $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
            if ($boardType !== 'maintenance' || empty($permissions['edit_machines'])) {
                throw new RuntimeException('Somente o técnico responsável pode editar as máquinas deste card.');
            }

            foreach ($ids as $id) {
                $machine = PluginTrallaMachine::getMachine($id);
                if ($machine === null || (int) $machine['plugin_tralla_cards_id'] !== $cardId) {
                    throw new RuntimeException('A seleção contém uma máquina que não pertence a este card.');
                }
            }
            foreach ($ids as $id) {
                if (!PluginTrallaMachine::updateMachine($id, $changes)) {
                    throw new RuntimeException('Não foi possível atualizar todas as máquinas.');
                }
            }
            $DB->update('glpi_plugin_tralla_cards', ['date_mod' => tralla_now()], ['id' => $cardId]);
            PluginTrallaHistory::log($cardId, $userId, 'machine_updated', count($ids) . ' máquina(s) atualizada(s) em massa.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($cardId, $userId);
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

        $pendingCardId = 0;
        $approvalId = 0;
        $familyRoot = self::familyRoot($card);
        $codeRoot = self::codeRoot($card);
        $DB->beginTransaction();
        try {
            self::lockCardRow($codeRoot);
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || !tralla_can_transition($card, 'finalize', $boardType)) {
                throw new RuntimeException('Este card não está em andamento.');
            }
            self::assertBoardAccess($card);
            $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
            if (empty($permissions['edit_machines'])) {
                throw new RuntimeException('Somente o técnico responsável pode finalizar o card.');
            }

            $machines = PluginTrallaMachine::getMachinesForCard($id);
            $isCall = (string) ($card['workflow_type'] ?? 'maintenance') === 'call';
            $callWithoutMachines = $isCall && $machines === [];
            if ($callWithoutMachines && trim((string) ($card['service_report'] ?? '')) === '') {
                throw new RuntimeException('Preencha o relatório do chamado antes de finalizar.');
            }
            if (!$callWithoutMachines && !tralla_all_machines_filled($machines)) {
                throw new RuntimeException('Preencha o relatório, marque Serviço realizado e escolha o status de todas as máquinas antes de finalizar.');
            }
            $partition = $callWithoutMachines ? ['ready' => [], 'pending' => []] : tralla_finalization_partition($machines);
            if (!$callWithoutMachines && $partition['ready'] === []) {
                throw new RuntimeException('Conclua ou marque como Inservível ao menos uma máquina antes de finalizar.');
            }
            if ($isCall && $partition['pending'] !== []) {
                throw new RuntimeException('Conclua ou marque como Inservível todas as máquinas do chamado antes de finalizar.');
            }

            $approvalList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
            if ($approvalList === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
            }

            $now = tralla_now();
            if ($partition['pending'] !== []) {
                $pendingList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_pending');
                if ($pendingList === null) {
                    throw new RuntimeException('O quadro não possui a lista "Manutenção Pendente".');
                }
                $pendingSeq = self::nextCodeSequence($codeRoot);
                $DB->insert('glpi_plugin_tralla_cards', [
                    'plugin_tralla_boards_id' => $boards_id,
                    'plugin_tralla_lists_id'  => (int) $pendingList['id'],
                    'name'                    => (string) $card['name'],
                    'description'             => $card['description'],
                    'rank'                    => self::nextRankForList((int) $pendingList['id']),
                    'users_id'                => $userId,
                    'technician_id'           => 0,
                    'stage'                   => 1,
                    'parent_cards_id'         => 0,
                    'approval_cards_id'       => 0,
                    'family_cards_id'         => 0,
                    'code_root_cards_id'      => $codeRoot,
                    'family_seq'              => $pendingSeq,
                    'split_from_cards_id'     => $id,
                    'approval_kind'           => 'maintenance',
                    'status'                  => 'pending',
                    'workflow_type'           => (string) ($card['workflow_type'] ?? 'maintenance'),
                    'is_locked'               => 0,
                    'is_archived'             => 0,
                    'source'                  => (string) $card['source'],
                    'machines_total'          => count($partition['pending']),
                    'date_creation'           => $now,
                    'date_mod'                => $now,
                ]);
                $pendingCardId = (int) $DB->insertId();
                // O card pendente inicia outra família para não bloquear a retirada do card finalizado.
                $DB->update('glpi_plugin_tralla_cards', [
                    'family_cards_id' => $pendingCardId,
                ], ['id' => $pendingCardId]);
                $DB->update('glpi_plugin_tralla_machines', [
                    'plugin_tralla_cards_id' => $pendingCardId,
                    'date_mod'               => $now,
                ], ['id' => $partition['pending']]);
                PluginTrallaMachine::refreshCardTotal($id);
            }

            $approvalSeq = self::nextCodeSequence($codeRoot);
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
                'family_cards_id'         => $familyRoot,
                'code_root_cards_id'      => $codeRoot,
                'family_seq'              => $approvalSeq,
                'split_from_cards_id'     => 0,
                'approval_kind'           => 'maintenance',
                'status'                  => 'waiting_approval',
                'workflow_type'           => (string) ($card['workflow_type'] ?? 'maintenance'),
                'service_report'          => (string) ($card['service_report'] ?? ''),
                'is_locked'               => 0,
                'is_archived'             => 0,
                'source'                  => (string) $card['source'],
                'machines_total'          => count($partition['ready']),
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

            $approvalCode = tralla_display_code([
                'id'              => $approvalId,
                'stage'           => 2,
                'parent_cards_id' => $id,
                'family_cards_id' => $familyRoot,
                'code_root_cards_id' => $codeRoot,
                'family_seq'      => $approvalSeq,
            ], $boardType);

            if ($pendingCardId > 0) {
                $pendingCode = tralla_display_code([
                    'id' => $pendingCardId,
                    'stage' => 1,
                    'family_cards_id' => $pendingCardId,
                    'code_root_cards_id' => $codeRoot,
                    'family_seq' => $pendingSeq,
                ], $boardType);
                PluginTrallaHistory::log($id, $userId, 'card_split', 'Máquinas pendentes reunidas no novo card ' . $pendingCode . '.');
                PluginTrallaHistory::log($pendingCardId, $userId, 'card_split', 'Card criado com as máquinas pendentes de ' . tralla_display_code($card, $boardType) . '.');
            }
            PluginTrallaHistory::log($id, $userId, 'card_finalized', ($isCall ? 'Chamado' : 'Manutenção') . ' finalizado; card de aprovação ' . $approvalCode . ' criado.');
            PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando decisão de aprovação.');

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return [
            'card'     => self::getCardDetail($id, $userId),
            'approval' => self::getCardDetail($approvalId, $userId),
            'pending_card' => $pendingCardId > 0 ? self::getCardDetail($pendingCardId, $userId) : null,
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
        $kind = (string) ($approval['approval_kind'] ?? 'maintenance');
        if ($kind === 'deletion') {
            return self::decideDeletion($approvalId, $approve, $reason, $userId, $isAdmin);
        }
        if ($kind === 'edit') {
            return self::decideEdit($approvalId, $approve, $reason, $userId, $isAdmin);
        }
        if ($kind === 'split') {
            return self::decideSplit($approvalId, $approve, $reason, $userId, $isAdmin);
        }
        if ($kind === 'call_release') {
            return self::decideCallRelease($approvalId, $approve, $reason, $userId);
        }
        if (!tralla_can_transition($approval, $approve ? 'approve' : 'reject', 'maintenance')) {
            throw new RuntimeException('Este card não está aguardando aprovação.');
        }
        if (!self::userIsApprover($userId, (int) $approval['plugin_tralla_boards_id'])) {
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
        $familyReady = false;

        $DB->beginTransaction();
        try {
            self::lockCardRow($parentId);

            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null
                || (int) $approval['stage'] !== 2
                || (string) ($approval['approval_kind'] ?? 'maintenance') !== 'maintenance'
                || (string) $approval['status'] !== 'waiting_approval'
                || !empty($approval['is_archived'])
                || (int) $approval['parent_cards_id'] !== $parentId) {
                throw new RuntimeException('Card de aprovação não encontrado.');
            }
            if ($parent === null) {
                throw new RuntimeException('Card de manutenção de origem não encontrado.');
            }
            self::assertBoardAccess($parent);
            if ((string) $parent['status'] !== 'waiting_approval'
                || (int) ($parent['approval_cards_id'] ?? 0) !== $approvalId) {
                throw new RuntimeException('O card de origem não está aguardando aprovação.');
            }
            if (!self::userIsApprover($userId, $boards_id)) {
                throw new RuntimeException('Sem permissão para aprovar ou reprovar.');
            }

            if ($approve) {
                $isCall = (string) ($parent['workflow_type'] ?? 'maintenance') === 'call';
                $doneList = $isCall
                    ? PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_done') : null;
                if ($isCall && $doneList === null) {
                    throw new RuntimeException('O quadro não possui a lista "Manutenção Concluído".');
                }
                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => $isCall ? 'done' : 'approved',
                    'plugin_tralla_lists_id' => $isCall ? (int) $doneList['id'] : (int) $parent['plugin_tralla_lists_id'],
                    'rank'              => $isCall ? self::nextRankForList((int) $doneList['id']) : (float) $parent['rank'],
                    'is_locked'         => 1,
                    'approval_cards_id' => 0,
                    'users_id_approver' => $userId,
                    'date_approved'     => $now,
                    'rejection_reason'  => null,
                    'date_mod'          => $now,
                ], ['id' => $parentId]);

                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => 'approved',
                    'is_archived'       => 1,
                    'users_id_approver' => $userId,
                    'date_mod'          => $now,
                ], ['id' => $approvalId]);

                $familyReady = $isCall || self::syncFamilyWithdrawal(
                    $boards_id, self::familyRoot($parent), $userId
                );

                PluginTrallaHistory::log(
                    $parentId,
                    $userId,
                    'approval_approved',
                    $isCall ? 'Chamado aprovado e concluído.' : ($familyReady
                        ? 'Manutenção aprovada; família liberada para retirada.'
                        : 'Manutenção aprovada; aguardando os demais cards da família.')
                );
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
                    'approval_cards_id'      => 0,
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

                PluginTrallaHistory::log(
                    $parentId,
                    $userId,
                    'approval_rejected',
                    'Manutenção reprovada; card voltou para Em Andamento.' . ($reason !== '' ? ' Motivo: ' . $reason : '')
                );
                PluginTrallaHistory::log($approvalId, $userId, 'approval_rejected', 'Aprovação recusada.');
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        $detailId = $approve && $familyReady ? self::familyRoot($parent) : $parentId;
        $detail = self::getCardDetail($detailId, $userId);
        $detail['family_ready'] = $familyReady;

        return $detail;
    }

    private static function decideCallRelease(int $approvalId, bool $approve, string $reason, int $userId): array
    {
        global $DB;
        $approval = self::getCard($approvalId);
        if ($approval === null || (int) $approval['stage'] !== 2
            || (string) ($approval['approval_kind'] ?? '') !== 'call_release'
            || (string) $approval['status'] !== 'waiting_approval' || !empty($approval['is_archived'])) {
            throw new RuntimeException('Solicitação de liberação não encontrada.');
        }
        $parentId = (int) $approval['parent_cards_id'];
        $parent = self::getCard($parentId);
        if ($parent === null) {
            throw new RuntimeException('Chamado de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        $boardId = (int) $parent['plugin_tralla_boards_id'];
        if (!self::userIsApprover($userId, $boardId)) {
            throw new RuntimeException('Sem permissão para liberar chamados.');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($parentId);
            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null || $parent === null
                || (int) $approval['stage'] !== 2
                || (string) ($approval['approval_kind'] ?? '') !== 'call_release'
                || (string) $approval['status'] !== 'waiting_approval'
                || !empty($approval['is_archived'])
                || (int) $approval['parent_cards_id'] !== $parentId
                || (int) $approval['plugin_tralla_boards_id'] !== $boardId
                || (string) ($parent['workflow_type'] ?? '') !== 'call'
                || (int) $parent['stage'] !== 1
                || (string) $parent['status'] !== 'pending'
                || empty($parent['is_locked']) || !empty($parent['is_archived'])
                || (int) ($parent['approval_cards_id'] ?? 0) !== $approvalId) {
                throw new RuntimeException('Este chamado não está aguardando liberação.');
            }
            if (!self::userIsApprover($userId, $boardId)) {
                throw new RuntimeException('Sem permissão para liberar chamados.');
            }

            $now = tralla_now();
            $reason = trim(strip_tags($reason));
            $DB->update('glpi_plugin_tralla_cards', [
                'approval_cards_id' => 0,
                'is_locked' => $approve ? 0 : 1,
                'users_id_approver' => $userId,
                'date_approved' => $approve ? $now : null,
                'rejection_reason' => !$approve && $reason !== '' ? $reason : null,
                'date_mod' => $now,
            ], ['id' => $parentId]);
            $DB->update('glpi_plugin_tralla_cards', [
                'status' => $approve ? 'approved' : 'rejected',
                'is_archived' => 1,
                'users_id_approver' => $userId,
                'rejection_reason' => !$approve && $reason !== '' ? $reason : null,
                'date_mod' => $now,
            ], ['id' => $approvalId]);
            $message = $approve
                ? 'Chamado liberado para ser assumido por um técnico.'
                : 'Liberação do chamado recusada.' . ($reason !== '' ? ' Motivo: ' . $reason : '');
            PluginTrallaHistory::log($parentId, $userId, $approve ? 'call_released' : 'call_release_rejected', $message);
            PluginTrallaHistory::log($approvalId, $userId, $approve ? 'approval_approved' : 'approval_rejected', $message);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($parentId, $userId);
    }

    public static function decideEdit(int $approvalId, bool $approve, string $reason, int $userId, bool $isAdmin): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $approval = self::getCard($approvalId);
        if ($approval === null
            || (int) $approval['stage'] !== 2
            || (string) ($approval['approval_kind'] ?? '') !== 'edit'
            || (string) $approval['status'] !== 'waiting_approval'
            || !empty($approval['is_archived'])) {
            throw new RuntimeException('Card de aprovação não encontrado.');
        }

        $parentId = (int) $approval['parent_cards_id'];
        $parent = $parentId > 0 ? self::getCard($parentId) : null;
        if ($parent === null) {
            throw new RuntimeException('Card de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        $boardId = (int) $parent['plugin_tralla_boards_id'];
        if (!self::userIsApprover($userId, $boardId)) {
            throw new RuntimeException('Sem permissão para aprovar ou reprovar.');
        }
        if (!in_array((string) $parent['status'], ['in_progress', 'withdrawal'], true)
            || (int) ($parent['approval_cards_id'] ?? 0) !== $approvalId) {
            throw new RuntimeException('O card de origem não está com edição pendente.');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($parentId);

            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null
                || (int) $approval['stage'] !== 2
                || (string) ($approval['approval_kind'] ?? '') !== 'edit'
                || (string) $approval['status'] !== 'waiting_approval'
                || !empty($approval['is_archived'])
                || (int) $approval['parent_cards_id'] !== $parentId) {
                throw new RuntimeException('Card de aprovação não encontrado.');
            }
            if ($parent === null) {
                throw new RuntimeException('Card de origem não encontrado.');
            }
            self::assertBoardAccess($parent);
            if (!in_array((string) $parent['status'], ['in_progress', 'withdrawal'], true)
                || (int) ($parent['approval_cards_id'] ?? 0) !== $approvalId) {
                throw new RuntimeException('O card de origem não está com edição pendente.');
            }
            if (!self::userIsApprover($userId, $boardId)) {
                throw new RuntimeException('Sem permissão para aprovar ou reprovar.');
            }

            $now = tralla_now();
            $reason = trim(strip_tags($reason));

            if ($approve) {
                $payload = json_decode((string) ($approval['edit_payload'] ?? ''), true);
                if (!is_array($payload)) {
                    throw new RuntimeException('Payload da edição inválido.');
                }

                $proposedName = tralla_normalize_label($payload['name'] ?? '');
                if ($proposedName === '') {
                    throw new RuntimeException('Informe o nome do card.');
                }

                $machinesPayload = is_array($payload['machines'] ?? null) ? $payload['machines'] : [];
                $newPayload = is_array($payload['new_machines'] ?? null) ? $payload['new_machines'] : [];

                $currentMachines = PluginTrallaMachine::getMachinesForCard($parentId);
                $currentById = [];
                foreach ($currentMachines as $machine) {
                    $currentById[(int) $machine['id']] = $machine;
                }

                $keptIds = [];
                foreach ($machinesPayload as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $machineId = (int) ($entry['id'] ?? 0);
                    if ($machineId <= 0 || !isset($currentById[$machineId])) {
                        throw new RuntimeException('Máquina da edição não pertence ao card.');
                    }
                    if (isset($keptIds[$machineId])) {
                        throw new RuntimeException('Máquina repetida na edição.');
                    }
                    $keptIds[$machineId] = true;

                    $status = (string) ($entry['status'] ?? '');
                    if ($status !== '' && !array_key_exists($status, tralla_machine_status_choices())) {
                        throw new RuntimeException('Status de máquina inválido.');
                    }

                    PluginTrallaMachine::updateMachine($machineId, [
                        'status'          => $status,
                        'report'          => tralla_normalize_report($entry['report'] ?? ''),
                        'service_done'    => !empty($entry['service_done']) ? 1 : 0,
                        'needs_inventory' => !empty($entry['needs_inventory']) ? 1 : 0,
                    ]);
                }

                foreach (array_keys($currentById) as $machineId) {
                    if (!isset($keptIds[$machineId])) {
                        PluginTrallaMachine::deleteMachine($machineId);
                    }
                }

                $newTotal = count($keptIds);
                foreach ($newPayload as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $modelId = (int) ($entry['model_id'] ?? 0);
                    if ($modelId <= 0 || PluginTrallaMachineModel::getModel($modelId, true) === null) {
                        throw new RuntimeException('Modelo de máquina inválido.');
                    }
                    $quantity = filter_var(
                        $entry['quantity'] ?? 1,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => self::MAX_MACHINE_QUANTITY]]
                    );
                    if ($quantity === false) {
                        throw new RuntimeException('Quantidade de máquina inválida.');
                    }
                    for ($index = 0; $index < (int) $quantity; $index++) {
                        PluginTrallaMachine::insertForCard($parentId, ['model_id' => $modelId]);
                    }
                    $newTotal += (int) $quantity;
                }

                if ($newTotal < 1) {
                    throw new RuntimeException('O card deve manter ao menos uma máquina.');
                }

                $DB->update('glpi_plugin_tralla_cards', [
                    'name'              => $proposedName,
                    'approval_cards_id' => 0,
                    'is_locked'         => 0,
                    'date_mod'          => $now,
                ], ['id' => $parentId]);
                PluginTrallaMachine::refreshCardTotal($parentId);

                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => 'approved',
                    'is_archived'       => 1,
                    'users_id_approver' => $userId,
                    'date_mod'          => $now,
                ], ['id' => $approvalId]);

                PluginTrallaHistory::log($parentId, $userId, 'edit_approved', 'Edição aprovada; alterações aplicadas ao card.');
                PluginTrallaHistory::log($approvalId, $userId, 'edit_approved', 'Aprovação da edição concluída.');
            } else {
                $DB->update('glpi_plugin_tralla_cards', [
                    'approval_cards_id' => 0,
                    'is_locked'         => 0,
                    'date_mod'          => $now,
                ], ['id' => $parentId]);

                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => 'rejected',
                    'is_archived'       => 1,
                    'users_id_approver' => $userId,
                    'rejection_reason'  => $reason !== '' ? $reason : null,
                    'date_mod'          => $now,
                ], ['id' => $approvalId]);

                PluginTrallaHistory::log(
                    $parentId,
                    $userId,
                    'edit_rejected',
                    'Edição recusada; card mantido.' . ($reason !== '' ? ' Motivo: ' . $reason : '')
                );
                PluginTrallaHistory::log(
                    $approvalId,
                    $userId,
                    'edit_rejected',
                    'Pedido de edição recusado.' . ($reason !== '' ? ' Motivo: ' . $reason : '')
                );
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($parentId, $userId);
    }

    public static function requestEdit(int $id, array $data, int $userId = 0, bool $isAdmin = false): array
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
        $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        if (empty($permissions['edit_request'])) {
            throw new RuntimeException('Sem permissão para solicitar edição deste card.');
        }

        $proposedName = tralla_normalize_label($data['name'] ?? '');
        if ($proposedName === '') {
            throw new RuntimeException('Informe o nome do card.');
        }

        $currentMachines = PluginTrallaMachine::getMachinesForCard($id);
        $currentById = [];
        foreach ($currentMachines as $machine) {
            $currentById[(int) $machine['id']] = $machine;
        }

        $machinesInput = is_array($data['machines'] ?? null) ? $data['machines'] : [];
        $newInput = is_array($data['new_machines'] ?? null) ? $data['new_machines'] : [];
        $machines = [];
        $seen = [];
        $hasChange = false;

        foreach ($machinesInput as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Máquina inválida.');
            }
            $machineId = (int) ($entry['id'] ?? 0);
            if ($machineId <= 0 || !isset($currentById[$machineId])) {
                throw new RuntimeException('Máquina da edição não pertence ao card.');
            }
            if (isset($seen[$machineId])) {
                throw new RuntimeException('Máquina repetida na edição.');
            }
            $seen[$machineId] = true;

            $status = (string) ($entry['status'] ?? '');
            if ($status !== '' && !array_key_exists($status, tralla_machine_status_choices())) {
                throw new RuntimeException('Status de máquina inválido.');
            }
            $report = tralla_normalize_report($entry['report'] ?? '');
            $serviceDone = !empty($entry['service_done']) ? 1 : 0;
            $needsInventory = !empty($entry['needs_inventory']) ? 1 : 0;
            $current = $currentById[$machineId];

            if ($status !== (string) $current['status']
                || $report !== trim((string) $current['report'])
                || $serviceDone !== (int) $current['service_done']
                || $needsInventory !== (int) $current['needs_inventory']) {
                $hasChange = true;
            }

            $machines[] = [
                'id'              => $machineId,
                'status'          => $status,
                'report'          => $report,
                'service_done'    => $serviceDone,
                'needs_inventory' => $needsInventory,
            ];
        }

        if (count($seen) !== count($currentById)) {
            $hasChange = true;
        }

        $newMachines = [];
        $newTotal = count($seen);
        foreach ($newInput as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Modelo de máquina inválido.');
            }
            $modelId = (int) ($entry['model_id'] ?? 0);
            if ($modelId <= 0 || !PluginTrallaMachineModel::canUseModel($modelId)) {
                throw new RuntimeException('Modelo de máquina inválido.');
            }
            $quantity = filter_var(
                $entry['quantity'] ?? 1,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => self::MAX_MACHINE_QUANTITY]]
            );
            if ($quantity === false) {
                throw new RuntimeException('Quantidade de máquina inválida.');
            }
            $newMachines[] = ['model_id' => $modelId, 'quantity' => (int) $quantity];
            $newTotal += (int) $quantity;
            $hasChange = true;
        }

        if ($newTotal < 1) {
            throw new RuntimeException('O card deve manter ao menos uma máquina.');
        }
        if ($proposedName !== (string) $card['name']) {
            $hasChange = true;
        }
        if (!$hasChange) {
            throw new RuntimeException('Nenhuma alteração para solicitar.');
        }

        $approvalId = 0;
        $familyRoot = self::familyRoot($card);
        $codeRoot = self::codeRoot($card);
        $DB->beginTransaction();
        try {
            self::lockCardRow($codeRoot);
            self::lockCardRow($id);

            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            self::assertBoardAccess($fresh);
            $freshPermissions = self::permissionsFor($fresh, $boardType, $userId, $isAdmin);
            if (empty($freshPermissions['edit_request'])) {
                throw new RuntimeException('Sem permissão para solicitar edição deste card.');
            }

            $approvalList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
            if ($approvalList === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
            }

            $payload = [
                'name'         => $proposedName,
                'machines'     => $machines,
                'new_machines' => $newMachines,
            ];
            $approvalSeq = self::nextCodeSequence($codeRoot);
            $now = tralla_now();

            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $approvalList['id'],
                'name'                    => $proposedName,
                'description'             => $fresh['description'],
                'rank'                    => self::nextRankForList((int) $approvalList['id']),
                'users_id'                => $userId,
                'technician_id'           => (int) $fresh['technician_id'],
                'stage'                   => 2,
                'parent_cards_id'         => $id,
                'approval_cards_id'       => 0,
                'family_cards_id'         => $familyRoot,
                'code_root_cards_id'      => $codeRoot,
                'family_seq'              => $approvalSeq,
                'split_from_cards_id'     => 0,
                'approval_kind'           => 'edit',
                'workflow_type'           => (string) ($fresh['workflow_type'] ?? 'maintenance'),
                'service_report'          => (string) ($fresh['service_report'] ?? ''),
                'edit_payload'            => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'status'                  => 'waiting_approval',
                'is_locked'               => 0,
                'is_archived'             => 0,
                'source'                  => (string) $fresh['source'],
                'machines_total'          => $newTotal,
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $approvalId = (int) $DB->insertId();

            $DB->update('glpi_plugin_tralla_cards', [
                'is_locked'         => 1,
                'approval_cards_id' => $approvalId,
                'date_mod'          => $now,
            ], ['id' => $id]);

            PluginTrallaHistory::log($id, $userId, 'edit_requested', 'Edição solicitada; aguardando aprovação.');
            PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando decisão da edição.');

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return [
            'card'     => self::getCardDetail($id, $userId),
            'approval' => self::getCardDetail($approvalId, $userId),
        ];
    }

    public static function split(int $id, array $machineIds, int $userId = 0, bool $isAdmin = false): array
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
        $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        if (empty($permissions['split'])) {
            throw new RuntimeException('Sem permissão para separar este card.');
        }

        $ids = [];
        foreach ($machineIds as $machineId) {
            $machineId = (int) $machineId;
            if ($machineId <= 0) {
                throw new RuntimeException('Máquina inválida.');
            }
            if (isset($ids[$machineId])) {
                throw new RuntimeException('Máquina repetida na separação.');
            }
            $ids[$machineId] = true;
        }
        if ($ids === []) {
            throw new RuntimeException('Selecione ao menos uma máquina.');
        }

        $machines = PluginTrallaMachine::getMachinesForCard($id);
        $mine = [];
        foreach ($machines as $machine) {
            $mine[(int) $machine['id']] = $machine;
        }
        foreach (array_keys($ids) as $machineId) {
            if (!isset($mine[$machineId])) {
                throw new RuntimeException('Máquina selecionada não pertence ao card.');
            }
        }
        if (count($ids) >= count($machines)) {
            throw new RuntimeException('Selecione menos máquinas do que o total do card.');
        }

        $root = self::familyRoot($card);
        $codeRoot = self::codeRoot($card);
        if (!self::familyIsIdle($root)) {
            throw new RuntimeException('A família precisa estar toda Pendente ou Em Andamento para ser separada.');
        }

        $approvalId = 0;
        $DB->beginTransaction();
        try {
            self::lockCardRow($codeRoot);
            self::lockCardRow($id);
            self::lockFamilyRows($root);

            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            self::assertBoardAccess($fresh);
            $freshPermissions = self::permissionsFor($fresh, $boardType, $userId, $isAdmin);
            if (empty($freshPermissions['split'])) {
                throw new RuntimeException('Sem permissão para separar este card.');
            }
            if (!self::familyIsIdle($root)) {
                throw new RuntimeException('A família precisa estar toda Pendente ou Em Andamento para ser separada.');
            }

            $freshMachines = PluginTrallaMachine::getMachinesForCard($id);
            $freshById = [];
            foreach ($freshMachines as $machine) {
                $freshById[(int) $machine['id']] = $machine;
            }
            foreach (array_keys($ids) as $machineId) {
                if (!isset($freshById[$machineId])) {
                    throw new RuntimeException('Máquina selecionada não pertence ao card.');
                }
            }
            if (count($ids) >= count($freshMachines)) {
                throw new RuntimeException('Selecione menos máquinas do que o total do card.');
            }

            $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
            if ($list === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
            }

            $approvalSeq = self::nextCodeSequence($codeRoot);
            $now = tralla_now();
            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $list['id'],
                'name'                    => (string) $fresh['name'],
                'description'             => $fresh['description'],
                'rank'                    => self::nextRankForList((int) $list['id']),
                'users_id'                => $userId,
                'technician_id'           => (int) $fresh['technician_id'],
                'stage'                   => 2,
                'parent_cards_id'         => $id,
                'approval_cards_id'       => 0,
                'family_cards_id'         => $root,
                'code_root_cards_id'      => $codeRoot,
                'family_seq'              => $approvalSeq,
                'split_from_cards_id'     => 0,
                'approval_kind'           => 'split',
                'edit_payload'            => json_encode(['machine_ids' => array_keys($ids)], JSON_THROW_ON_ERROR),
                'status'                  => 'waiting_approval',
                'is_locked'               => 0,
                'is_archived'             => 0,
                'source'                  => (string) $fresh['source'],
                'machines_total'          => count($ids),
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $approvalId = (int) $DB->insertId();
            $DB->update('glpi_plugin_tralla_cards', [
                'is_locked' => 1,
                'approval_cards_id' => $approvalId,
                'date_mod' => $now,
            ], ['id' => $id]);
            PluginTrallaHistory::log($id, $userId, 'split_requested', 'Separação de ' . count($ids) . ' máquina(s) enviada para aprovação.');
            PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando aprovação da separação.');

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return [
            'card'  => self::getCardDetail($id, $userId),
            'approval' => self::getCardDetail($approvalId, $userId),
        ];
    }

    public static function decideSplit(int $approvalId, bool $approve, string $reason, int $userId, bool $isAdmin): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $approval = self::getCard($approvalId);
        if ($approval === null || (int) $approval['stage'] !== 2
            || (string) ($approval['approval_kind'] ?? '') !== 'split'
            || (string) $approval['status'] !== 'waiting_approval' || !empty($approval['is_archived'])) {
            throw new RuntimeException('Solicitação de separação não encontrada.');
        }
        $parentId = (int) $approval['parent_cards_id'];
        $parent = self::getCard($parentId);
        if ($parent === null) {
            throw new RuntimeException('Card de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        $boards_id = (int) $parent['plugin_tralla_boards_id'];
        if (!self::userIsApprover($userId, $boards_id)) {
            throw new RuntimeException('Sem permissão para aprovar a separação.');
        }
        $root = self::familyRoot($parent);
        $codeRoot = self::codeRoot($parent);
        $reason = trim(strip_tags($reason));

        $DB->beginTransaction();
        try {
            self::lockCardRow($codeRoot);
            self::lockFamilyRows($root);
            self::lockCardRow($parentId);
            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null || $parent === null
                || (string) ($approval['approval_kind'] ?? '') !== 'split'
                || (string) $approval['status'] !== 'waiting_approval'
                || !empty($approval['is_archived'])
                || (int) $approval['parent_cards_id'] !== $parentId
                || (int) $parent['approval_cards_id'] !== $approvalId
                || !in_array((string) $parent['status'], ['pending', 'in_progress'], true)) {
                throw new RuntimeException('Esta separação não está mais aguardando aprovação.');
            }
            if (!self::userIsApprover($userId, $boards_id)) {
                throw new RuntimeException('Sem permissão para aprovar a separação.');
            }

            $now = tralla_now();
            $DB->update('glpi_plugin_tralla_cards', [
                'is_locked' => 0, 'approval_cards_id' => 0, 'date_mod' => $now,
            ], ['id' => $parentId]);

            if ($approve) {
                if (!self::familyIsIdle($root)) {
                    throw new RuntimeException('A família mudou enquanto a separação aguardava aprovação.');
                }
                $payload = json_decode((string) ($approval['edit_payload'] ?? ''), true);
                $ids = is_array($payload) ? array_values(array_unique(array_map('intval', (array) ($payload['machine_ids'] ?? [])))) : [];
                $machines = PluginTrallaMachine::getMachinesForCard($parentId);
                $owned = array_fill_keys(array_map(static fn (array $machine): int => (int) $machine['id'], $machines), true);
                if ($ids === [] || count($ids) >= count($machines)) {
                    throw new RuntimeException('As máquinas da separação mudaram. Solicite novamente.');
                }
                foreach ($ids as $machineId) {
                    if ($machineId <= 0 || !isset($owned[$machineId])) {
                        throw new RuntimeException('As máquinas da separação mudaram. Solicite novamente.');
                    }
                }
                $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_in_progress');
                if ($list === null) {
                    throw new RuntimeException('O quadro não possui a lista "Manutenção Em Andamento".');
                }
                $seq = self::nextCodeSequence($codeRoot);
                $DB->insert('glpi_plugin_tralla_cards', [
                    'plugin_tralla_boards_id' => $boards_id,
                    'plugin_tralla_lists_id' => (int) $list['id'],
                    'name' => (string) $parent['name'],
                    'description' => $parent['description'],
                    'rank' => self::nextRankForList((int) $list['id']),
                    'users_id' => (int) $approval['users_id'],
                    'technician_id' => (int) $approval['users_id'],
                    'stage' => 1,
                    'parent_cards_id' => 0,
                    'approval_cards_id' => 0,
                    'family_cards_id' => $root,
                    'code_root_cards_id' => $codeRoot,
                    'family_seq' => $seq,
                    'split_from_cards_id' => $parentId,
                    'approval_kind' => 'maintenance',
                    'status' => 'in_progress',
                    'is_locked' => 0,
                    'is_archived' => 0,
                    'source' => (string) $parent['source'],
                    'machines_total' => count($ids),
                    'date_taken' => $now,
                    'date_creation' => $now,
                    'date_mod' => $now,
                ]);
                $childId = (int) $DB->insertId();
                $DB->update('glpi_plugin_tralla_machines', [
                    'plugin_tralla_cards_id' => $childId, 'date_mod' => $now,
                ], ['id' => $ids]);
                PluginTrallaMachine::refreshCardTotal($parentId);
                PluginTrallaHistory::log($parentId, $userId, 'card_split', 'Separação aprovada; card ' . $codeRoot . '/' . $seq . ' criado.');
                PluginTrallaHistory::log($childId, $userId, 'card_split', 'Card criado após aprovação da separação.');
            }

            $DB->update('glpi_plugin_tralla_cards', [
                'status' => $approve ? 'approved' : 'rejected',
                'is_archived' => 1,
                'users_id_approver' => $userId,
                'rejection_reason' => !$approve && $reason !== '' ? $reason : null,
                'date_mod' => $now,
            ], ['id' => $approvalId]);
            PluginTrallaHistory::log($parentId, $userId, $approve ? 'split_approved' : 'split_rejected',
                $approve ? 'Separação aprovada.' : 'Separação recusada.' . ($reason !== '' ? ' Motivo: ' . $reason : ''));
            PluginTrallaHistory::log($approvalId, $userId, $approve ? 'approval_approved' : 'approval_rejected',
                $approve ? 'Separação aprovada.' : 'Separação recusada.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($parentId, $userId);
    }

    public static function decideDeletion(int $approvalId, bool $approve, string $reason, int $userId, bool $isAdmin): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }

        $approval = self::getCard($approvalId);
        if ($approval === null
            || (int) $approval['stage'] !== 2
            || (string) ($approval['approval_kind'] ?? '') !== 'deletion'
            || (string) $approval['status'] !== 'waiting_approval'
            || !empty($approval['is_archived'])) {
            throw new RuntimeException('Card de aprovação não encontrado.');
        }

        $parentId = (int) $approval['parent_cards_id'];
        $parent = $parentId > 0 ? self::getCard($parentId) : null;
        if ($parent === null) {
            throw new RuntimeException('Card de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        if ((string) $parent['status'] !== 'deletion_pending') {
            throw new RuntimeException('O card de origem não está aguardando exclusão.');
        }

        $boardId = (int) $parent['plugin_tralla_boards_id'];
        if (!self::userIsApprover($userId, $boardId)) {
            throw new RuntimeException('Sem permissão para aprovar ou reprovar.');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($parentId);

            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null
                || (int) $approval['stage'] !== 2
                || (string) ($approval['approval_kind'] ?? '') !== 'deletion'
                || (string) $approval['status'] !== 'waiting_approval'
                || !empty($approval['is_archived'])
                || (int) $approval['parent_cards_id'] !== $parentId) {
                throw new RuntimeException('Card de aprovação não encontrado.');
            }
            if ($parent === null) {
                throw new RuntimeException('Card de origem não encontrado.');
            }
            self::assertBoardAccess($parent);
            if ((string) $parent['status'] !== 'deletion_pending') {
                throw new RuntimeException('O card de origem não está aguardando exclusão.');
            }

            $now = tralla_now();
            $reason = trim(strip_tags($reason));

            if ($approve) {
                $DB->update('glpi_plugin_tralla_cards', [
                    'status'              => 'deleted',
                    'is_deleted'          => 1,
                    'is_locked'           => 1,
                    'approval_cards_id'   => 0,
                    'deletion_decided_at' => $now,
                    'deletion_decided_by' => $userId,
                    'date_mod'            => $now,
                ], ['id' => $parentId]);

                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => 'approved',
                    'is_archived'       => 1,
                    'users_id_approver' => $userId,
                    'rejection_reason'  => null,
                    'date_mod'          => $now,
                ], ['id' => $approvalId]);

                self::syncFamilyWithdrawal($boardId, self::familyRoot($parent), $userId);

                PluginTrallaHistory::log($parentId, $userId, 'card_deletion_approved', 'Exclusão aprovada; card movido para Excluídos.');
                PluginTrallaHistory::log($approvalId, $userId, 'card_deletion_approved', 'Aprovação da exclusão concluída.');
            } else {
                $lists = PluginTrallaList::getListsForBoard($boardId);
                $listId = tralla_restore_list_id($parent, $lists, 'maintenance');
                if ($listId <= 0) {
                    throw new RuntimeException('O quadro não possui uma lista ativa para restaurar o card.');
                }

                $DB->update('glpi_plugin_tralla_cards', [
                    'plugin_tralla_lists_id'      => $listId,
                    'status'                      => tralla_restore_status($parent, 'maintenance'),
                    'is_locked'                   => tralla_restore_is_locked($parent),
                    'approval_cards_id'           => 0,
                    'deletion_reason'             => null,
                    'deletion_requested_at'       => null,
                    'deletion_requested_by'       => 0,
                    'deletion_decided_at'         => null,
                    'deletion_decided_by'         => 0,
                    'deletion_previous_lists_id'  => 0,
                    'deletion_previous_status'    => '',
                    'deletion_previous_is_locked' => 0,
                    'rank'                        => self::nextRankForList($listId),
                    'date_mod'                    => $now,
                ], ['id' => $parentId]);

                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => 'rejected',
                    'is_archived'       => 1,
                    'users_id_approver' => $userId,
                    'rejection_reason'  => $reason !== '' ? $reason : null,
                    'date_mod'          => $now,
                ], ['id' => $approvalId]);

                PluginTrallaHistory::log(
                    $parentId,
                    $userId,
                    'card_deletion_rejected',
                    'Exclusão reprovada; card restaurado.' . ($reason !== '' ? ' Motivo: ' . $reason : '')
                );
                PluginTrallaHistory::log(
                    $approvalId,
                    $userId,
                    'card_deletion_rejected',
                    'Pedido de exclusão recusado.' . ($reason !== '' ? ' Motivo: ' . $reason : '')
                );
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
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
        $root = self::familyRoot($card);

        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            self::lockFamilyRows($root);

            $card = self::getCard($id);
            if ($card === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            self::assertBoardAccess($card);

            if (!tralla_can_transition($card, 'withdraw', $boardType)) {
                throw new RuntimeException('Este card não está aguardando retirada.');
            }
            if (PluginTrallaConfig::assignatureUrl() !== '' && empty($card['assignature_sent'])) {
                throw new RuntimeException('A retirada precisa ser assinada no Assignat-ure.');
            }

            $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
            if (empty($permissions['withdraw'])) {
                throw new RuntimeException('Sem permissão para confirmar a retirada.');
            }

            $members = [];
            foreach ($DB->request([
                'FROM'  => 'glpi_plugin_tralla_cards',
                'WHERE' => [
                    'family_cards_id' => $root,
                    'stage'           => 1,
                    'is_archived'     => 0,
                    'is_deleted'      => 0,
                ],
            ]) as $row) {
                $members[] = $row;
            }
            if ($members === []) {
                throw new RuntimeException('Cards da família não encontrados.');
            }
            foreach ($members as $member) {
                if (!in_array((string) $member['status'], ['withdrawal', 'done'], true)) {
                    throw new RuntimeException('Todos os cards da família precisam estar aprovados para confirmar a retirada.');
                }
            }

            $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_done');
            if ($list === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Concluído".');
            }

            $now = tralla_now();
            foreach ($members as $member) {
                if ((string) $member['status'] !== 'withdrawal') {
                    continue;
                }
                $DB->update('glpi_plugin_tralla_cards', [
                    'plugin_tralla_lists_id' => (int) $list['id'],
                    'status'                 => 'done',
                    'date_withdrawn'         => $now,
                    'date_mod'               => $now,
                    'rank'                   => self::nextRankForList((int) $list['id']),
                ], ['id' => (int) $member['id']]);

                PluginTrallaHistory::log(
                    (int) $member['id'],
                    $userId,
                    'withdrawal_confirmed',
                    'Retirada confirmada; manutenção concluída.'
                );
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($id, $userId);
    }

    /** Conclui apenas o card que teve sua retirada assinada. */
    public static function completeSignedWithdrawal(int $id, string $signerName, string $signatureHash): void
    {
        global $DB;

        if ($id <= 0 || trim($signerName) === '' || !preg_match('/^[a-f0-9]{64}$/', $signatureHash)) {
            throw new RuntimeException('Dados da assinatura inválidos.');
        }
        $card = self::getCard($id);
        if ($card === null || (int) $card['stage'] !== 1 || self::getBoardType((int) $card['plugin_tralla_boards_id']) !== 'maintenance') {
            throw new RuntimeException('Card de manutenção não encontrado.');
        }
        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || !in_array((string) $card['status'], ['withdrawal', 'done'], true)
                || !empty($card['is_archived']) || !empty($card['is_deleted'])) {
                throw new RuntimeException('Este card não está aguardando retirada.');
            }
            if ((string) $card['status'] === 'done' && !empty($card['assignature_sent'])) {
                $DB->commit();
                return;
            }
            if ((string) $card['status'] !== 'withdrawal') {
                throw new RuntimeException('Este card não está aguardando retirada.');
            }
            $list = PluginTrallaBoard::findListByCategory((int) $card['plugin_tralla_boards_id'], 'maintenance_done');
            if ($list === null) {
                throw new RuntimeException('Lista de manutenção concluída não encontrada.');
            }
            $now = tralla_now();
            $safeSigner = mb_substr(trim($signerName), 0, 100);
            $DB->update('glpi_plugin_tralla_cards', [
                'assignature_sent' => 1,
                'plugin_tralla_lists_id' => (int) $list['id'],
                'status' => 'done',
                'date_withdrawn' => $now,
                'date_mod' => $now,
                'rank' => self::nextRankForList((int) $list['id']),
            ], ['id' => $id]);
            PluginTrallaHistory::log(
                $id,
                0,
                'withdrawal_confirmed',
                'Retirada assinada por ' . $safeSigner . ' no Assignat-ure. SHA-256: ' . $signatureHash
            );
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function requestDeletion(int $id, string $reason, int $userId = 0, bool $isAdmin = false): array
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
        $permissions = self::permissionsFor($card, $boardType, $userId, $isAdmin);
        if (empty($permissions['request_deletion'])) {
            throw new RuntimeException('Sem permissão para excluir este card.');
        }

        $normalizedReason = tralla_normalize_deletion_reason($reason);
        if (!tralla_is_valid_deletion_reason($normalizedReason)) {
            throw new RuntimeException('Informe o motivo da exclusão.');
        }

        $approvalId = 0;
        $isMaintenance = false;
        $familyRoot = self::familyRoot($card);
        $codeRoot = self::codeRoot($card);

        $DB->beginTransaction();
        try {
            self::lockCardRow($codeRoot);
            self::lockCardRow($id);

            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            self::assertBoardAccess($fresh);

            $freshBoardType = self::getBoardType((int) $fresh['plugin_tralla_boards_id']);
            $freshPermissions = self::permissionsFor($fresh, $freshBoardType, $userId, $isAdmin);
            if (empty($freshPermissions['request_deletion'])) {
                throw new RuntimeException('Sem permissão para excluir este card.');
            }

            if ($freshBoardType !== 'normal') {
                $approvalList = PluginTrallaBoard::findListByCategory(
                    (int) $fresh['plugin_tralla_boards_id'],
                    'maintenance_approval'
                );
                if ($approvalList === null) {
                    throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
                }

                $approvalSeq = self::nextCodeSequence($codeRoot);
                $now = tralla_now();
                $DB->insert('glpi_plugin_tralla_cards', [
                    'plugin_tralla_boards_id' => (int) $fresh['plugin_tralla_boards_id'],
                    'plugin_tralla_lists_id'  => (int) $approvalList['id'],
                    'name'                    => $fresh['name'],
                    'description'             => $fresh['description'],
                    'rank'                    => self::nextRankForList((int) $approvalList['id']),
                    'users_id'                => $userId,
                    'technician_id'           => (int) $fresh['technician_id'],
                    'stage'                   => 2,
                    'parent_cards_id'         => $id,
                    'approval_cards_id'       => 0,
                    'family_cards_id'         => $familyRoot,
                    'code_root_cards_id'      => $codeRoot,
                    'family_seq'              => $approvalSeq,
                    'split_from_cards_id'     => 0,
                    'approval_kind'           => 'deletion',
                    'workflow_type'           => (string) ($fresh['workflow_type'] ?? 'maintenance'),
                    'service_report'          => (string) ($fresh['service_report'] ?? ''),
                    'status'                  => 'waiting_approval',
                    'is_locked'               => 0,
                    'is_archived'             => 0,
                    'source'                  => (string) $fresh['source'],
                    'machines_total'          => (int) $fresh['machines_total'],
                    'deletion_reason'         => $normalizedReason,
                    'deletion_requested_at'   => $now,
                    'deletion_requested_by'   => $userId,
                    'date_creation'           => $now,
                    'date_mod'                => $now,
                ]);
                $approvalId = (int) $DB->insertId();

                $DB->update('glpi_plugin_tralla_cards', [
                    'status'                      => 'deletion_pending',
                    'is_locked'                   => 1,
                    'approval_cards_id'           => $approvalId,
                    'deletion_reason'             => $normalizedReason,
                    'deletion_requested_at'       => $now,
                    'deletion_requested_by'       => $userId,
                    'deletion_decided_at'         => null,
                    'deletion_decided_by'         => 0,
                    'deletion_previous_lists_id'  => (int) $fresh['plugin_tralla_lists_id'],
                    'deletion_previous_status'    => (string) $fresh['status'],
                    'deletion_previous_is_locked' => (int) $fresh['is_locked'],
                    'date_mod'                    => $now,
                ], ['id' => $id]);

                PluginTrallaHistory::log(
                    $id,
                    $userId,
                    'card_deletion_requested',
                    'Exclusão de manutenção solicitada. Motivo: ' . $normalizedReason
                );
                PluginTrallaHistory::log(
                    $approvalId,
                    $userId,
                    'card_deletion_requested',
                    'Aguardando aprovação da exclusão. Motivo: ' . $normalizedReason
                );

                $DB->commit();
                $isMaintenance = true;
            } else {
                $now = tralla_now();
                $DB->update('glpi_plugin_tralla_cards', [
                    'deletion_previous_lists_id'  => (int) $fresh['plugin_tralla_lists_id'],
                    'deletion_previous_status'    => (string) $fresh['status'],
                    'deletion_previous_is_locked' => (int) $fresh['is_locked'],
                    'deletion_reason'             => $normalizedReason,
                    'deletion_requested_at'       => $now,
                    'deletion_requested_by'       => $userId,
                    'deletion_decided_at'         => $now,
                    'deletion_decided_by'         => $userId,
                    'is_deleted'                  => 1,
                    'status'                      => 'deleted',
                    'is_locked'                   => 1,
                    'approval_cards_id'           => 0,
                    'date_mod'                    => $now,
                ], ['id' => $id]);

                PluginTrallaHistory::log(
                    $id,
                    $userId,
                    'card_deletion_requested',
                    'Exclusão direta solicitada. Motivo: ' . $normalizedReason
                );

                $DB->commit();
            }
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        if ($isMaintenance) {
            return [
                'card'     => self::getCardDetail($id, $userId),
                'approval' => self::getCardDetail($approvalId, $userId),
            ];
        }

        return [
            'card'     => self::getCardDetail($id, $userId),
            'approval' => null,
        ];
    }

    public static function restoreDeleted(int $id, int $userId = 0, bool $isAdmin = false): array
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
        if (empty($card['is_deleted'])) {
            throw new RuntimeException('Este card não está excluído.');
        }

        $boards_id = (int) $card['plugin_tralla_boards_id'];
        $boardType = self::getBoardType($boards_id);
        if (!$isAdmin
            && (!PluginTrallaBoard::canAdminBoard($boards_id, $userId)
                || !Session::haveRight(self::$rightname, UPDATE))) {
            throw new RuntimeException('Sem permissão para restaurar este card.');
        }

        $lists = PluginTrallaList::getListsForBoard($boards_id);

        $DB->beginTransaction();
        try {
            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            if (empty($fresh['is_deleted'])) {
                throw new RuntimeException('Este card não está excluído.');
            }

            $listId = tralla_restore_list_id($fresh, $lists, $boardType);
            if ($listId <= 0) {
                throw new RuntimeException('O quadro não possui uma lista ativa para restaurar o card.');
            }

            $status = tralla_restore_status($fresh, $boardType);
            $locked = tralla_restore_is_locked($fresh);
            $list = PluginTrallaList::getList($listId);
            $listName = (string) ($list['name'] ?? '');
            $now = tralla_now();

            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id'      => $listId,
                'status'                      => $status,
                'is_locked'                   => $locked,
                'is_deleted'                  => 0,
                'approval_cards_id'           => 0,
                'deletion_reason'             => null,
                'deletion_requested_at'       => null,
                'deletion_requested_by'       => 0,
                'deletion_decided_at'         => null,
                'deletion_decided_by'         => 0,
                'deletion_previous_lists_id'  => 0,
                'deletion_previous_status'    => '',
                'deletion_previous_is_locked' => 0,
                'date_mod'                    => $now,
                'rank'                        => self::nextRankForList($listId),
            ], ['id' => $id]);

            PluginTrallaHistory::log(
                $id,
                $userId,
                'card_deletion_restored',
                'Card restaurado para a lista "' . $listName . '".'
            );

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($id, $userId);
    }

    public static function getDeletedForBoard(int $boards_id): array
    {
        global $DB;

        if (!PluginTrallaBoard::canAdminBoard($boards_id)) {
            throw new RuntimeException('Você não tem acesso aos cards excluídos deste quadro.');
        }

        $boardType = self::getBoardType($boards_id);
        $deleted = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'plugin_tralla_boards_id' => $boards_id,
                'is_deleted'              => 1,
            ],
            'ORDER' => 'deletion_decided_at DESC, id DESC',
        ]) as $row) {
            $previousList = PluginTrallaList::getList((int) ($row['deletion_previous_lists_id'] ?? 0));

            $deleted[] = [
                'id'                     => (int) $row['id'],
                'code'                   => tralla_display_code($row, $boardType),
                'name'                   => (string) $row['name'],
                'deletion_reason'        => (string) ($row['deletion_reason'] ?? ''),
                'requester_name'         => tralla_user_name((int) ($row['deletion_requested_by'] ?? 0)),
                'decider_name'           => tralla_user_name((int) ($row['deletion_decided_by'] ?? 0)),
                'previous_list_name'     => (string) ($previousList['name'] ?? ''),
                'deleted_at_formatted'   => tralla_date($row['deletion_decided_at'] ?? ''),
                'requested_at_formatted' => tralla_date($row['deletion_requested_at'] ?? ''),
            ];
        }

        return $deleted;
    }

    /**
     * Serializa decisões concorrentes sobre o mesmo card.
     *
     * O `SELECT ... FOR UPDATE` não possui equivalente no query builder do GLPI,
     * por isso o SQL bruto é necessário. Deve ser executado dentro da transação
     * que faz a leitura e a escrita, para que o bloqueio valha até o commit.
     */
    private static function familyRoot(array $card): int
    {
        $root = (int) ($card['family_cards_id'] ?? 0);
        if ($root > 0) {
            return $root;
        }
        if ((int) ($card['stage'] ?? 1) === 2) {
            $parentId = (int) ($card['parent_cards_id'] ?? 0);
            if ($parentId > 0) {
                return $parentId;
            }
        }

        return (int) ($card['id'] ?? 0);
    }

    private static function codeRoot(array $card): int
    {
        $root = (int) ($card['code_root_cards_id'] ?? 0);
        return $root > 0 ? $root : self::familyRoot($card);
    }

    private static function nextCodeSequence(int $root): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'family_seq AS max_seq'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => ['code_root_cards_id' => $root],
        ])->current();

        return max(1, (int) ($row['max_seq'] ?? 0)) + 1;
    }

    private static function familyIsIdle(int $root): bool
    {
        global $DB;

        if ($root <= 0) {
            return false;
        }

        $found = false;
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'family_cards_id' => $root,
                'stage'           => 1,
                'is_archived'     => 0,
                'is_deleted'      => 0,
            ],
        ]) as $member) {
            $found = true;
            if (!in_array((string) $member['status'], ['pending', 'in_progress'], true)) {
                return false;
            }
            if ((int) $member['is_locked'] !== 0) {
                return false;
            }
            if ((int) ($member['approval_cards_id'] ?? 0) !== 0) {
                return false;
            }
        }

        return $found;
    }

    private static function lockFamilyRows(int $root): void
    {
        global $DB;

        if ($root <= 0) {
            return;
        }

        $DB->doQuery(sprintf(
            'SELECT `id` FROM `glpi_plugin_tralla_cards`
             WHERE `family_cards_id` = %d
               AND `stage` = 1
               AND `is_archived` = 0
               AND `is_deleted` = 0
             FOR UPDATE',
            $root
        ));
    }

    private static function syncFamilyWithdrawal(int $boards_id, int $root, int $userId): bool
    {
        global $DB;

        if ($boards_id <= 0 || $root <= 0) {
            return false;
        }

        self::lockFamilyRows($root);

        $members = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'family_cards_id' => $root,
                'stage'           => 1,
                'is_archived'     => 0,
                'is_deleted'      => 0,
            ],
        ]) as $member) {
            $members[] = $member;
        }
        if ($members === []) {
            return false;
        }

        $rootCard = null;
        foreach ($members as $member) {
            if ((int) $member['id'] === $root) {
                $rootCard = $member;
            }
            if (!in_array((string) $member['status'], ['approved', 'withdrawal'], true)) {
                return false;
            }
        }
        if ($rootCard === null) {
            return false;
        }

        $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_withdrawal');
        if ($list === null) {
            return false;
        }

        $now = tralla_now();
        $rankRow = $DB->request([
            'SELECT' => ['MAX' => 'rank AS max_rank'],
            'FROM' => 'glpi_plugin_tralla_machines',
            'WHERE' => ['plugin_tralla_cards_id' => $root],
        ])->current();
        $machineRank = (float) ($rankRow['max_rank'] ?? 0);
        foreach ($members as $member) {
            $memberId = (int) $member['id'];
            if ($memberId === $root) {
                continue;
            }
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_plugin_tralla_machines',
                'WHERE' => ['plugin_tralla_cards_id' => $memberId],
                'ORDER' => 'rank ASC, id ASC',
            ]) as $machine) {
                $machineRank += 1024.0;
                if ($DB->update('glpi_plugin_tralla_machines', [
                    'plugin_tralla_cards_id' => $root,
                    'rank' => $machineRank,
                    'date_mod' => $now,
                ], ['id' => (int) $machine['id']]) === false) {
                    throw new RuntimeException('Falha ao reunir as máquinas no card de origem.');
                }
            }
            $DB->update('glpi_plugin_tralla_cards', [
                'is_archived' => 1,
                'is_locked' => 1,
                'machines_total' => 0,
                'date_mod' => $now,
            ], ['id' => $memberId]);
            PluginTrallaHistory::log($memberId, $userId, 'family_merged',
                'Máquinas reunidas no card ' . tralla_display_code($rootCard, 'maintenance') . '.');
        }
        PluginTrallaMachine::refreshCardTotal($root);
        if ((string) $rootCard['status'] === 'approved') {
            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'status' => 'withdrawal',
                'is_locked' => 0,
                'date_mod' => $now,
                'rank' => self::nextRankForList((int) $list['id']),
            ], ['id' => $root]);
        }
        PluginTrallaHistory::log($root, $userId, 'family_withdrawal_ready',
            count($members) > 1
                ? 'Todos os cards aprovados; máquinas reunidas e prontas para retirada.'
                : 'Manutenção aprovada; aguardando retirada.');

        return true;
    }

    private static function buildEditChanges(array $card, array $payload): array
    {
        $changes = [];
        $currentName = trim((string) ($card['name'] ?? ''));
        $proposedName = trim((string) ($payload['name'] ?? ''));
        if ($proposedName !== '' && $proposedName !== $currentName) {
            $changes[] = [
                'type'   => 'name',
                'label'  => 'Nome',
                'before' => $currentName !== '' ? $currentName : '—',
                'after'  => $proposedName,
            ];
        }

        $currentMachines = PluginTrallaMachine::getMachinesForCard((int) ($card['id'] ?? 0));
        $currentById = [];
        foreach ($currentMachines as $machine) {
            $currentById[(int) $machine['id']] = $machine;
        }

        $machineLabel = static function (array $machine): string {
            $label = trim((string) ($machine['label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
            $model = trim((string) ($machine['model_name'] ?? ''));
            if ($model !== '') {
                $sequence = (int) ($machine['sequence'] ?? 0);
                return $sequence > 0 ? $model . ' #' . $sequence : $model;
            }

            return 'Máquina #' . (int) ($machine['id'] ?? 0);
        };

        $statusLabels = tralla_machine_status_labels();
        $kept = [];
        $entries = is_array($payload['machines'] ?? null) ? $payload['machines'] : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $machineId = (int) ($entry['id'] ?? 0);
            if ($machineId <= 0) {
                continue;
            }
            $kept[$machineId] = true;
            if (!isset($currentById[$machineId])) {
                continue;
            }
            $current = $currentById[$machineId];
            $label = $machineLabel($current);

            $status = (string) ($entry['status'] ?? '');
            if ($status !== (string) $current['status']) {
                $changes[] = [
                    'type'       => 'machine_status',
                    'label'      => $label . ': status',
                    'before'     => $statusLabels[(string) $current['status']] ?? (string) $current['status'],
                    'after'      => $statusLabels[$status] ?? $status,
                    'machine_id' => $machineId,
                ];
            }

            $report = trim((string) ($entry['report'] ?? ''));
            $currentReport = trim((string) ($current['report'] ?? ''));
            if ($report !== $currentReport) {
                $changes[] = [
                    'type'       => 'machine_report',
                    'label'      => $label . ': relatório',
                    'before'     => $currentReport !== '' ? $currentReport : '—',
                    'after'      => $report !== '' ? $report : '—',
                    'machine_id' => $machineId,
                ];
            }

            $service = !empty($entry['service_done']) ? 1 : 0;
            if ($service !== (int) ($current['service_done'] ?? 0)) {
                $changes[] = [
                    'type'       => 'machine_service',
                    'label'      => $label . ': serviço realizado',
                    'before'     => !empty($current['service_done']) ? 'Sim' : 'Não',
                    'after'      => $service ? 'Sim' : 'Não',
                    'machine_id' => $machineId,
                ];
            }

            $inventory = !empty($entry['needs_inventory']) ? 1 : 0;
            if ($inventory !== (int) ($current['needs_inventory'] ?? 0)) {
                $changes[] = [
                    'type'       => 'machine_inventory',
                    'label'      => $label . ': necessita inventariar',
                    'before'     => !empty($current['needs_inventory']) ? 'Sim' : 'Não',
                    'after'      => $inventory ? 'Sim' : 'Não',
                    'machine_id' => $machineId,
                ];
            }
        }

        foreach ($currentById as $machineId => $machine) {
            if (isset($kept[$machineId])) {
                continue;
            }
            $changes[] = [
                'type'       => 'machine_removed',
                'label'      => $machineLabel($machine),
                'before'     => 'No card',
                'after'      => 'Removida',
                'machine_id' => $machineId,
            ];
        }

        $newEntries = is_array($payload['new_machines'] ?? null) ? $payload['new_machines'] : [];
        foreach ($newEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $modelId = (int) ($entry['model_id'] ?? 0);
            $quantity = (int) ($entry['quantity'] ?? 1);
            if ($modelId <= 0 || $quantity <= 0) {
                continue;
            }
            $model = PluginTrallaMachineModel::getModel($modelId, true);
            $modelName = $model !== null ? (string) $model['name'] : ('Modelo #' . $modelId);
            $changes[] = [
                'type'   => 'machine_added',
                'label'  => 'Nova máquina',
                'before' => '—',
                'after'  => $quantity . 'x ' . $modelName,
            ];
        }

        return $changes;
    }

    private static function lockCardRow(int $cardId): void
    {
        global $DB;

        $DB->doQuery(sprintf(
            'SELECT `id` FROM `glpi_plugin_tralla_cards` WHERE `id` = %d FOR UPDATE',
            $cardId
        ));
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
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => [
                'split_from_cards_id' => $id,
                'stage'               => 1,
            ],
        ]) as $child) {
            $childIds[] = (int) $child['id'];
        }
        foreach (array_unique($childIds) as $childId) {
            self::deleteCard($childId);
        }

        PluginTrallaFile::purgeForCards([$id]);
        $DB->delete('glpi_plugin_tralla_machines', ['plugin_tralla_cards_id' => $id]);
        $DB->delete('glpi_plugin_tralla_history', ['plugin_tralla_cards_id' => $id]);
        $DB->delete('glpi_plugin_tralla_notifications', ['plugin_tralla_cards_id' => $id]);
        $DB->delete('glpi_plugin_tralla_cards', ['id' => $id]);
    }
}
