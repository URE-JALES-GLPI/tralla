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
        tralla_code_cache(true);

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
        $codesByCard = [];
        if ($boardType === 'maintenance') {
            foreach ($DB->request([
                'SELECT' => ['parent_cards_id', 'edit_payload'],
                'FROM' => 'glpi_plugin_tralla_cards',
                'WHERE' => [
                    'parent_cards_id' => $cardIds,
                    'stage' => 2,
                    'status' => 'approved',
                    'approval_kind' => ['call_release', 'pause', 'resume'],
                ],
                'ORDER' => 'id ASC',
            ]) as $approvalRow) {
                $parentId = (int) $approvalRow['parent_cards_id'];
                $payload = json_decode((string) ($approvalRow['edit_payload'] ?? ''), true);
                $codesByCard[$parentId] = self::mergePauseCodes($codesByCard[$parentId] ?? [], $payload);
            }
            foreach ($cards as $row) {
                if ((int) $row['stage'] !== 1) { continue; }
                $cardId = (int) $row['id'];
                $payload = json_decode((string) ($row['edit_payload'] ?? ''), true);
                $codesByCard[$cardId] = self::mergePauseCodes($codesByCard[$cardId] ?? [], $payload);
            }
        }
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
        $isAdmin = PluginTrallaBoard::roleFor($boards_id) === 'superadmin';

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
            // A conversa do WhatsApp só é exibida no detalhe do card.
            unset($card['whatsapp_transcript']);
            $list = $lists[(int) $card['plugin_tralla_lists_id']] ?? [];
            $card['board_type'] = $boardType;
            $codeOwner = (int) $card['stage'] === 2 ? (int) $card['parent_cards_id'] : (int) $card['id'];
            $card['pause_codes'] = $codesByCard[$codeOwner] ?? ['crm_code' => '', 'external_ticket_code' => ''];
            $card['search_codes'] = implode(' ', array_filter($card['pause_codes']));
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
                if ((string) $card['status'] === 'pending' && !empty($card['is_locked'])) {
                    $card['status_label'] = (int) ($card['approval_cards_id'] ?? 0) > 0
                        ? 'Aguardando liberação' : 'Liberação recusada';
                }
                if (!empty($card['is_paused'])) {
                    $card['status_label'] = 'Pausado';
                }
                $card['pending_approval_kind'] = $pendingApprovalKinds[(int) $card['id']] ?? '';
                if ($card['pending_approval_kind'] === 'pause') {
                    $card['status_label'] = 'Pausa em aprovação';
                }
            }
            $card['source_label'] = tralla_source_label((string) $card['source']);
            $card['sla'] = $boardType === 'maintenance' ? tralla_card_sla($card) : null;
            $card['technician_name'] = tralla_user_name((int) $card['technician_id']);
            $card['technician_picture_url'] = tralla_user_picture_url((int) $card['technician_id']);
            $card['creator_name'] = tralla_user_name((int) $card['users_id']);
            $card['category'] = (string) ($list['category'] ?? '');
            $card['list_name'] = (string) ($list['name'] ?? '');
            $card['assignature_term_url'] = (string) $card['status'] === 'done' && !empty($card['assignature_sent'])
                ? PluginTrallaConfig::assignatureTermUrl((int) $card['id'])
                : '';
            $card['assignature_url'] = (string) $card['status'] === 'withdrawal' && (int) $card['stage'] === 1
                ? PluginTrallaConfig::assignatureCardUrl((int) $card['id'])
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

    private static function mergePauseCodes(array $codes, $payload): array
    {
        $codes += ['crm_code' => '', 'external_ticket_code' => ''];
        if (!is_array($payload)) { return $codes; }
        if (array_key_exists('crm_code', $payload) || array_key_exists('crm', $payload)) {
            $codes['crm_code'] = (string) ($payload['crm_code'] ?? $payload['crm'] ?? '');
        }
        if (array_key_exists('external_ticket_code', $payload)) {
            $codes['external_ticket_code'] = (string) ($payload['external_ticket_code'] ?? '');
        }
        return $codes;
    }

    private static function codesForCard(int $id, string $parentPayload): array
    {
        global $DB;
        $codes = [];
        foreach ($DB->request([
            'SELECT' => ['edit_payload'],
            'FROM' => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'parent_cards_id' => $id,
                'stage' => 2,
                'status' => 'approved',
                'approval_kind' => ['call_release', 'pause', 'resume'],
            ],
            'ORDER' => 'id ASC',
        ]) as $approval) {
            $codes = self::mergePauseCodes($codes, json_decode((string) ($approval['edit_payload'] ?? ''), true));
        }
        return self::mergePauseCodes($codes, json_decode($parentPayload, true));
    }

    /**
     * Detalhe completo do card (modal).
     */
    public static function getCardDetail(int $id, int $userId = 0, bool $splitView = false): array
    {
        global $DB;
        tralla_code_cache(true);

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
        $isAdmin = PluginTrallaBoard::roleFor((int) $card['plugin_tralla_boards_id']) === 'superadmin';

        $card['board_type'] = $boardType;
        $card['list_name'] = (string) ($list['name'] ?? '');
        $card['category'] = (string) ($list['category'] ?? '');
        $card['code'] = tralla_display_code($card, $boardType);
        $card['status_label'] = tralla_status_label((string) $card['status']);
        if ((int) $card['stage'] === 1
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
        $card['sla'] = $boardType === 'maintenance' ? tralla_card_sla($card) : null;
        if ((int) ($card['whatsapp_chats_id'] ?? 0) > 0) {
            // Conversa ao vivo; a cópia gravada só muda quando há novidade e
            // serve de reserva se o WhatsAppSimples sair do ar.
            $live = tralla_whatsapp_live_transcript(tralla_whatsapp_chat_ids($card));
            if ($live !== null && $live !== (string) ($card['whatsapp_transcript'] ?? '')) {
                $DB->update('glpi_plugin_tralla_cards', ['whatsapp_transcript' => $live], ['id' => $id]);
                $card['whatsapp_transcript'] = $live;
            }
        }
        $ticketCard = (int) $card['stage'] === 2 && is_array($parentCard) ? $parentCard : $card;
        $card['tickets_id'] = (int) ($ticketCard['tickets_id'] ?? 0);
        $card['ticket_url'] = $card['tickets_id'] > 0
            ? (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '') . '/front/ticket.form.php?id=' . $card['tickets_id'] : '';
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
        $codeOwnerId = (int) $card['stage'] === 2 ? (int) $card['parent_cards_id'] : $id;
        $codeOwner = $codeOwnerId === $id ? $card : $parentCard;
        $card['pause_codes'] = $codeOwnerId > 0
            ? self::codesForCard($codeOwnerId, $codeOwnerId === $id
                ? $rawEditPayload : (string) ($codeOwner['edit_payload'] ?? ''))
            : ['crm_code' => '', 'external_ticket_code' => ''];
        $card['edit_changes'] = [];
        $card['split_machine_ids'] = [];
        $card['pause_request_reason'] = '';
        $card['transfer_targets'] = [];
        $card['transfer_request'] = null;
        if (!empty($card['is_paused'])) {
            $card['status_label'] = 'Pausado';
        }

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

        $card['split_members'] = [];
        $card['split_snapshot'] = null;
        if ((int) $card['stage'] === 1 && $boardType === 'maintenance') {
            $split = self::splitHistory(self::familyRoot($card), $boardType);
            $merged = false;
            foreach ($split['members'] as $member) {
                $merged = $merged || $member['is_archived'];
            }
            if (count($split['members']) > 1 && $merged) {
                $byMember = [];
                foreach ($split['members'] as $member) {
                    $byMember[$member['id']] = $member;
                    $member['is_current'] = $splitView && $member['id'] === $id;
                    $card['split_members'][] = $member;
                }
                if ($splitView && isset($byMember[$id])) {
                    // Visualização de como o card estava durante a separação.
                    $machines = array_values(array_filter(
                        PluginTrallaMachine::getMachinesForCard($split['root']),
                        static fn (array $machine): bool => ($split['owners'][(int) $machine['id']] ?? $split['root']) === $id
                    ));
                    $card['machines'] = $machines;
                    $card['machines_progress'] = tralla_machines_progress($machines);
                    $card['split_snapshot'] = [
                        'root_id'   => $split['root'],
                        'root_code' => $byMember[$split['root']]['code'] ?? '',
                    ];
                } elseif ($id === $split['root']) {
                    foreach ($card['machines'] as &$machine) {
                        $owner = $byMember[$split['owners'][(int) $machine['id']] ?? $id] ?? null;
                        if ($owner !== null) {
                            $machine['worked_card_id'] = $owner['id'];
                            $machine['worked_card_code'] = $owner['code'];
                            $machine['worked_by_name'] = $owner['technician_name'];
                        }
                    }
                    unset($machine);
                    $card['history'] = self::familyHistory($split['members']);
                }
            }
        }

        if ((int) $card['stage'] === 1 && (int) ($card['approval_cards_id'] ?? 0) > 0) {
            $pendingApproval = self::getCard((int) $card['approval_cards_id']);
            if ($pendingApproval !== null && empty($pendingApproval['is_archived'])) {
                $card['pending_approval_kind'] = (string) ($pendingApproval['approval_kind'] ?? 'maintenance');
                $card['pending_approval_id'] = (int) $pendingApproval['id'];
                if ($card['pending_approval_kind'] === 'pause') {
                    $card['status_label'] = 'Pausa em aprovação';
                }
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

        if ((int) $card['stage'] === 2 && in_array((string) ($card['approval_kind'] ?? ''), ['pause', 'resume'], true)) {
            $payload = json_decode($rawEditPayload, true);
            $card['pause_request_reason'] = is_array($payload) ? (string) ($payload['reason'] ?? '') : '';
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
        if (!empty($card['permissions']['transfer'])) {
            $card['transfer_targets'] = self::transferTargets($card);
        }
        if ((int) $card['stage'] === 2 && in_array((string) ($card['approval_kind'] ?? ''), ['transfer', 'takeover'], true)) {
            $payload = json_decode($rawEditPayload, true);
            if (is_array($payload)) {
                $card['transfer_request'] = [
                    'target'    => (string) ($payload['target'] ?? ''),
                    'users_id'  => (int) ($payload['users_id'] ?? 0),
                    'user_name' => tralla_user_name((int) ($payload['users_id'] ?? 0)),
                    'from_name' => tralla_user_name((int) ($payload['from_users_id'] ?? 0)),
                ];
            }
        }

        if ($card['split_snapshot'] !== null) {
            $card['permissions'] = array_map(static fn ($allowed) => false, $card['permissions']);
            $card['status'] = 'approved';
            $card['status_label'] = 'Card separado';
            $card['sla'] = null;
            $card['family_members'] = [];
            $card['approval_cards_id'] = 0;
            $card['pending_approval_kind'] = '';
            $card['pending_approval_id'] = 0;
            $card['assignature_url'] = '';
            $card['assignature_term_url'] = '';
        }
        $card['current_user_id'] = $userId;

        return $card;
    }

    /**
     * Membros (inclusive arquivados) de uma família separada e o card em que cada
     * máquina foi trabalhada. As máquinas reunidas na origem são atribuídas a partir
     * das separações aprovadas, cujo payload guarda as máquinas enviadas a cada card.
     */
    private static function splitHistory(int $root, string $boardType): array
    {
        global $DB;

        $result = ['root' => $root, 'members' => [], 'owners' => []];
        if ($root <= 0) {
            return $result;
        }

        $children = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => ['family_cards_id' => $root, 'stage' => 1, 'is_deleted' => 0],
            'ORDER' => 'family_seq ASC, id ASC',
        ]) as $member) {
            $memberId = (int) $member['id'];
            $result['members'][] = [
                'id'              => $memberId,
                'code'            => tralla_display_code($member, $boardType),
                'name'            => (string) $member['name'],
                'status'          => (string) $member['status'],
                'status_label'    => tralla_status_label((string) $member['status']),
                'technician_id'   => (int) ($member['technician_id'] ?? 0),
                'technician_name' => tralla_user_name((int) ($member['technician_id'] ?? 0)),
                'date_taken'      => $member['date_taken'],
                'date_finalized'  => $member['date_finalized'],
                'is_archived'     => !empty($member['is_archived']),
            ];
            if ((int) ($member['split_from_cards_id'] ?? 0) > 0) {
                $children[] = ['id' => $memberId, 'from' => (int) $member['split_from_cards_id']];
            }
        }
        if (count($result['members']) <= 1) {
            return $result;
        }

        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'family_cards_id' => $root,
                'stage'           => 2,
                'approval_kind'   => 'split',
                'status'          => 'approved',
            ],
            'ORDER' => 'id ASC',
        ]) as $approval) {
            $childIndex = null;
            foreach ($children as $index => $child) {
                if ($child['from'] === (int) $approval['parent_cards_id'] && $child['id'] > (int) $approval['id']) {
                    $childIndex = $index;
                    break;
                }
            }
            if ($childIndex === null) {
                continue;
            }
            $childId = $children[$childIndex]['id'];
            unset($children[$childIndex]);
            $payload = json_decode((string) ($approval['edit_payload'] ?? ''), true);
            foreach ((array) (is_array($payload) ? ($payload['machine_ids'] ?? []) : []) as $machineId) {
                $result['owners'][(int) $machineId] = $childId;
            }
        }

        // Máquinas que ainda estão em um card separado pertencem a ele.
        foreach ($result['members'] as $member) {
            if ($member['id'] === $root || $member['is_archived']) {
                continue;
            }
            foreach (PluginTrallaMachine::getMachinesForCard($member['id']) as $machine) {
                $result['owners'][(int) $machine['id']] = $member['id'];
            }
        }

        return $result;
    }

    /** Histórico unificado dos cards de uma família, identificando o card de cada evento. */
    private static function familyHistory(array $members): array
    {
        $history = [];
        foreach ($members as $member) {
            foreach (PluginTrallaHistory::getForCard($member['id']) as $entry) {
                $entry['card_id'] = $member['id'];
                $entry['card_code'] = $member['code'];
                $history[] = $entry;
            }
        }
        usort($history, static fn (array $a, array $b): int => [(string) $b['date_creation'], (int) $b['id']]
            <=> [(string) $a['date_creation'], (int) $a['id']]);

        return $history;
    }

    public static function permissionsFor(array $card, string $boardType, int $userId, bool $isAdmin): array
    {
        $boardId = (int) ($card['plugin_tralla_boards_id'] ?? 0);
        $role = PluginTrallaBoard::roleFor($boardId, $userId);
        $isAdmin = $role === 'superadmin';
        $isTablet = $role === 'tablet';
        $isMember = $role !== '' && !$isTablet;
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

        $paused = !empty($card['is_paused']);
        $awaitingDecision = (int) ($card['approval_cards_id'] ?? 0) > 0;
        $canEditMachines = $isMember
            && $boardType === 'maintenance'
            && $stage === 1
            && $status === 'in_progress'
            && !$locked
            && !$awaitingDecision
            && $isTechnician;
        $canFinalize = $isMember
            && $boardType === 'maintenance'
            && $stage === 1
            && $status === 'in_progress'
            && !$locked
            && !$awaitingDecision
            && ($isTechnician || $isAdmin)
            && ($isCall && ($card['machines'] ?? []) === []
                ? trim((string) ($card['service_report'] ?? '')) !== ''
                : (tralla_all_machines_filled($card['machines'] ?? [])
                    && tralla_finalization_partition($card['machines'] ?? [])['ready'] !== []
                    && (!$isCall || tralla_finalization_partition($card['machines'] ?? [])['pending'] === [])));

        $canEditCard = $isMember
            && $boardType === 'normal'
            && $canUpdate;
        $canEditDescription = $isAdmin
            && $boardType === 'maintenance'
            && empty($card['is_deleted'])
            && empty($card['is_archived']);

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
            && !$awaitingDecision
            && $canUpdate;

        // Pausar/despausar: técnico, admin do quadro, quem criou o card ou aprovador.
        $canAskPause = $isMember && $canUpdate && $boardType === 'maintenance' && $stage === 1
            && !$awaitingDecision && empty($card['is_archived']) && empty($card['is_deleted'])
            && (in_array($status, ['pending', 'in_progress'], true) || $paused)
            && ($isTechnician || $isBoardAdmin || (int) ($card['users_id'] ?? 0) === $userId
                || self::userIsApprover($userId, $boardId));
        $canRequestPause = $canAskPause && !$paused && !$locked
            && in_array($status, ['pending', 'in_progress'], true);
        $canRequestResume = $canAskPause && $paused;

        // Transferir (dono do card) ou pedir para pegar (outro membro) um card em andamento.
        $canMoveOwner = $isMember && $canUpdate && $boardType === 'maintenance' && $stage === 1
            && $status === 'in_progress' && !$locked && !$paused && !$awaitingDecision
            && (int) ($card['technician_id'] ?? 0) > 0
            && empty($card['is_archived']) && empty($card['is_deleted']);
        $canTransfer = $canMoveOwner && $isTechnician;
        $canRequestTakeover = $canMoveOwner && !$isTechnician;

        $canRequestCallRelease = $isMember
            && (PluginTrallaBoardUser::getRole($boardId, $userId) === 'superadmin'
                || (int) ($card['users_id'] ?? 0) === $userId)
            && $boardType === 'maintenance'
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

        // Acompanhamento do WhatsApp: aprovadores e admins do quadro marcam "Chamado criado".
        $canAcknowledgeWhatsapp = $isMember
            && $stage === 2
            && $status === 'whatsapp_watch'
            && empty($card['is_archived'])
            && (self::userIsApprover($userId, $boardId) || $isBoardAdmin);

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

        $canTabletEdit = $isTablet
            && $boardType === 'maintenance'
            && !$isCall
            && $stage === 1
            && in_array($status, ['pending', 'in_progress', 'withdrawal'], true)
            && !$locked
            && (int) ($card['approval_cards_id'] ?? 0) === 0
            && empty($card['is_archived'])
            && empty($card['is_deleted']);

        $permissions = [
            'view'             => true,
            'tablet_edit'      => $canTabletEdit,
            'request_pause'    => $canRequestPause,
            'request_resume'   => $canRequestResume,
            'transfer'         => $canTransfer,
            'request_takeover' => $canRequestTakeover,
            'edit_pause_codes' => $boardType === 'maintenance' && $stage === 1 && $paused
                && $isMember && self::userIsApprover($userId, $boardId),
            'edit_card'        => $canEditCard,
            'edit_description' => $canEditDescription,
            'edit_machines'    => $canEditMachines,
            'add_machine'      => $canEditMachines && !$isCall,
            'delete_machine'   => $canEditMachines && !$isCall,
            'split'            => $canSplit,
            'edit_request'     => $canEditRequest,
            'take'             => $canTake,
            'assign_technician' => $canTake && $isAdmin,
            'request_call_release' => $canRequestCallRelease,
            'finalize'         => $canFinalize,
            'approve'          => $canApprove,
            'acknowledge_whatsapp' => $canAcknowledgeWhatsapp,
            'reject'           => $canApprove,
            'withdraw'         => $canConfirm,
            'toggle_withdrawal_notice' => $isMember && $canUpdate && $boardType === 'maintenance'
                && $stage === 1 && $status === 'withdrawal'
                && empty($card['is_archived']) && empty($card['is_deleted']),
            'request_deletion' => $canRequestDeletion,
            'move'             => $isMember && $boardType === 'normal' && $canUpdate,
        ];

        if ($paused) {
            // Pausado: nada além de ver o card e pedir para despausar.
            foreach ($permissions as $name => $allowed) {
                $permissions[$name] = in_array($name, ['view', 'request_resume', 'edit_pause_codes'], true) ? $allowed : false;
            }
        }

        return $permissions;
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
        if ($boardType === 'maintenance') {
            $entityIdValue = trim((string) ($data['entity_id'] ?? ''));
            $entity = ctype_digit($entityIdValue) ? $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM' => 'glpi_entities',
                'WHERE' => ['id' => (int) $entityIdValue],
                'LIMIT' => 1,
            ])->current() : null;
            if (!is_array($entity) || tralla_normalize_label((string) $entity['name']) !== $name) {
                throw new RuntimeException('Selecione uma entidade da lista para o nome do card.');
            }
        }

        $description = trim(strip_tags((string) ($data['description'] ?? '')));
        $callCategory = trim((string) ($data['call_category'] ?? ''));
        $callSubcategory = trim((string) ($data['call_subcategory'] ?? ''));
        if ($boardType === 'maintenance' && $workflowType !== 'call') {
            // Categoria e subcategoria só se aplicam a chamados.
            $callCategory = '';
            $callSubcategory = '';
        } elseif ($boardType === 'maintenance'
            && !tralla_valid_call_category($callCategory, $callSubcategory)) {
            throw new RuntimeException('Selecione uma categoria e subcategoria válidas.');
        }
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
            // Fluxo com prioridade: todo card nasce bloqueado em Pendente e vai para
            // Manutenção Aprovação, onde o aprovador define a prioridade (SLA).
            // Exceção: chamado criado por SuperAdmin com "Disponibilizar agora",
            // confirmado explicitamente como P1 (Alta).
            $isSuperAdmin = PluginTrallaBoardUser::getRole($boards_id, $userId) === 'superadmin';
            $releaseNow = $workflowType === 'call' && $isSuperAdmin
                && (string) ($data['release_mode'] ?? 'later') === 'now';
            if ($releaseNow && empty($data['confirm_p1'])) {
                throw new RuntimeException('Para disponibilizar agora, confirme que o chamado é P1 (Alta).');
            }
            $deferCall = !$releaseNow;
            $approvalList = null;
            if ($deferCall) {
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

            $machines = self::expandRequestedMachines($requestedMachines);

            $DB->beginTransaction();
            try {
            // Serializa a numeração visual do quadro.
            $DB->doQuery(sprintf('SELECT `id` FROM `glpi_plugin_tralla_boards` WHERE `id` = %d FOR UPDATE', $boards_id));
            $numberRow = $DB->request([
                'SELECT' => ['MAX' => 'code_number AS max_number'],
                'FROM'   => 'glpi_plugin_tralla_cards',
                'WHERE'  => ['plugin_tralla_boards_id' => $boards_id],
            ])->current();
            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $list['id'],
                'entities_id'             => (int) $entity['id'],
                'name'                    => $name,
                'description'             => $description,
                'call_category'           => $callCategory,
                'call_subcategory'        => $callSubcategory,
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
                'priority'                => $releaseNow ? 'P1' : '',
                'date_sla_start'          => $releaseNow ? $now : null,
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
                'code_number'     => (int) ($numberRow['max_number'] ?? 0) + 1,
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
                PluginTrallaHistory::log($cardId, $userId, 'call_release_requested',
                    ($workflowType === 'call' ? 'Chamado' : 'Card') . ' criado bloqueado; aguardando aprovação e definição de prioridade.');
            } else {
                PluginTrallaHistory::log($cardId, $userId, 'priority_set', 'Disponibilizado na criação como P1 (Alta), confirmado por quem criou.');
            }

            PluginTrallaHistory::log($cardId, $userId, 'card_created',
                ($workflowType === 'call' ? 'Chamado' : 'Card de manutenção') . ' criado com ' . count($machines) . ' máquina(s).');
            $DB->commit();
            } catch (Throwable $e) {
                $DB->rollBack();
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

    /**
     * Converte as linhas do formulário (modelo + quantidade) em máquinas.
     *
     * @return array<int, array{model_id:int,label:string,serial:string}>
     */
    private static function expandRequestedMachines($requestedMachines): array
    {
        $machines = [];
        foreach ((array) $requestedMachines as $machine) {
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

        return $machines;
    }

    /** Quadro usado pelo WhatsApp: valida tipo, acesso e direitos de quem abre. */
    private static function assertWhatsappBoard(int $boards_id, int $userId): PluginTrallaBoard
    {
        if (!Session::haveRight(self::$rightname, CREATE) || !Session::haveRight(self::$rightname, UPDATE)) {
            throw new RuntimeException('Seu perfil não tem permissão para criar e atender cards no Tralla.');
        }
        $board = new PluginTrallaBoard();
        if ($boards_id <= 0 || !$board->getFromDB($boards_id)) {
            throw new RuntimeException('O quadro #' . $boards_id . ' do Tralla não existe.');
        }
        if ((string) $board->fields['type'] !== 'maintenance') {
            throw new RuntimeException('O quadro "' . $board->fields['name'] . '" não é de manutenção.');
        }
        if (!PluginTrallaBoard::canOperateBoard($boards_id, $userId)) {
            throw new RuntimeException('Você não participa do quadro "' . $board->fields['name'] . '" do Tralla.');
        }

        return $board;
    }

    /**
     * Dados do modal "Novo card" aberto pelo WhatsAppSimples, no quadro fixo
     * da integração: entidades e categorias de chamado do Kanban, mais as prioridades.
     */
    public static function whatsappCreateOptions(int $boards_id, int $userId = 0): array
    {
        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $board = self::assertWhatsappBoard($boards_id, $userId);

        $priorities = [];
        foreach (tralla_priorities() as $key => $spec) {
            $priorities[] = [
                'key'        => $key,
                'label'      => $spec['label'],
                'name'       => $spec['name'],
                'color'      => $spec['color'],
                'first'      => tralla_sla_span_label($spec['first']),
                'palliative' => tralla_sla_span_label($spec['palliative']),
                'definitive' => tralla_sla_span_label($spec['definitive']),
            ];
        }
        $inProgress = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_in_progress');

        return [
            'board'      => [
                'id'               => $boards_id,
                'name'             => (string) $board->fields['name'],
                'in_progress_list' => (string) ($inProgress['name'] ?? 'Manutenção Em Andamento'),
            ],
            'entities'   => tralla_all_entity_options(),
            'call_categories' => tralla_call_categories(),
            'priorities' => $priorities,
        ];
    }

    /**
     * Chamado aberto a partir de um atendimento do WhatsApp.
     *
     * Mesmos dados do Chamado do modal "Novo card" (entidade, categoria e
     * descrição), mas, como o técnico já está atendendo, o card não passa por
     * Pendente nem por aprovação: nasce em "Manutenção Em Andamento", com ele
     * como responsável, a prioridade escolhida por ele e o SLA contando a
     * partir da criação.
     */
    public static function createFromWhatsapp(array $data, int $userId = 0): array
    {
        global $DB;

        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $boards_id = (int) ($data['boards_id'] ?? 0);
        self::assertWhatsappBoard($boards_id, $userId);

        $entityIdValue = trim((string) ($data['entity_id'] ?? ''));
        $entity = ctype_digit($entityIdValue) ? $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => (int) $entityIdValue],
            'LIMIT'  => 1,
        ])->current() : null;
        if (!is_array($entity)) {
            throw new RuntimeException('Selecione uma entidade da lista para o nome do card.');
        }
        $name = tralla_normalize_label((string) $entity['name']);

        $priority = strtoupper(trim((string) ($data['priority'] ?? '')));
        if (!tralla_valid_priority($priority)) {
            throw new RuntimeException('Selecione a prioridade do atendimento (P1 a P4).');
        }
        $callCategory = trim((string) ($data['call_category'] ?? ''));
        $callSubcategory = trim((string) ($data['call_subcategory'] ?? ''));
        if (!tralla_valid_call_category($callCategory, $callSubcategory)) {
            throw new RuntimeException('Selecione uma categoria e subcategoria válidas.');
        }
        $description = trim(strip_tags((string) ($data['description'] ?? '')));
        if ($description === '') {
            throw new RuntimeException('Descreva a situação do chamado.');
        }
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('A descrição deve ter no máximo 2000 caracteres.');
        }
        $chatId = max(0, (int) ($data['whatsapp_chats_id'] ?? 0));
        $transcript = trim((string) ($data['whatsapp_transcript'] ?? ''));

        $list = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_in_progress');
        if ($list === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Em Andamento".');
        }

        $now = tralla_now();
        $DB->beginTransaction();
        try {
            // Serializa a numeração visual do quadro.
            $DB->doQuery(sprintf('SELECT `id` FROM `glpi_plugin_tralla_boards` WHERE `id` = %d FOR UPDATE', $boards_id));
            $numberRow = $DB->request([
                'SELECT' => ['MAX' => 'code_number AS max_number'],
                'FROM'   => 'glpi_plugin_tralla_cards',
                'WHERE'  => ['plugin_tralla_boards_id' => $boards_id],
            ])->current();
            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $list['id'],
                'entities_id'             => (int) $entity['id'],
                'name'                    => $name,
                'description'             => $description,
                'call_category'           => $callCategory,
                'call_subcategory'        => $callSubcategory,
                'rank'                    => self::nextRankForList((int) $list['id']),
                'users_id'                => $userId,
                'technician_id'           => $userId,
                'stage'                   => 1,
                'parent_cards_id'         => 0,
                'approval_cards_id'       => 0,
                'family_cards_id'         => 0,
                'code_root_cards_id'      => 0,
                'family_seq'              => 1,
                'split_from_cards_id'     => 0,
                'status'                  => 'in_progress',
                'workflow_type'           => 'call',
                'is_locked'               => 0,
                'priority'                => $priority,
                'date_sla_start'          => $now,
                'date_taken'              => $now,
                'is_archived'             => 0,
                'source'                  => 'whatsapp',
                'machines_total'          => 0,
                'whatsapp_chats_id'       => $chatId,
                'whatsapp_transcript'     => $transcript,
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $cardId = (int) $DB->insertId();
            $DB->update('glpi_plugin_tralla_cards', [
                'family_cards_id'    => $cardId,
                'code_root_cards_id' => $cardId,
                'code_number'        => (int) ($numberRow['max_number'] ?? 0) + 1,
            ], ['id' => $cardId]);

            $watchCode = self::createWhatsappWatch($cardId, $boards_id, $userId, $now);

            $origin = trim((string) ($data['whatsapp_protocol'] ?? ''));
            PluginTrallaHistory::log($cardId, $userId, 'card_created',
                'Chamado criado pelo WhatsApp' . ($origin !== '' ? ' (atendimento ' . $origin . ')' : '') . '.');
            PluginTrallaHistory::log($cardId, $userId, 'priority_set',
                'Prioridade ' . (tralla_priorities()[$priority]['label'] ?? $priority) . ' definida por quem abriu; SLA iniciado.');
            PluginTrallaHistory::log($cardId, $userId, 'card_taken', 'Card pego por ' . tralla_user_name($userId) . '.');
            if ($watchCode !== '') {
                PluginTrallaHistory::log($cardId, $userId, 'whatsapp_watch_created',
                    'Card ' . $watchCode . ' criado em Manutenção Aprovação só para acompanhamento; este card segue livre.');
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($cardId, $userId);
    }

    /**
     * Card de acompanhamento em "Manutenção Aprovação" para o chamado aberto
     * pelo WhatsApp. Não bloqueia o card em andamento (não preenche
     * approval_cards_id), não tem aprovar/reprovar (status próprio) e é
     * arquivado quando o card de origem é concluído.
     *
     * @return string código do card criado, ou '' se o quadro não tem a lista
     */
    private static function createWhatsappWatch(int $cardId, int $boardId, int $userId, string $now): string
    {
        global $DB;

        $list = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_approval');
        $card = self::getCard($cardId);
        if ($list === null || $card === null) {
            return '';
        }
        $DB->insert('glpi_plugin_tralla_cards', [
            'plugin_tralla_boards_id' => $boardId,
            'plugin_tralla_lists_id'  => (int) $list['id'],
            'entities_id'             => (int) $card['entities_id'],
            'name'                    => (string) $card['name'],
            'description'             => (string) $card['description'],
            'call_category'           => (string) $card['call_category'],
            'call_subcategory'        => (string) $card['call_subcategory'],
            'rank'                    => self::nextRankForList((int) $list['id']),
            'users_id'                => $userId,
            'technician_id'           => $userId,
            'stage'                   => 2,
            'parent_cards_id'         => $cardId,
            'family_cards_id'         => $cardId,
            'code_root_cards_id'      => $cardId,
            'family_seq'              => self::nextCodeSequence($cardId),
            'approval_kind'           => 'whatsapp',
            'workflow_type'           => 'call',
            'status'                  => 'whatsapp_watch',
            'is_locked'               => 0,
            'is_archived'             => 0,
            'source'                  => 'whatsapp',
            'priority'                => (string) $card['priority'],
            'date_creation'           => $now,
            'date_mod'                => $now,
        ]);
        $watchId = (int) $DB->insertId();
        PluginTrallaHistory::log($watchId, $userId, 'whatsapp_watch_created',
            'Acompanhamento do chamado aberto pelo WhatsApp. O card de origem está em atendimento.');
        tralla_code_cache(true);

        return tralla_display_code(self::getCard($watchId) ?? [], 'maintenance');
    }

    /**
     * "Chamado criado": o aprovador registra que viu o chamado aberto pelo
     * WhatsApp; o card de acompanhamento sai de Aprovação (arquivado).
     */
    public static function acknowledgeWhatsappWatch(int $id, int $userId = 0): array
    {
        global $DB;

        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        if (empty(self::permissionsFor($card, 'maintenance', $userId, false)['acknowledge_whatsapp'])) {
            throw new RuntimeException('Somente aprovadores ou administradores do quadro podem marcar o chamado como criado.');
        }

        $now = tralla_now();
        $who = tralla_user_name($userId);
        $DB->update('glpi_plugin_tralla_cards', [
            'is_archived'       => 1,
            'users_id_approver' => $userId,
            'date_approved'     => $now,
            'date_mod'          => $now,
        ], ['id' => $id]);
        PluginTrallaHistory::log($id, $userId, 'whatsapp_watch_acknowledged', 'Chamado criado, confirmado por ' . $who . '.');
        if ((int) $card['parent_cards_id'] > 0) {
            PluginTrallaHistory::log((int) $card['parent_cards_id'], $userId, 'whatsapp_watch_acknowledged',
                'Acompanhamento em Aprovação encerrado: chamado criado, confirmado por ' . $who . '.');
        }

        return self::getCardDetail($id, $userId);
    }

    /** IDs dos cards de acompanhamento ainda ativos de um card do WhatsApp. */
    private static function whatsappWatchIds(int $cardId): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => [
                'parent_cards_id' => $cardId,
                'stage'           => 2,
                'approval_kind'   => 'whatsapp',
                'is_archived'     => 0,
            ],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Conclui direto (sem aprovação) um chamado aberto pelo WhatsApp e encerra
     * o acompanhamento em Aprovação. Exige o relatório do atendimento.
     */
    private static function concludeWhatsappCall(int $id, int $userId): void
    {
        global $DB;

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        $list = PluginTrallaBoard::findListByCategory((int) $card['plugin_tralla_boards_id'], 'maintenance_done');
        if ($list === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Concluído".');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || !tralla_can_transition($card, 'finalize', 'maintenance')) {
                throw new RuntimeException('Este card não está em andamento.');
            }
            if (trim((string) ($card['service_report'] ?? '')) === '') {
                throw new RuntimeException('Preencha o relatório do atendimento antes de finalizar.');
            }
            $now = tralla_now();
            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'status'                 => 'done',
                'is_locked'              => 0,
                'date_finalized'         => $now,
                'date_approved'          => $now,
                'date_mod'               => $now,
                'rank'                   => self::nextRankForList((int) $list['id']),
            ], ['id' => $id]);
            foreach (self::whatsappWatchIds($id) as $watchId) {
                $DB->update('glpi_plugin_tralla_cards', ['is_archived' => 1, 'date_mod' => $now], ['id' => $watchId]);
                PluginTrallaHistory::log($watchId, $userId, 'whatsapp_watch_closed', 'Card de origem concluído; acompanhamento encerrado.');
            }
            PluginTrallaHistory::log($id, $userId, 'card_finalized',
                'Chamado aberto pelo WhatsApp finalizado por ' . tralla_user_name($userId)
                . ' e concluído direto, sem aprovação.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    /**
     * O que o usuário pode fazer, pelo WhatsApp, num card aberto pelo WhatsApp.
     * Editar: técnico responsável ou SuperAdmin do quadro, com o card em andamento.
     * Finalizar: segue a regra do Kanban (só o técnico responsável).
     */
    private static function whatsappPermissions(array $card, int $userId): array
    {
        $inProgress = (int) ($card['stage'] ?? 1) === 1
            && (string) ($card['status'] ?? '') === 'in_progress'
            && empty($card['is_locked'])
            && empty($card['is_deleted'])
            && empty($card['is_archived']);
        $role = PluginTrallaBoard::roleFor((int) $card['plugin_tralla_boards_id'], $userId);
        $isTechnician = (int) ($card['technician_id'] ?? 0) === $userId;
        $canUpdate = Session::haveRight(self::$rightname, UPDATE) && !in_array($role, ['', 'tablet'], true);

        return [
            'edit'     => $inProgress && $canUpdate && ($isTechnician || $role === 'superadmin'),
            'finalize' => $inProgress && $canUpdate && $isTechnician,
        ];
    }

    /** Card ligado a um dos atendimentos do contato, visível para o usuário. */
    private static function whatsappLinkedCard(int $id, array $chatIds): array
    {
        $card = self::getCard($id);
        $chatIds = array_map('intval', $chatIds);
        if ($card === null || (int) $card['stage'] !== 1 || !empty($card['is_deleted'])
            || !in_array((int) ($card['whatsapp_chats_id'] ?? 0), $chatIds, true)) {
            throw new RuntimeException('Card não encontrado para este contato.');
        }
        self::assertBoardAccess($card);

        return $card;
    }

    /**
     * Cards ainda não concluídos abertos pelos atendimentos do contato.
     *
     * @param int[] $chatIds atendimentos do contato no WhatsAppSimples
     */
    public static function whatsappCardsForChats(array $chatIds, int $userId = 0): array
    {
        $chatIds = array_values(array_filter(array_map('intval', $chatIds)));
        if ($chatIds === []) {
            return [];
        }

        return self::whatsappOpenCardRows(['whatsapp_chats_id' => $chatIds], $userId);
    }

    /**
     * Todos os cards ainda não concluídos abertos pelo WhatsApp, nos quadros que o
     * usuário vê (aba "Tralla" das configurações do WhatsAppSimples).
     */
    public static function whatsappOpenCards(int $userId = 0): array
    {
        return self::whatsappOpenCardRows(['whatsapp_chats_id' => ['>', 0]], $userId);
    }

    /** Detalhe completo de um card aberto pelo WhatsApp, sem exigir a conversa atual. */
    public static function whatsappOverviewDetail(int $id, int $userId = 0): array
    {
        $card = self::getCard($id);
        if ($card === null || (int) ($card['whatsapp_chats_id'] ?? 0) <= 0) {
            throw new RuntimeException('Card do WhatsApp não encontrado.');
        }

        return self::whatsappCardDetail($id, [(int) $card['whatsapp_chats_id']], $userId);
    }

    private static function whatsappOpenCardRows(array $where, int $userId): array
    {
        global $DB;

        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        tralla_code_cache(true);
        $cards = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => $where + [
                'stage'             => 1,
                'is_deleted'        => 0,
                'is_archived'       => 0,
                'NOT'               => ['status' => ['done', 'deleted']],
            ],
            'ORDER' => 'id DESC',
        ]) as $row) {
            $boardId = (int) $row['plugin_tralla_boards_id'];
            if (!PluginTrallaBoard::canViewBoard($boardId)) {
                continue;
            }
            $list = PluginTrallaList::getList((int) $row['plugin_tralla_lists_id']);
            $cards[] = [
                'id'                => (int) $row['id'],
                'code'              => tralla_display_code($row, 'maintenance'),
                'name'              => (string) $row['name'],
                'status'            => (string) $row['status'],
                'status_label'      => tralla_status_label((string) $row['status']),
                'list_name'         => (string) ($list['name'] ?? ''),
                'priority'          => (string) $row['priority'],
                'technician_name'   => tralla_user_name((int) $row['technician_id']),
                'date_creation'     => (string) $row['date_creation'],
                'whatsapp_chats_id' => (int) $row['whatsapp_chats_id'],
                'technician_id'     => (int) $row['technician_id'],
                'is_paused'         => !empty($row['is_paused']),
                'sla_next'          => (tralla_card_sla($row) ?? [])['next'] ?? null,
                'wa'                => self::whatsappPermissions($row, $userId),
                'url'               => tralla_web_dir() . '/front/kanban.php?boards_id=' . $boardId . '&card=' . (int) $row['id'],
            ];
        }

        return $cards;
    }

    /** Detalhe do card (o mesmo do Kanban) com as permissões da tela do WhatsApp. */
    public static function whatsappCardDetail(int $id, array $chatIds, int $userId = 0): array
    {
        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $card = self::whatsappLinkedCard($id, $chatIds);
        $detail = self::getCardDetail($id, $userId);
        $detail['wa'] = self::whatsappPermissions($card, $userId);
        $detail['url'] = tralla_web_dir() . '/front/kanban.php?boards_id='
            . (int) $card['plugin_tralla_boards_id'] . '&card=' . $id;

        return $detail;
    }

    /** Edita descrição e relatório do atendimento pela tela do WhatsApp. */
    public static function updateFromWhatsapp(int $id, array $chatIds, string $description, string $report, int $userId = 0): array
    {
        global $DB;

        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $card = self::whatsappLinkedCard($id, $chatIds);
        if (empty(self::whatsappPermissions($card, $userId)['edit'])) {
            throw new RuntimeException('Somente o técnico responsável pode editar o card em andamento.');
        }
        $description = trim(strip_tags($description));
        if ($description === '') {
            throw new RuntimeException('Descreva a situação do chamado.');
        }
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('A descrição deve ter no máximo 2000 caracteres.');
        }
        $report = tralla_normalize_report($report);

        $changes = [];
        if ($description !== (string) $card['description']) {
            $changes['description'] = $description;
        }
        if ($report !== trim((string) ($card['service_report'] ?? ''))) {
            $changes['service_report'] = $report;
        }
        if ($changes !== []) {
            $DB->update('glpi_plugin_tralla_cards', $changes + ['date_mod' => tralla_now()], ['id' => $id]);
            if (isset($changes['description'])) {
                foreach (self::whatsappWatchIds($id) as $watchId) {
                    $DB->update('glpi_plugin_tralla_cards', ['description' => $description], ['id' => $watchId]);
                }
            }
            if (isset($changes['description'])) {
                PluginTrallaHistory::log($id, $userId, 'card_description_updated',
                    tralla_history_change('Descrição', (string) $card['description'], $description) . ' (pelo WhatsApp)');
            }
            if (isset($changes['service_report'])) {
                PluginTrallaHistory::log($id, $userId, 'call_report_updated',
                    tralla_history_change('Relatório', (string) ($card['service_report'] ?? ''), $report) . ' (pelo WhatsApp)');
            }
        }

        return self::whatsappCardDetail($id, $chatIds, $userId);
    }

    /**
     * Transfere o vínculo do card para outro atendimento (ex.: começou com um
     * contato e terminou com outro). O novo atendimento passa a ser o vínculo
     * principal; os anteriores continuam na conversa do card, em ordem.
     */
    public static function transferWhatsappLink(int $id, array $chatIds, int $targetChatId, string $fromLabel, string $toLabel, int $userId = 0): array
    {
        global $DB;

        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $card = self::whatsappLinkedCard($id, $chatIds);
        if (empty(self::whatsappPermissions($card, $userId)['edit'])) {
            throw new RuntimeException('Somente o técnico responsável pode transferir o card em andamento.');
        }
        $current = (int) $card['whatsapp_chats_id'];
        if ($targetChatId <= 0 || $targetChatId === $current) {
            throw new RuntimeException('Selecione outra conversa para vincular ao card.');
        }
        // Voltar para uma conversa que já passou pelo card é permitido: ela sai das
        // anteriores e vira o vínculo principal (o card vai e volta entre contatos).
        $previous = implode(',', array_values(array_diff(tralla_whatsapp_chat_ids($card), [$targetChatId])));
        if (strlen($previous) > 255) {
            throw new RuntimeException('Este card já passou por transferências demais.');
        }

        $DB->update('glpi_plugin_tralla_cards', [
            'whatsapp_chats_id'       => $targetChatId,
            'whatsapp_previous_chats' => $previous,
            'date_mod'                => tralla_now(),
        ], ['id' => $id]);
        PluginTrallaHistory::log($id, $userId, 'whatsapp_link_transferred',
            'Vínculo do WhatsApp transferido de ' . $fromLabel . ' para ' . $toLabel
            . '. As duas conversas continuam no card; o vínculo principal passa a ser ' . $toLabel . '.');

        return self::getCardDetail($id, $userId) + ['previous_chats_id' => $current];
    }

    /** Finaliza pela tela do WhatsApp: o chamado vai direto para Concluído, sem aprovação. */
    public static function finalizeFromWhatsapp(int $id, array $chatIds, int $userId = 0): array
    {
        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        self::whatsappLinkedCard($id, $chatIds);
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            throw new RuntimeException('Sem permissão para finalizar o card.');
        }

        return self::finalize($id, $userId, false)['card'];
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
            'call_category' => (string) ($card['call_category'] ?? ''),
            'call_subcategory' => (string) ($card['call_subcategory'] ?? ''),
            'rank' => self::nextRankForList((int) $list['id']),
            'users_id' => $userId,
            'technician_id' => 0,
            'stage' => 2,
            'parent_cards_id' => $cardId,
            'family_cards_id' => $cardId,
            'code_root_cards_id' => $cardId,
            'family_seq' => $sequence,
            'approval_kind' => 'call_release',
            'workflow_type' => (string) ($card['workflow_type'] ?? 'maintenance'),
            'status' => 'waiting_approval',
            'is_locked' => 0,
            'is_archived' => 0,
            'source' => (string) $card['source'],
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        $approvalId = (int) $DB->insertId();
        PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando aprovação e definição de prioridade.');
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
        if (PluginTrallaBoardUser::getRole($boardId, $userId) !== 'superadmin'
            && (int) ($card['users_id'] ?? 0) !== $userId) {
            throw new RuntimeException('Somente quem criou o card ou um SuperAdmin do quadro pode solicitar a liberação.');
        }
        $approvalList = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_approval');
        if ($approvalList === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($cardId);
            $card = self::getCard($cardId);
            if ($card === null
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

    public static function updateDescription(int $id, string $description, int $userId = 0): array
    {
        global $DB;

        $userId = $userId ?: (int) Session::getLoginUserID();
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        if (empty(self::permissionsFor($card, self::getBoardType((int) $card['plugin_tralla_boards_id']), $userId, false)['edit_description'])) {
            throw new RuntimeException('Somente um SuperAdmin deste quadro pode editar a descrição.');
        }

        $description = trim(strip_tags($description));
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('A descrição deve ter no máximo 2000 caracteres.');
        }
        $now = tralla_now();
        $ids = [$id];
        if ((int) $card['stage'] === 2 && (int) $card['parent_cards_id'] > 0) {
            $parent = self::getCard((int) $card['parent_cards_id']);
            if ($parent !== null && (int) $parent['plugin_tralla_boards_id'] === (int) $card['plugin_tralla_boards_id']
                && empty($parent['is_deleted']) && empty($parent['is_archived'])) {
                $ids[] = (int) $parent['id'];
            }
        } elseif ((int) $card['stage'] === 1 && (int) $card['approval_cards_id'] > 0) {
            $approval = self::getCard((int) $card['approval_cards_id']);
            if ($approval !== null && (int) $approval['parent_cards_id'] === $id
                && empty($approval['is_deleted']) && empty($approval['is_archived'])) {
                $ids[] = (int) $approval['id'];
            }
        }

        $DB->beginTransaction();
        try {
            if ((int) $card['stage'] === 1) {
                $ids = array_merge($ids, self::whatsappWatchIds($id));
            }
            foreach ($ids as $cardId) {
                $DB->update('glpi_plugin_tralla_cards', [
                    'description' => $description,
                    'date_mod' => $now,
                ], ['id' => $cardId]);
            }
            PluginTrallaHistory::log($id, $userId, 'card_description_updated',
                tralla_history_change('Descrição', (string) $card['description'], $description) . ' (pelo SuperAdmin do quadro)');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($id, $userId);
    }

    public static function toggleWithdrawalNotice(int $id, int $userId = 0): array
    {
        global $DB;

        $userId = $userId ?: (int) Session::getLoginUserID();
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);

        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || empty(self::permissionsFor($card, self::getBoardType((int) $card['plugin_tralla_boards_id']), $userId, false)['toggle_withdrawal_notice'])) {
                throw new RuntimeException('Este card não está disponível para marcar o aviso de retirada.');
            }
            $marked = empty($card['withdrawal_notice_marked']) ? 1 : 0;
            $DB->update('glpi_plugin_tralla_cards', [
                'withdrawal_notice_marked' => $marked,
                'date_mod' => tralla_now(),
            ], ['id' => $id]);
            PluginTrallaHistory::log($id, $userId, $marked ? 'withdrawal_notice_marked' : 'withdrawal_notice_cleared',
                $marked ? 'Aviso de retirada marcado no quadro.' : 'Marca de aviso de retirada removida.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

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
        $report = tralla_normalize_report($report);
        $previous = trim((string) ($card['service_report'] ?? ''));
        if ($report === $previous) {
            return self::getCardDetail($id, $userId);
        }
        $DB->update('glpi_plugin_tralla_cards', [
            'service_report' => $report,
            'date_mod' => tralla_now(),
        ], ['id' => $id]);
        PluginTrallaHistory::log($id, $userId, 'call_report_updated', tralla_history_change('Relatório', $previous, $report));

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

    public static function assignTechnician(int $id, int $technicianId, int $actorId = 0): array
    {
        global $DB;

        $actorId = $actorId > 0 ? $actorId : (int) Session::getLoginUserID();
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boardId = (int) $card['plugin_tralla_boards_id'];
        if (PluginTrallaBoardUser::getRole($boardId, $actorId) !== 'superadmin'
            || !Session::haveRight(self::$rightname, UPDATE)) {
            throw new RuntimeException('Apenas SuperAdmins do quadro podem atribuir técnicos.');
        }
        $technician = new User();
        $role = PluginTrallaBoardUser::getRole($boardId, $technicianId);
        if ($technicianId <= 0 || !in_array($role, ['member', 'admin', 'superadmin'], true)
            || !$technician->getFromDB($technicianId)
            || !empty($technician->fields['is_deleted']) || empty($technician->fields['is_active'])) {
            throw new RuntimeException('Selecione um técnico ativo com acesso ao quadro.');
        }
        if (!tralla_can_transition($card, 'take', self::getBoardType($boardId))) {
            throw new RuntimeException('Este card ainda não está disponível para atribuição.');
        }
        $list = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_in_progress');
        if ($list === null) {
            throw new RuntimeException('O quadro não possui a lista Em Andamento.');
        }

        $now = tralla_now();
        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || !tralla_can_transition($card, 'take', self::getBoardType($boardId))) {
                throw new RuntimeException('Este card já foi atribuído ou não está disponível.');
            }
            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'technician_id' => $technicianId,
                'status' => 'in_progress',
                'date_taken' => $now,
                'date_mod' => $now,
                'rank' => self::nextRankForList((int) $list['id']),
            ], ['id' => $id]);
            PluginTrallaHistory::log($id, $actorId, 'card_taken',
                'Card atribuído a ' . tralla_user_name($technicianId) . ' por ' . tralla_user_name($actorId) . '.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($id, $actorId);
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

        // Chamado aberto pelo WhatsApp: conclui direto, sem aprovação (também pelo Kanban).
        if ((string) ($card['source'] ?? '') === 'whatsapp'
            && (string) ($card['workflow_type'] ?? '') === 'call'
            && PluginTrallaMachine::getMachinesForCard($id) === []) {
            if (empty(self::permissionsFor($card, $boardType, $userId, $isAdmin)['edit_machines'])) {
                throw new RuntimeException('Somente o técnico responsável pode finalizar o card.');
            }
            self::concludeWhatsappCall($id, $userId);
            return [
                'card'         => self::getCardDetail($id, $userId),
                'approval'     => null,
                'family_ready' => false,
                'pending_card' => null,
            ];
        }

        $pendingCardId = 0;
        $approvalId = 0;
        $familyReady = false;
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

            // Manutenção finaliza sem aprovação e segue para Retirada; Chamado continua passando pela aprovação.
            $approvalList = $isCall ? PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval') : null;
            if ($isCall && $approvalList === null) {
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
                    'call_category'           => (string) ($card['call_category'] ?? ''),
                    'call_subcategory'        => (string) ($card['call_subcategory'] ?? ''),
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
                    'priority'                => (string) ($card['priority'] ?? ''),
                    'date_sla_start'          => $card['date_sla_start'] ?? null,
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

            if (!$isCall) {
                $DB->update('glpi_plugin_tralla_cards', [
                    'status'            => 'approved',
                    'is_locked'         => 1,
                    'approval_cards_id' => 0,
                    'date_finalized'    => $now,
                    'date_approved'     => $now,
                    'rejection_reason'  => null,
                    'date_mod'          => $now,
                ], ['id' => $id]);
            } else {
                $approvalSeq = self::nextCodeSequence($codeRoot);
                $DB->insert('glpi_plugin_tralla_cards', [
                    'plugin_tralla_boards_id' => $boards_id,
                    'plugin_tralla_lists_id'  => (int) $approvalList['id'],
                    'name'                    => $card['name'],
                    'description'             => $card['description'],
                    'call_category'           => (string) ($card['call_category'] ?? ''),
                    'call_subcategory'        => (string) ($card['call_subcategory'] ?? ''),
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
            }

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
            if ($isCall) {
                $approvalCode = tralla_display_code([
                    'id'              => $approvalId,
                    'stage'           => 2,
                    'parent_cards_id' => $id,
                    'family_cards_id' => $familyRoot,
                    'code_root_cards_id' => $codeRoot,
                    'family_seq'      => $approvalSeq,
                ], $boardType);
                PluginTrallaHistory::log($id, $userId, 'card_finalized', 'Chamado finalizado; card de aprovação ' . $approvalCode . ' criado.');
                PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando decisão de aprovação.');
            } else {
                $familyReady = self::syncFamilyWithdrawal($boards_id, $familyRoot, $userId);
                PluginTrallaHistory::log($id, $userId, 'card_finalized', $familyReady
                    ? 'Manutenção finalizada; card liberado para retirada.'
                    : 'Manutenção finalizada; aguardando os demais cards da família.');
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return [
            'card'     => self::getCardDetail($familyReady ? $familyRoot : $id, $userId),
            'approval' => $approvalId > 0 ? self::getCardDetail($approvalId, $userId) : null,
            'family_ready' => $familyReady,
            'pending_card' => $pendingCardId > 0 ? self::getCardDetail($pendingCardId, $userId) : null,
        ];
    }

    public static function decide(int $approvalId, bool $approve, string $reason = '', int $userId = 0, bool $isAdmin = false, string $priority = '', string $code = ''): array
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
            return self::decideCallRelease($approvalId, $approve, $reason, $userId, $priority, $code);
        }
        if ($kind === 'transfer' || $kind === 'takeover') {
            return self::decideTransfer($approvalId, $approve, $reason, $userId, $code);
        }
        if ($kind === 'pause' || $kind === 'resume') {
            return self::decidePauseChange($approvalId, $approve, $reason, $userId, $code);
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

    private static function decideCallRelease(int $approvalId, bool $approve, string $reason, int $userId, string $priority = '', string $crm = ''): array
    {
        global $DB;
        $priority = strtoupper(trim($priority));
        if ($approve && !tralla_valid_priority($priority)) {
            throw new RuntimeException('Escolha a prioridade (P1 a P4) para liberar o card.');
        }
        $crm = trim(strip_tags($crm));
        if ($approve && ($crm === '' || mb_strlen($crm) > 255)) {
            throw new RuntimeException('Informe o CRM (até 255 caracteres) para liberar o card.');
        }
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
            if ($approve) {
                $parentPayload = json_decode((string) ($parent['edit_payload'] ?? ''), true);
                $parentPayload = is_array($parentPayload) ? $parentPayload : [];
                $parentPayload['crm_code'] = $crm;
                $DB->update('glpi_plugin_tralla_cards', [
                    'edit_payload' => json_encode($parentPayload, JSON_UNESCAPED_UNICODE),
                ], ['id' => $parentId]);
            }
            $DB->update('glpi_plugin_tralla_cards', [
                'approval_cards_id' => 0,
                'is_locked' => $approve ? 0 : 1,
                'priority' => $approve ? $priority : (string) ($parent['priority'] ?? ''),
                'date_sla_start' => $approve ? $now : ($parent['date_sla_start'] ?? null),
                'users_id_approver' => $userId,
                'date_approved' => $approve ? $now : null,
                'rejection_reason' => !$approve && $reason !== '' ? $reason : null,
                'date_mod' => $now,
            ], ['id' => $parentId]);
            $DB->update('glpi_plugin_tralla_cards', [
                'status' => $approve ? 'approved' : 'rejected',
                'is_archived' => 1,
                'edit_payload' => $approve ? json_encode(['crm' => $crm], JSON_UNESCAPED_UNICODE) : ($approval['edit_payload'] ?? null),
                'users_id_approver' => $userId,
                'rejection_reason' => !$approve && $reason !== '' ? $reason : null,
                'date_mod' => $now,
            ], ['id' => $approvalId]);
            $what = (string) ($parent['workflow_type'] ?? '') === 'call' ? 'Chamado' : 'Card';
            $message = $approve
                ? $what . ' liberado com prioridade ' . (tralla_priorities()[$priority]['label'] ?? $priority) . '. CRM: ' . $crm . '; já pode ser assumido por um técnico.'
                : 'Liberação recusada.' . ($reason !== '' ? ' Motivo: ' . $reason : '');
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

                self::applyEditPayload($parentId, $payload);
                $DB->update('glpi_plugin_tralla_cards', [
                    'approval_cards_id' => 0,
                    'is_locked'         => 0,
                    'date_mod'          => $now,
                ], ['id' => $parentId]);

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

    /** Permite ao aprovador corrigir os identificadores de um card já pausado. */
    public static function updatePauseCodes(int $id, string $crmCode, string $externalCode, int $userId = 0): array
    {
        global $DB;
        $userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
        $crmCode = trim($crmCode);
        $externalCode = trim($externalCode);
        foreach ([$crmCode, $externalCode] as $value) {
            if ($value !== '' && (mb_strlen($value) > 100
                || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $value))) {
                throw new RuntimeException('Informe códigos válidos com letras e números (até 100 caracteres).');
            }
        }
        $card = self::getCard($id);
        if ($card === null) { throw new RuntimeException('Card não encontrado.'); }
        self::assertBoardAccess($card);
        $boardId = (int) $card['plugin_tralla_boards_id'];
        if (self::getBoardType($boardId) !== 'maintenance' || (int) $card['stage'] !== 1
            || empty($card['is_paused']) || !empty($card['is_archived']) || !empty($card['is_deleted'])
            || !self::userIsApprover($userId, $boardId)) {
            throw new RuntimeException('Somente aprovadores podem editar códigos de cards pausados.');
        }
        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $card = self::getCard($id);
            if ($card === null || empty($card['is_paused']) || !empty($card['is_archived'])
                || !empty($card['is_deleted'])) {
                throw new RuntimeException('O card não está mais pausado.');
            }
            $payload = json_decode((string) ($card['edit_payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $payload['crm_code'] = $crmCode;
            $payload['external_ticket_code'] = $externalCode;
            $DB->update('glpi_plugin_tralla_cards', [
                'edit_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'date_mod' => tralla_now(),
            ], ['id' => $id]);
            PluginTrallaHistory::log($id, $userId, 'pause_codes_updated',
                'Códigos atualizados. CRM: ' . ($crmCode ?: 'não informado')
                . '; Chamado Externo: ' . ($externalCode ?: 'não informado') . '.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return self::getCardDetail($id, $userId);
    }

    /**
     * Pede para pausar ($kind = 'pause') ou despausar ($kind = 'resume') o card.
     * Cria um card de aprovação. O pedido de pausa já coloca o card na lista de pausados;
     * a suspensão efetiva dos prazos acontece somente depois da aprovação.
     */
    /** Membros do quadro (sem Tablet) que podem receber o card, exceto o técnico atual. */
    private static function transferTargets(array $card): array
    {
        global $DB;

        $targets = [];
        foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => [
                'plugin_tralla_boards_id' => (int) $card['plugin_tralla_boards_id'],
                'role'                    => ['member', 'admin', 'superadmin'],
                'NOT'                     => ['users_id' => (int) ($card['technician_id'] ?? 0)],
            ],
        ]) as $row) {
            $name = tralla_user_name((int) $row['users_id']);
            if ($name !== '') {
                $targets[] = ['id' => (int) $row['users_id'], 'name' => $name];
            }
        }
        usort($targets, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $targets;
    }

    /**
     * O técnico do card pede para devolvê-lo a Pendente ($target = 'pending') ou passá-lo a
     * outro técnico ($target = 'technician'); outro membro pede para pegá-lo ($target = 'takeover').
     * Cria um card de aprovação; nada muda até a decisão.
     */
    public static function requestTransfer(int $id, string $target, int $targetUserId = 0, int $userId = 0): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }
        if (!in_array($target, ['pending', 'technician', 'takeover'], true)) {
            throw new RuntimeException('Escolha para onde transferir o card.');
        }
        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boards_id = (int) $card['plugin_tralla_boards_id'];
        $boardType = self::getBoardType($boards_id);
        $kind = $target === 'takeover' ? 'takeover' : 'transfer';

        $approvalId = 0;
        $DB->beginTransaction();
        try {
            self::lockCardRow(self::codeRoot($card));
            self::lockCardRow($id);
            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            $permissions = self::permissionsFor($fresh, $boardType, $userId, false);
            if (empty($permissions[$kind === 'takeover' ? 'request_takeover' : 'transfer'])) {
                throw new RuntimeException($kind === 'takeover'
                    ? 'Este card não pode ser pego agora.' : 'Somente o técnico do card em andamento pode transferi-lo.');
            }
            if ($target === 'takeover') {
                $targetUserId = $userId;
            } elseif ($target === 'technician') {
                if (!in_array($targetUserId, array_column(self::transferTargets($fresh), 'id'), true)) {
                    throw new RuntimeException('Escolha um técnico do quadro.');
                }
            } else {
                $targetUserId = 0;
            }
            $approvalList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
            if ($approvalList === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
            }

            $codeRoot = self::codeRoot($fresh);
            $now = tralla_now();
            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $approvalList['id'],
                'name'                    => (string) $fresh['name'],
                'description'             => $fresh['description'],
                'call_category'           => (string) ($fresh['call_category'] ?? ''),
                'call_subcategory'        => (string) ($fresh['call_subcategory'] ?? ''),
                'rank'                    => self::nextRankForList((int) $approvalList['id']),
                'users_id'                => $userId,
                'technician_id'           => (int) $fresh['technician_id'],
                'stage'                   => 2,
                'parent_cards_id'         => $id,
                'approval_cards_id'       => 0,
                'family_cards_id'         => self::familyRoot($fresh),
                'code_root_cards_id'      => $codeRoot,
                'family_seq'              => self::nextCodeSequence($codeRoot),
                'split_from_cards_id'     => 0,
                'approval_kind'           => $kind,
                'workflow_type'           => (string) ($fresh['workflow_type'] ?? 'maintenance'),
                'edit_payload'            => json_encode([
                    'target'        => $target === 'pending' ? 'pending' : 'technician',
                    'users_id'      => $targetUserId,
                    'from_users_id' => (int) $fresh['technician_id'],
                ], JSON_UNESCAPED_UNICODE),
                'status'                  => 'waiting_approval',
                'is_locked'               => 0,
                'is_archived'             => 0,
                'source'                  => (string) $fresh['source'],
                'machines_total'          => (int) ($fresh['machines_total'] ?? 0),
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $approvalId = (int) $DB->insertId();
            $DB->update('glpi_plugin_tralla_cards', [
                'approval_cards_id' => $approvalId,
                'date_mod'          => $now,
            ], ['id' => $id]);

            $message = $target === 'pending'
                ? 'Transferência para Pendente solicitada; aguardando aprovação.'
                : ($target === 'takeover'
                    ? tralla_user_name($userId) . ' pediu para pegar o card; aguardando aprovação.'
                    : 'Transferência para ' . tralla_user_name($targetUserId) . ' solicitada; aguardando aprovação.');
            PluginTrallaHistory::log($id, $userId, 'transfer_requested', $message);
            PluginTrallaHistory::log($approvalId, $userId, 'approval_created', $message);
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

    /** Aprovar exige o Código CRM, gravado nos códigos do card. */
    private static function decideTransfer(int $approvalId, bool $approve, string $reason, int $userId, string $code): array
    {
        global $DB;

        $approval = self::getCard($approvalId);
        $kind = (string) ($approval['approval_kind'] ?? '');
        if ($approval === null || (int) $approval['stage'] !== 2 || !in_array($kind, ['transfer', 'takeover'], true)
            || (string) $approval['status'] !== 'waiting_approval' || !empty($approval['is_archived'])) {
            throw new RuntimeException('Pedido não encontrado.');
        }
        $parentId = (int) $approval['parent_cards_id'];
        $parent = self::getCard($parentId);
        if ($parent === null) {
            throw new RuntimeException('Card de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        $boardId = (int) $parent['plugin_tralla_boards_id'];
        if (!self::userIsApprover($userId, $boardId)) {
            throw new RuntimeException('Sem permissão para aprovar ou recusar.');
        }
        $reason = trim(strip_tags($reason));
        $code = trim($code);
        if ($approve && (mb_strlen($code) > 100 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $code))) {
            throw new RuntimeException('Informe um Código CRM válido (letras e números, até 100 caracteres).');
        }
        $payload = json_decode((string) ($approval['edit_payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $toPending = (string) ($payload['target'] ?? '') === 'pending';
        $targetUserId = (int) ($payload['users_id'] ?? 0);
        $pendingList = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_pending');
        if ($approve && $toPending && $pendingList === null) {
            throw new RuntimeException('O quadro não possui a lista "Manutenção Pendente".');
        }
        if ($approve && !$toPending && !in_array(PluginTrallaBoard::roleFor($boardId, $targetUserId), ['member', 'admin', 'superadmin'], true)) {
            throw new RuntimeException('O técnico escolhido não é mais membro do quadro.');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($parentId);
            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null || $parent === null
                || (string) $approval['status'] !== 'waiting_approval' || !empty($approval['is_archived'])
                || (int) ($parent['approval_cards_id'] ?? 0) !== $approvalId
                || (string) $parent['status'] !== 'in_progress' || !empty($parent['is_paused'])) {
                throw new RuntimeException('Este pedido não está mais aguardando aprovação.');
            }

            $now = tralla_now();
            $fields = ['approval_cards_id' => 0, 'date_mod' => $now];
            if ($approve && $toPending) {
                $fields += [
                    'status'                 => 'pending',
                    'technician_id'          => 0,
                    'plugin_tralla_lists_id' => (int) $pendingList['id'],
                    'rank'                   => self::nextRankForList((int) $pendingList['id']),
                    'pending_reminded_at'    => $now,
                ];
            } elseif ($approve) {
                $fields += ['technician_id' => $targetUserId, 'sla_alerts_sent' => ''];
            }
            if ($approve) {
                $parentPayload = json_decode((string) ($parent['edit_payload'] ?? ''), true);
                $parentPayload = is_array($parentPayload) ? $parentPayload : [];
                $parentPayload['crm_code'] = $code;
                $fields['edit_payload'] = json_encode($parentPayload, JSON_UNESCAPED_UNICODE);
            }
            $DB->update('glpi_plugin_tralla_cards', $fields, ['id' => $parentId]);
            $DB->update('glpi_plugin_tralla_cards', [
                'status'            => $approve ? 'approved' : 'rejected',
                'is_archived'       => 1,
                'edit_payload'      => json_encode($payload + ($approve ? ['crm_code' => $code] : []), JSON_UNESCAPED_UNICODE),
                'users_id_approver' => $userId,
                'rejection_reason'  => !$approve && $reason !== '' ? $reason : null,
                'date_mod'          => $now,
            ], ['id' => $approvalId]);

            $from = tralla_user_name((int) ($payload['from_users_id'] ?? 0));
            if ($approve) {
                $message = ($toPending
                    ? 'Card devolvido a Pendente, sem técnico'
                    : 'Card transferido de ' . ($from !== '' ? $from : 'sem técnico') . ' para ' . tralla_user_name($targetUserId))
                    . '. Código CRM: ' . $code . '.';
            } else {
                $message = 'Transferência recusada; o card continua com ' . ($from !== '' ? $from : 'o técnico atual') . '.'
                    . ($reason !== '' ? ' Motivo: ' . $reason : '');
            }
            PluginTrallaHistory::log($parentId, $userId, $approve ? 'transfer_approved' : 'transfer_rejected', $message);
            PluginTrallaHistory::log($approvalId, $userId, $approve ? 'approval_approved' : 'approval_rejected', $message);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($parentId, $userId);
    }

    public static function requestPauseChange(int $id, string $kind, string $reason, int $userId = 0): array
    {
        global $DB;

        if ($userId <= 0) {
            $userId = (int) Session::getLoginUserID();
        }
        if (!in_array($kind, ['pause', 'resume'], true)) {
            throw new RuntimeException('Pedido inválido.');
        }
        $reason = trim(strip_tags($reason));
        if ($kind === 'pause' && $reason === '') {
            throw new RuntimeException('Informe o motivo da pausa.');
        }
        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 2000);
        }

        $card = self::getCard($id);
        if ($card === null) {
            throw new RuntimeException('Card não encontrado.');
        }
        self::assertBoardAccess($card);
        $boards_id = (int) $card['plugin_tralla_boards_id'];
        $boardType = self::getBoardType($boards_id);
        $permission = $kind === 'pause' ? 'request_pause' : 'request_resume';
        $pausedList = $kind === 'pause' ? PluginTrallaBoard::ensurePausedList($boards_id) : null;
        if ($kind === 'pause' && $pausedList === null) {
            throw new RuntimeException('O quadro não possui a lista Manutenção Pausados.');
        }

        $approvalId = 0;
        $DB->beginTransaction();
        try {
            self::lockCardRow(self::codeRoot($card));
            self::lockCardRow($id);
            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            if (empty(self::permissionsFor($fresh, $boardType, $userId, false)[$permission])) {
                throw new RuntimeException($kind === 'pause'
                    ? 'Este card não pode ser pausado agora.' : 'Este card não pode ser despausado agora.');
            }
            $approvalList = PluginTrallaBoard::findListByCategory($boards_id, 'maintenance_approval');
            if ($approvalList === null) {
                throw new RuntimeException('O quadro não possui a lista "Manutenção Aprovação".');
            }

            $codeRoot = self::codeRoot($fresh);
            $now = tralla_now();
            $DB->insert('glpi_plugin_tralla_cards', [
                'plugin_tralla_boards_id' => $boards_id,
                'plugin_tralla_lists_id'  => (int) $approvalList['id'],
                'name'                    => (string) $fresh['name'],
                'description'             => $fresh['description'],
                'call_category'           => (string) ($fresh['call_category'] ?? ''),
                'call_subcategory'        => (string) ($fresh['call_subcategory'] ?? ''),
                'rank'                    => self::nextRankForList((int) $approvalList['id']),
                'users_id'                => $userId,
                'technician_id'           => (int) $fresh['technician_id'],
                'stage'                   => 2,
                'parent_cards_id'         => $id,
                'approval_cards_id'       => 0,
                'family_cards_id'         => self::familyRoot($fresh),
                'code_root_cards_id'      => $codeRoot,
                'family_seq'              => self::nextCodeSequence($codeRoot),
                'split_from_cards_id'     => 0,
                'approval_kind'           => $kind,
                'workflow_type'           => (string) ($fresh['workflow_type'] ?? 'maintenance'),
                'edit_payload'            => json_encode([
                    'reason' => $reason,
                    'previous_list_id' => (int) $fresh['plugin_tralla_lists_id'],
                    'previous_status' => (string) $fresh['status'],
                    'technician_id' => (int) $fresh['technician_id'],
                ], JSON_UNESCAPED_UNICODE),
                'status'                  => 'waiting_approval',
                'is_locked'               => 0,
                'is_archived'             => 0,
                'source'                  => (string) $fresh['source'],
                'machines_total'          => (int) ($fresh['machines_total'] ?? 0),
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            $approvalId = (int) $DB->insertId();
            $parentUpdate = [
                'approval_cards_id' => $approvalId,
                'date_mod'          => $now,
            ];
            if ($kind === 'pause') {
                $parentUpdate['plugin_tralla_lists_id'] = (int) $pausedList['id'];
                $parentUpdate['rank'] = self::nextRankForList((int) $pausedList['id']);
            }
            $DB->update('glpi_plugin_tralla_cards', $parentUpdate, ['id' => $id]);

            $what = $kind === 'pause' ? 'Pausa' : 'Despausa';
            PluginTrallaHistory::log($id, $userId, $kind . '_requested',
                $what . ' solicitada; aguardando aprovação.' . ($reason !== '' ? ' Motivo: ' . $reason : ''));
            PluginTrallaHistory::log($approvalId, $userId, 'approval_created', 'Aguardando decisão: ' . mb_strtolower($what) . '.');
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

    private static function decidePauseChange(int $approvalId, bool $approve, string $reason, int $userId, string $code = ''): array
    {
        global $DB;

        $approval = self::getCard($approvalId);
        $kind = (string) ($approval['approval_kind'] ?? '');
        if ($approval === null || (int) $approval['stage'] !== 2 || !in_array($kind, ['pause', 'resume'], true)
            || (string) $approval['status'] !== 'waiting_approval' || !empty($approval['is_archived'])) {
            throw new RuntimeException('Pedido não encontrado.');
        }
        $parentId = (int) $approval['parent_cards_id'];
        $parent = self::getCard($parentId);
        if ($parent === null) {
            throw new RuntimeException('Card de origem não encontrado.');
        }
        self::assertBoardAccess($parent);
        $boardId = (int) $parent['plugin_tralla_boards_id'];
        if (!self::userIsApprover($userId, $boardId)) {
            throw new RuntimeException('Sem permissão para aprovar ou recusar.');
        }
        $reason = trim(strip_tags($reason));
        $code = trim($code);
        if ($approve && (mb_strlen($code) > 100 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $code))) {
            throw new RuntimeException($kind === 'pause'
                ? 'Informe um Chamado Externo válido (letras e números, até 100 caracteres).'
                : 'Informe um Código CRM válido (letras e números, até 100 caracteres).');
        }
        $pausedList = PluginTrallaBoard::ensurePausedList($boardId);
        $pendingList = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_pending');
        if ($approve && ($kind === 'pause' ? $pausedList === null : $pendingList === null)) {
            throw new RuntimeException('Lista de destino não encontrada no quadro.');
        }

        $DB->beginTransaction();
        try {
            self::lockCardRow($parentId);
            $approval = self::getCard($approvalId);
            $parent = self::getCard($parentId);
            if ($approval === null || $parent === null
                || (string) $approval['status'] !== 'waiting_approval' || !empty($approval['is_archived'])
                || (int) ($parent['approval_cards_id'] ?? 0) !== $approvalId
                || ($kind === 'pause') === !empty($parent['is_paused'])) {
                throw new RuntimeException('Este pedido não está mais aguardando aprovação.');
            }

            $now = tralla_now();
            $payload = json_decode((string) ($approval['edit_payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $requestReason = (string) ($payload['reason'] ?? '');
            $fields = ['approval_cards_id' => 0, 'date_mod' => $now];
            if ($approve && $kind === 'pause') {
                $fields += [
                    'is_paused' => 1,
                    'paused_at' => $now,
                    'pause_reason' => $requestReason,
                    'plugin_tralla_lists_id' => (int) $pausedList['id'],
                    'rank' => self::nextRankForList((int) $pausedList['id']),
                ];
            } elseif ($approve) {
                $pausedFor = max(0, time() - (int) strtotime((string) $parent['paused_at']));
                $fields += [
                    'is_paused'           => 0,
                    'paused_at'           => null,
                    'paused_seconds'      => 0,
                    'status'              => 'pending',
                    'technician_id'       => 0,
                    'date_taken'          => null,
                    'date_sla_start'      => $now,
                    'plugin_tralla_lists_id' => (int) $pendingList['id'],
                    'rank'                => self::nextRankForList((int) $pendingList['id']),
                    'pending_reminded_at' => $now,
                    'sla_alerts_sent'     => '',
                ];
            } elseif ($kind === 'pause') {
                $previousId = (int) ($payload['previous_list_id'] ?? 0);
                $previousList = $previousId > 0 ? PluginTrallaList::getList($previousId) : null;
                if ($previousList === null || (int) $previousList['plugin_tralla_boards_id'] !== $boardId
                    || !empty($previousList['is_archived'])) {
                    $previousList = $pendingList;
                }
                if ($previousList === null) {
                    throw new RuntimeException('Lista original do card não encontrada.');
                }
                $fields['plugin_tralla_lists_id'] = (int) $previousList['id'];
                $fields['rank'] = self::nextRankForList((int) $previousList['id']);
            }
            $DB->update('glpi_plugin_tralla_cards', $fields, ['id' => $parentId]);
            if ($approve) {
                $parentPayload = json_decode((string) ($parent['edit_payload'] ?? ''), true);
                $parentPayload = is_array($parentPayload) ? $parentPayload : [];
                $parentPayload[$kind === 'pause' ? 'external_ticket_code' : 'crm_code'] = $code;
                $DB->update('glpi_plugin_tralla_cards', [
                    'edit_payload' => json_encode($parentPayload, JSON_UNESCAPED_UNICODE),
                ], ['id' => $parentId]);
            }
            $DB->update('glpi_plugin_tralla_cards', [
                'status'            => $approve ? 'approved' : 'rejected',
                'is_archived'       => 1,
                'edit_payload'      => $approve ? json_encode($payload + [
                    $kind === 'pause' ? 'external_ticket_code' : 'crm_code' => $code,
                ], JSON_UNESCAPED_UNICODE) : ($approval['edit_payload'] ?? null),
                'users_id_approver' => $userId,
                'rejection_reason'  => !$approve && $reason !== '' ? $reason : null,
                'date_mod'          => $now,
            ], ['id' => $approvalId]);

            if ($kind === 'pause') {
                $message = $approve
                    ? 'Card pausado. Chamado Externo: ' . $code . '; prazos de SLA, lembretes e ações suspensos.' . ($requestReason !== '' ? ' Motivo: ' . $requestReason : '')
                    : 'Pausa recusada.' . ($reason !== '' ? ' Motivo: ' . $reason : '');
            } else {
                $message = $approve
                    ? 'Card despausado após ' . tralla_duration_label((int) ($pausedFor ?? 0)) . ' pausado. Código CRM: ' . $code . '. Card devolvido a Pendente sem técnico; novo ciclo de SLA iniciado.'
                    : 'Despausa recusada; o card continua pausado.' . ($reason !== '' ? ' Motivo: ' . $reason : '');
            }
            PluginTrallaHistory::log($parentId, $userId, $kind . ($approve ? '_approved' : '_rejected'), $message);
            PluginTrallaHistory::log($approvalId, $userId, $approve ? 'approval_approved' : 'approval_rejected', $message);
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

        [$proposedName, $machines, $newMachines, $newTotal] = self::normalizeEditInput($card, $data);

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
                'call_category'           => (string) ($fresh['call_category'] ?? ''),
                'call_subcategory'        => (string) ($fresh['call_subcategory'] ?? ''),
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
                'call_category'           => (string) ($fresh['call_category'] ?? ''),
                'call_subcategory'        => (string) ($fresh['call_subcategory'] ?? ''),
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
                    'call_category' => (string) ($parent['call_category'] ?? ''),
                    'call_subcategory' => (string) ($parent['call_subcategory'] ?? ''),
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
                    'priority' => (string) ($parent['priority'] ?? ''),
                    'date_sla_start' => $parent['date_sla_start'] ?? null,
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
                PluginTrallaHistory::log($parentId, $userId, 'card_split', 'Separação aprovada; card ' . tralla_code_number($codeRoot) . '/' . $seq . ' criado.');
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
                    'call_category'           => (string) ($fresh['call_category'] ?? ''),
                    'call_subcategory'        => (string) ($fresh['call_subcategory'] ?? ''),
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
            // Só cards principais contam: aprovações não consomem número (3/2 é o primeiro separado).
            'WHERE'  => ['code_root_cards_id' => $root, 'stage' => 1],
        ])->current();

        tralla_code_cache(true);
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
        // Resumo de quem trabalhou em cada card antes de reunir as máquinas.
        $summary = [];
        foreach ($members as $member) {
            $count = count(PluginTrallaMachine::getMachinesForCard((int) $member['id']));
            $technician = tralla_user_name((int) ($member['technician_id'] ?? 0));
            $summary[] = tralla_display_code($member, 'maintenance') . ': '
                . ($technician !== '' ? $technician : 'sem técnico') . ' (' . $count . ' máquina' . ($count === 1 ? '' : 's') . ')';
        }
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
                'Máquinas reunidas no card ' . tralla_display_code($rootCard, 'maintenance')
                . '. Técnico: ' . (tralla_user_name((int) ($member['technician_id'] ?? 0)) ?: 'sem técnico') . '.');
        }
        PluginTrallaMachine::refreshCardTotal($root);
        if ((string) $rootCard['status'] === 'approved') {
            $DB->update('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => (int) $list['id'],
                'status' => 'withdrawal',
                'withdrawal_notice_marked' => 0,
                'is_locked' => 0,
                'date_mod' => $now,
                'rank' => self::nextRankForList((int) $list['id']),
            ], ['id' => $root]);
        }
        PluginTrallaHistory::log($root, $userId, 'family_withdrawal_ready',
            count($members) > 1
                ? 'Todos os cards finalizados; máquinas reunidas e prontas para retirada. ' . implode('; ', $summary) . '.'
                : 'Manutenção concluída; aguardando retirada.');

        return true;
    }

    /**
     * Valida nome e máquinas de uma edição de card.
     *
     * @return array{0: string, 1: array, 2: array, 3: int}
     */
    private static function normalizeEditInput(array $card, array $data): array
    {
        $proposedName = tralla_normalize_label($data['name'] ?? '');
        if ($proposedName === '') {
            throw new RuntimeException('Informe o nome do card.');
        }

        $currentMachines = PluginTrallaMachine::getMachinesForCard((int) $card['id']);
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
            throw new RuntimeException('Nenhuma alteração para salvar.');
        }

        return [$proposedName, $machines, $newMachines, $newTotal];
    }

    /** Aplica nome e máquinas de uma edição validada ao card. */
    private static function applyEditPayload(int $parentId, array $payload): void
    {
        global $DB;

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
            'name'     => $proposedName,
            'date_mod' => tralla_now(),
        ], ['id' => $parentId]);
        PluginTrallaMachine::refreshCardTotal($parentId);
    }

    /**
     * Edição direta do papel Tablet: aplica na hora, sem aprovação, e registra
     * no histórico cada alteração feita.
     */
    public static function tabletEdit(int $id, array $data, int $userId = 0): array
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

        $DB->beginTransaction();
        try {
            self::lockCardRow($id);
            $fresh = self::getCard($id);
            if ($fresh === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            $permissions = self::permissionsFor($fresh, $boardType, $userId, false);
            if (empty($permissions['tablet_edit'])) {
                throw new RuntimeException('Sem permissão para editar este card.');
            }

            [$proposedName, $machines, $newMachines] = self::normalizeEditInput($fresh, $data);
            $payload = ['name' => $proposedName, 'machines' => $machines, 'new_machines' => $newMachines];
            $changes = self::buildEditChanges($fresh, $payload);
            self::applyEditPayload($id, $payload);

            $lines = array_map(static function (array $change): string {
                $short = static fn (string $text): string => function_exists('mb_strimwidth')
                    ? mb_strimwidth($text, 0, 300, '…') : substr($text, 0, 300);
                return $change['label'] . ': ' . $short((string) $change['before']) . ' → ' . $short((string) $change['after']);
            }, $changes);
            PluginTrallaHistory::log($id, $userId, 'tablet_edit',
                'Card editado pelo tablet (sem aprovação).' . ($lines !== [] ? "\n" . implode("\n", $lines) : ''));

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return self::getCardDetail($id, $userId);
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
