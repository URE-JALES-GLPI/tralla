<?php
/**
 * Endpoint read-only para o assignat-ure.
 *
 * Uso: GET /plugins/tralla/front/assignature.php?card=300&token=...
 * Alternativa: header X-Tralla-Token.
 */
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $DB;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    tralla_json(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

$configuredToken = PluginTrallaConfig::assignatureToken();
$providedToken = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_TRALLA_TOKEN'] ?? '');

if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    tralla_json(['ok' => false, 'error' => 'Token inválido.'], 401);
}

header('Cache-Control: no-store');

$listMode = strtolower(trim((string) ($_GET['list'] ?? '')));
if ($listMode === 'withdrawal') {
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
    $cards = [];

    foreach ($DB->request([
        'FROM'  => 'glpi_plugin_tralla_cards',
        'WHERE' => [
            'status'      => 'withdrawal',
            'stage'       => 1,
            'is_archived' => 0,
        ],
        'ORDER' => 'date_approved ASC, id ASC',
        'LIMIT' => $limit,
    ]) as $row) {
        $board = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_boards',
            'WHERE' => ['id' => (int) $row['plugin_tralla_boards_id']],
            'LIMIT' => 1,
        ])->current();

        if (!is_array($board) || (string) $board['type'] !== 'maintenance') {
            continue;
        }

        $machines = PluginTrallaMachine::getMachinesForCard((int) $row['id']);
        $cards[] = [
            'id'              => (int) $row['id'],
            'code'            => tralla_display_code($row, 'maintenance'),
            'name'            => (string) $row['name'],
            'status'          => (string) $row['status'],
            'status_label'    => tralla_status_label((string) $row['status']),
            'board'           => [
                'id'   => (int) $board['id'],
                'name' => (string) $board['name'],
            ],
            'technician'      => [
                'id'   => (int) $row['technician_id'],
                'name' => tralla_user_name((int) $row['technician_id']),
            ],
            'machines_total'  => count($machines),
            'date_approved'   => $row['date_approved'],
            'assignature_sent'=> (int) $row['assignature_sent'],
        ];
    }

    tralla_json([
        'ok'   => true,
        'data' => [
            'cards' => $cards,
            'count' => count($cards),
        ],
    ]);
}

$cardId = (int) ($_GET['card'] ?? $_GET['cards_id'] ?? 0);
if ($cardId <= 0 && isset($_GET['code'])) {
    $code = trim((string) $_GET['code']);
    if (preg_match('/^(\d+)\/2$/', $code, $matches)) {
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => ['parent_cards_id' => (int) $matches[1], 'stage' => 2],
            'LIMIT'  => 1,
        ])->current();
        $cardId = $row ? (int) $row['id'] : 0;
    } elseif (preg_match('/^(\d+)(?:\/1)?$/', ltrim($code, '#'), $matches)) {
        $cardId = (int) $matches[1];
    }
}

$card = $cardId > 0 ? PluginTrallaCard::getCard($cardId) : null;
if ($card === null) {
    tralla_json(['ok' => false, 'error' => 'Card não encontrado.'], 404);
}

$boards_id = (int) $card['plugin_tralla_boards_id'];
$board = $DB->request([
    'FROM'  => 'glpi_plugin_tralla_boards',
    'WHERE' => ['id' => $boards_id],
    'LIMIT' => 1,
])->current();

if (!is_array($board) || (string) $board['type'] !== 'maintenance') {
    tralla_json(['ok' => false, 'error' => 'Card não pertence a um quadro de manutenção.'], 404);
}

$list = PluginTrallaList::getList((int) $card['plugin_tralla_lists_id']);
$machines = PluginTrallaMachine::getMachinesForCard($cardId);

$machinePayload = [];
foreach ($machines as $machine) {
    $machinePayload[] = [
        'id'              => (int) $machine['id'],
        'model_id'        => (int) $machine['plugin_tralla_machine_models_id'],
        'model_name'      => (string) $machine['model_name'],
        'label'           => (string) $machine['label'],
        'serial'          => (string) $machine['serial'],
        'report'          => (string) $machine['report'],
        'status'          => (string) $machine['status'],
        'status_label'    => (string) $machine['status_label'],
        'service_done'    => (int) $machine['service_done'],
        'needs_inventory' => (int) $machine['needs_inventory'],
        'is_filled'       => (bool) $machine['is_filled'],
        'report_saved_at' => $machine['report_saved_at'],
    ];
}

tralla_json([
    'ok'   => true,
    'data' => [
        'id'              => (int) $card['id'],
        'code'            => tralla_display_code($card, 'maintenance'),
        'parent_id'       => (int) $card['parent_cards_id'],
        'stage'           => (int) $card['stage'],
        'name'            => (string) $card['name'],
        'description'     => (string) $card['description'],
        'status'          => (string) $card['status'],
        'status_label'    => tralla_status_label((string) $card['status']),
        'source'          => (string) $card['source'],
        'source_label'    => tralla_source_label((string) $card['source']),
        'board'           => [
            'id'   => (int) $board['id'],
            'name' => (string) $board['name'],
            'type' => (string) $board['type'],
        ],
        'list'            => [
            'id'             => (int) ($list['id'] ?? 0),
            'name'           => (string) ($list['name'] ?? ''),
            'category'       => (string) ($list['category'] ?? ''),
            'category_label' => tralla_category_label((string) ($list['category'] ?? '')),
        ],
        'technician'      => [
            'id'   => (int) $card['technician_id'],
            'name' => tralla_user_name((int) $card['technician_id']),
        ],
        'approver'        => [
            'id'   => (int) $card['users_id_approver'],
            'name' => tralla_user_name((int) $card['users_id_approver']),
        ],
        'machines'        => $machinePayload,
        'machines_filled' => tralla_all_machines_filled($machines),
        'progress'        => tralla_machines_progress($machines),
        'date_creation'   => $card['date_creation'],
        'date_mod'        => $card['date_mod'],
        'date_taken'      => $card['date_taken'],
        'date_finalized'  => $card['date_finalized'],
        'date_approved'   => $card['date_approved'],
        'date_withdrawn'  => $card['date_withdrawn'],
        'assignature_sent'=> (int) $card['assignature_sent'],
    ],
]);
