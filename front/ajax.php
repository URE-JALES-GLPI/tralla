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
        return [
            'lists' => PluginTrallaList::getListsForBoard($boards_id),
            'cards' => PluginTrallaCard::getCardsForBoard($boards_id),
        ];
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

        case 'card_delete':
            if (!Session::haveRight('plugin_tralla', DELETE) && !Session::haveRight('plugin_tralla', PURGE)) {
                throw new RuntimeException('Sem permissão para esta ação.');
            }
            $cardId = tralla_post_int('id');
            $card = PluginTrallaCard::getCard($cardId);
            if ($card === null) {
                throw new RuntimeException('Card não encontrado.');
            }
            $boardType = PluginTrallaCard::getBoardType((int) $card['plugin_tralla_boards_id']);
            $permissions = PluginTrallaCard::permissionsFor($card, $boardType, $userId, $isAdmin);
            if (empty($permissions['delete'])) {
                throw new RuntimeException('Sem permissão para excluir este card.');
            }
            if ((int) $card['stage'] === 2) {
                throw new RuntimeException('Cards de aprovação são arquivados automaticamente.');
            }
            $boards_id = (int) $card['plugin_tralla_boards_id'];
            PluginTrallaCard::deleteCard($cardId);
            tralla_json(['ok' => true, 'data' => ['state' => tralla_ajax_state($boards_id)]]);

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
