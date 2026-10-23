<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $CFG_GLPI;

Session::checkRight('plugin_tralla', READ);
tralla_check_csrf($_POST);

if (!function_exists('tralla_ajax_require')) {
    function tralla_ajax_require(int $right): void
    {
        if (!Session::haveRight('plugin_tralla', $right)) {
            throw new RuntimeException('Sem permissão para esta ação.');
        }
    }
}

if (!function_exists('tralla_ajax_state')) {
    function tralla_ajax_state(int $boards_id): array
    {
        return tralla_board_state(
            $boards_id,
            PluginTrallaCard::userIsApprover((int) Session::getLoginUserID(), $boards_id)
        );
    }
}

$action = tralla_post_string('action', tralla_post_string('action_get', ''));
$userId = (int) Session::getLoginUserID();
$isAdmin = Session::haveRight('plugin_tralla', PURGE);

try {
    switch ($action) {
        case 'state':
            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canViewBoard($boards_id)) {
                throw new RuntimeException('Quadro sem acesso.');
            }
            tralla_json(['ok' => true, 'data' => tralla_ajax_state($boards_id)]);

        case 'board_ping':
            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canViewBoard($boards_id)) {
                throw new RuntimeException('Quadro sem acesso.');
            }
            tralla_json([
                'ok'   => true,
                'data' => [
                    'version' => PluginTrallaBoard::getVersion($boards_id),
                    'cursor'  => PluginTrallaHistory::getLatestIdForBoard($boards_id),
                ],
            ]);

        case 'board_poll':
            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canViewBoard($boards_id)) {
                throw new RuntimeException('Quadro sem acesso.');
            }
            $activity = PluginTrallaHistory::getActivityForBoard(
                $boards_id,
                tralla_post_int('after_id', 0),
                20
            );
            tralla_json([
                'ok'   => true,
                'data' => [
                    'state'    => tralla_ajax_state($boards_id),
                    'activity' => $activity['rows'],
                    'cursor'   => $activity['last_id'],
                    'version'  => PluginTrallaBoard::getVersion($boards_id),
                ],
            ]);

        case 'list_add':
            tralla_ajax_require(CREATE);
            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canViewBoard($boards_id)) {
                throw new RuntimeException('Quadro sem acesso.');
            }
            $category = tralla_post_string('category');
            if ($category !== '' && PluginTrallaList::categoryExists($boards_id, $category)) {
                throw new RuntimeException('Já existe uma lista com esta categoria neste quadro.');
            }
            $list = new PluginTrallaList();
            $list->check(-1, CREATE, $_POST);
            $newId = $list->add([
                'plugin_tralla_boards_id' => $boards_id,
                'name'                    => tralla_post_string('name'),
                'category'                => $category,
                'color'                   => tralla_post_string('color'),
                'rank'                    => (float) tralla_post_string('rank', '0'),
            ]);
            if (!$newId) {
                throw new RuntimeException('Não foi possível criar a lista.');
            }
            tralla_json([
                'ok'   => true,
                'data' => [
                    'state' => tralla_ajax_state($boards_id),
                    'list'  => PluginTrallaList::getList((int) $newId),
                ],
            ]);

        case 'list_update':
            tralla_ajax_require(UPDATE);
            $listId = tralla_post_int('id');
            $list = PluginTrallaList::getList($listId);
            if ($list === null || !PluginTrallaBoard::canViewBoard((int) $list['plugin_tralla_boards_id'])) {
                throw new RuntimeException('Lista não encontrada.');
            }
            $category = tralla_post_string('category', (string) $list['category']);
            if ($category !== '' && PluginTrallaList::categoryExists((int) $list['plugin_tralla_boards_id'], $category, $listId)) {
                throw new RuntimeException('Já existe uma lista com esta categoria neste quadro.');
            }
            $item = new PluginTrallaList();
            $item->check($listId, UPDATE);
            $item->update([
                'id'       => $listId,
                'name'     => tralla_post_string('name', (string) $list['name']),
                'category' => $category,
                'color'    => tralla_post_string('color', (string) ($list['color'] ?? '')),
            ]);
            tralla_json(['ok' => true, 'data' => ['state' => tralla_ajax_state((int) $list['plugin_tralla_boards_id'])]]);

        case 'list_delete':
            tralla_ajax_require(DELETE);
            $listId = tralla_post_int('id');
            $list = PluginTrallaList::getList($listId);
            if ($list === null || !PluginTrallaBoard::canViewBoard((int) $list['plugin_tralla_boards_id'])) {
                throw new RuntimeException('Lista não encontrada.');
            }
            if ((string) $list['category'] !== '') {
                throw new RuntimeException('Listas do fluxo de manutenção não podem ser excluídas.');
            }
            $cardCount = countElementsInTable('glpi_plugin_tralla_cards', [
                'plugin_tralla_lists_id' => $listId,
            ]);
            if ($cardCount > 0) {
                throw new RuntimeException('Mova ou exclua os cards desta lista antes de removê-la.');
            }
            $item = new PluginTrallaList();
            $item->check($listId, DELETE);
            $item->delete(['id' => $listId], 1);
            tralla_json(['ok' => true, 'data' => ['state' => tralla_ajax_state((int) $list['plugin_tralla_boards_id'])]]);

        case 'list_reorder':
            tralla_ajax_require(UPDATE);
            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canViewBoard($boards_id)) {
                throw new RuntimeException('Quadro sem acesso.');
            }
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) {
                throw new RuntimeException('Ordem inválida.');
            }
            $rank = 1000.0;
            global $DB;
            foreach ($ids as $id) {
                $id = (int) $id;
                $list = PluginTrallaList::getList($id);
                if ($list === null || (int) $list['plugin_tralla_boards_id'] !== $boards_id) {
                    continue;
                }
                $DB->update('glpi_plugin_tralla_lists', [
                    'rank'      => $rank,
                    'date_mod'  => tralla_now(),
                ], ['id' => $id]);
                $rank += 1000.0;
            }
            tralla_json(['ok' => true, 'data' => ['state' => tralla_ajax_state($boards_id)]]);

        case 'card_add':
            tralla_ajax_require(CREATE);
            $boards_id = tralla_post_int('boards_id');
            $machines = $_POST['machines'] ?? [];
            if (is_string($machines)) {
                $machines = json_decode($machines, true) ?: [];
            }
            if (!is_array($machines)) {
                $machines = [];
            }
            $card = PluginTrallaCard::create([
                'boards_id'   => $boards_id,
                'list_id'     => tralla_post_int('list_id'),
                'name'        => tralla_post_string('name'),
                'description' => tralla_post_string('description'),
                'source'      => tralla_post_string('source', 'manual'),
                'machines'    => $machines,
            ], $userId);
            tralla_json([
                'ok'   => true,
                'data' => [
                    'state' => tralla_ajax_state($boards_id),
                    'card'  => $card,
                ],
            ]);

        case 'card_get':
            $cardId = tralla_post_int('id');
            tralla_json(['ok' => true, 'data' => PluginTrallaCard::getCardDetail($cardId, $userId)]);

        case 'card_update':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::updateCard($cardId, [
                'name'        => tralla_post_string('name'),
                'description' => tralla_post_string('description'),
            ], $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => ['card' => $detail, 'state' => tralla_ajax_state((int) $detail['plugin_tralla_boards_id'])]]);

        case 'card_move':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::move(
                $cardId,
                tralla_post_int('list_id'),
                (float) tralla_post_string('rank', '0'),
                $userId
            );
            tralla_json(['ok' => true, 'data' => ['card' => $detail, 'state' => tralla_ajax_state((int) $detail['plugin_tralla_boards_id'])]]);

        case 'card_request_deletion':
            $cardId = tralla_post_int('id');
            $result = PluginTrallaCard::requestDeletion(
                $cardId,
                tralla_post_string('reason'),
                $userId,
                $isAdmin
            );
            tralla_json(['ok' => true, 'data' => [
                'state' => tralla_ajax_state((int) ($result['card']['plugin_tralla_boards_id'] ?? 0)),
                'card' => $result['card'],
                'approval' => $result['approval'],
            ]]);

        case 'split_card':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('id');
            $machineIds = json_decode(tralla_post_string('machine_ids', '[]'), true);
            if (!is_array($machineIds)) {
                throw new RuntimeException('Selecione ao menos uma máquina.');
            }
            $result = PluginTrallaCard::split($cardId, $machineIds, $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => [
                'state' => tralla_ajax_state((int) $result['card']['plugin_tralla_boards_id']),
                'card'  => $result['card'],
                'child' => $result['child'],
            ]]);

        case 'edit_request':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('id');
            $machines = json_decode(tralla_post_string('machines', '[]'), true);
            $newMachines = json_decode(tralla_post_string('new_machines', '[]'), true);
            if (!is_array($machines) || !is_array($newMachines)) {
                throw new RuntimeException('Dados da edição inválidos.');
            }
            $result = PluginTrallaCard::requestEdit($cardId, [
                'name'         => tralla_post_string('name'),
                'machines'     => $machines,
                'new_machines' => $newMachines,
            ], $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => [
                'state'    => tralla_ajax_state((int) $result['card']['plugin_tralla_boards_id']),
                'card'     => $result['card'],
                'approval' => $result['approval'],
            ]]);

        case 'deleted_cards':            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canAdminBoard($boards_id)) {
                throw new RuntimeException('Você não tem acesso aos cards excluídos deste quadro.');
            }
            tralla_json(['ok' => true, 'data' => ['cards' => PluginTrallaCard::getDeletedForBoard($boards_id)]]);

        case 'restore_card':
            $cardId = tralla_post_int('id');
            $card = PluginTrallaCard::restoreDeleted($cardId, $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => [
                'state' => tralla_ajax_state((int) ($card['plugin_tralla_boards_id'] ?? 0)),
                'card' => $card,
            ]]);

        case 'board_history':
            $boards_id = tralla_post_int('boards_id');
            if (!PluginTrallaBoard::canViewBoard($boards_id) || PluginTrallaBoard::roleFor($boards_id) === '') {
                throw new RuntimeException('Quadro sem acesso.');
            }
            $filters = [
                'card_id'   => tralla_post_string('card_id'),
                'date_from' => tralla_post_string('date_from'),
                'date_to'   => tralla_post_string('date_to'),
                'page'      => tralla_post_int('page', 1),
            ];
            tralla_json(['ok' => true, 'data' => PluginTrallaHistory::getForBoard($boards_id, $filters)]);

        case 'take_card':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::take($cardId, $userId);
            tralla_json(['ok' => true, 'data' => ['card' => $detail, 'state' => tralla_ajax_state((int) $detail['plugin_tralla_boards_id'])]]);

        case 'machine_update':
            tralla_ajax_require(UPDATE);
            $machineId = tralla_post_int('machine_id');
            $data = [];
            foreach (['report', 'status', 'service_done', 'needs_inventory', 'label', 'serial'] as $field) {
                if (array_key_exists($field, $_POST)) {
                    $data[$field] = $_POST[$field];
                }
            }
            $result = PluginTrallaCard::updateMachine($machineId, $data, $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => $result]);

        case 'machine_add':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('card_id');
            $detail = PluginTrallaCard::addMachine($cardId, [
                'model_id' => tralla_post_int('model_id'),
                'label'    => tralla_post_string('label'),
                'serial'   => tralla_post_string('serial'),
            ], $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => ['card' => $detail]]);

        case 'machine_delete':
            tralla_ajax_require(UPDATE);
            $machineId = tralla_post_int('machine_id');
            $detail = PluginTrallaCard::deleteMachine($machineId, $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => ['card' => $detail]]);

        case 'finalize_card':
            tralla_ajax_require(UPDATE);
            $cardId = tralla_post_int('id');
            $result = PluginTrallaCard::finalize($cardId, $userId, $isAdmin);
            tralla_json([
                'ok'   => true,
                'data' => [
                    'card'     => $result['card'],
                    'approval' => $result['approval'],
                    'state'    => tralla_ajax_state((int) $result['card']['plugin_tralla_boards_id']),
                ],
            ]);

        case 'approve_card':
            tralla_ajax_require(READ);
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::decide($cardId, true, '', $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => ['card' => $detail, 'state' => tralla_ajax_state((int) $detail['plugin_tralla_boards_id'])]]);

        case 'reject_card':
            tralla_ajax_require(READ);
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::decide($cardId, false, tralla_post_string('reason'), $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => ['card' => $detail, 'state' => tralla_ajax_state((int) $detail['plugin_tralla_boards_id'])]]);

        case 'confirm_withdrawal':
            tralla_ajax_require(READ);
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::confirmWithdrawal($cardId, $userId, $isAdmin);
            tralla_json(['ok' => true, 'data' => ['card' => $detail, 'state' => tralla_ajax_state((int) $detail['plugin_tralla_boards_id'])]]);

        case 'model_add':
            tralla_ajax_require(CREATE);
            $model = new PluginTrallaMachineModel();
            $model->check(-1, CREATE, $_POST);
            $modelId = $model->add([
                'name'         => tralla_post_string('name'),
                'entities_id'  => tralla_post_int('entities_id', (int) ($_SESSION['glpiactive_entity'] ?? 0)),
                'is_recursive' => 0,
                'comment'      => tralla_post_string('comment'),
            ]);
            if (!$modelId) {
                throw new RuntimeException('Não foi possível cadastrar o modelo.');
            }
            $row = PluginTrallaMachineModel::getModel((int) $modelId);
            tralla_json(['ok' => true, 'data' => ['model' => ['id' => (int) $modelId, 'name' => (string) ($row['name'] ?? '')]]]);

        case 'model_list':
            tralla_json(['ok' => true, 'data' => ['models' => array_map(static function (array $row): array {
                return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }, PluginTrallaMachineModel::getModels())]]);

        case 'history':
            $cardId = tralla_post_int('id');
            $detail = PluginTrallaCard::getCardDetail($cardId, $userId);
            tralla_json(['ok' => true, 'data' => ['history' => $detail['history']]]);

        default:
            throw new RuntimeException('Ação inválida.');
    }
} catch (Throwable $e) {
    tralla_json([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
