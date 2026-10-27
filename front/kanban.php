<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $CFG_GLPI;

Session::checkRight('plugin_tralla', READ);

$boards_id = tralla_get_int('boards_id');
$board = new PluginTrallaBoard();
if ($boards_id <= 0 || !$board->getFromDB($boards_id) || !PluginTrallaBoard::canViewBoard($boards_id)) {
    Session::addMessageAfterRedirect('Quadro não encontrado ou sem acesso.', false, ERROR);
    Html::redirect(tralla_web_dir() . '/front/board.php');
}

$pluginUrl = (string) $CFG_GLPI['root_doc'] . '/plugins/tralla';
$isMaintenance = ($board->fields['type'] ?? '') === 'maintenance';
$isUpdates = ($board->fields['type'] ?? '') === 'updates';

$userId = (int) Session::getLoginUserID();
$role = PluginTrallaBoard::roleFor($boards_id);
$roleLabel = tralla_board_role_label($role);
$canManageLists = !$isUpdates && PluginTrallaBoard::canAdminBoard($boards_id) && Session::haveRight('plugin_tralla', UPDATE);
$canManageBoard = PluginTrallaBoard::canAdminBoard($boards_id) && Session::haveRight('plugin_tralla', UPDATE);
$canManageMembers = PluginTrallaBoard::canGovernBoard($boards_id) && Session::haveRight('plugin_tralla', UPDATE);
$canViewDeleted = PluginTrallaBoard::canAdminBoard($boards_id);
$canRestore = tralla_can_restore($role, Session::haveRight('plugin_tralla', UPDATE));
$canCreateCard = PluginTrallaBoard::canOperateBoard($boards_id) && Session::haveRight('plugin_tralla', CREATE);
$canMoveCard = PluginTrallaBoard::canOperateBoard($boards_id) && Session::haveRight('plugin_tralla', UPDATE) && !$isMaintenance && !$isUpdates;
$canApprove = PluginTrallaCard::userIsApprover($userId, $boards_id);

$boardState = tralla_board_state($boards_id, $canApprove);
$lists = $boardState['lists'];
$cards = $boardState['cards'];
$deletedCards = $canViewDeleted ? PluginTrallaCard::getDeletedForBoard($boards_id) : [];
$models = PluginTrallaMachineModel::getModels();

$bootstrap = [
    'board' => [
        'id'             => (int) $boards_id,
        'name'           => (string) $board->fields['name'],
        'type'           => (string) $board->fields['type'],
        'type_label'     => $isMaintenance ? 'Manutenção' : ($isUpdates ? 'Atualizações' : 'Normal'),
        'background'     => (string) $board->fields['background'],
        'color'          => (string) $board->fields['color'],
        'comment'        => (string) $board->fields['comment'],
        'is_maintenance' => $isMaintenance,
        'is_updates'     => $isUpdates,
        'entities_id'    => (int) $board->fields['entities_id'],
        'role'           => $role,
        'role_label'     => $roleLabel,
        'can_defer_call' => PluginTrallaBoardUser::getRole($boards_id, $userId) === 'superadmin',
        'can_rename_lists' => PluginTrallaBoardUser::getRole($boards_id, $userId) === 'superadmin'
            && Session::haveRight('plugin_tralla', UPDATE),
    ],
    'lists' => array_map(static function (array $list): array {
        return [
            'id'           => (int) $list['id'],
            'name'         => (string) $list['name'],
            'category'     => (string) $list['category'],
            'category_label' => tralla_category_label((string) $list['category']),
            'color'        => (string) ($list['color'] ?? ''),
            'rank'         => (float) $list['rank'],
        ];
    }, $lists),
    'cards' => $cards,
    'deleted_cards' => $deletedCards,
    'activity_cursor' => PluginTrallaHistory::getLatestIdForBoard($boards_id),
    'version'         => PluginTrallaBoard::getVersion($boards_id),
    'entities' => tralla_all_entity_options(),
    'call_categories' => tralla_call_categories(),
    'models' => array_map(static function (array $model): array {
        return [
            'id'   => (int) $model['id'],
            'name' => (string) $model['name'],
        ];
    }, $models),
    'machine_quantity_max' => PluginTrallaCard::MAX_MACHINE_QUANTITY,
    'labels' => [
        'categories'       => tralla_category_labels(),
        'statuses'         => tralla_status_labels(),
        'machine_statuses' => tralla_machine_status_labels(),
        'machine_choices'  => tralla_machine_status_choices(),
        'sources'          => tralla_source_labels(),
        'source_choices'   => tralla_source_choices(),
    ],
    'rights' => [
        'read'    => Session::haveRight('plugin_tralla', READ),
        'create'  => Session::haveRight('plugin_tralla', CREATE),
        'update'  => Session::haveRight('plugin_tralla', UPDATE),
        'delete'  => Session::haveRight('plugin_tralla', DELETE),
        'purge'   => Session::haveRight('plugin_tralla', PURGE),
        'approve' => $canApprove,
    ],
    'can' => [
        'create_card'    => $canCreateCard,
        'move_card'      => $canMoveCard,
        'manage_lists'   => $canManageLists,
        'manage_board'   => $canManageBoard,
        'manage_members' => $canManageMembers,
        'view_deleted'   => $canViewDeleted,
        'restore'        => $canRestore,
        'approve'        => $canApprove,
    ],
    'user' => [
        'id'   => $userId,
        'name' => tralla_user_name($userId),
    ],
    'urls' => [
        'ajax'       => $pluginUrl . '/front/ajax.php',
        'board'      => $pluginUrl . '/front/board.php',
        'kanban'     => $pluginUrl . '/front/kanban.php',
        'board_form' => $pluginUrl . '/front/board.form.php',
        'config'     => $pluginUrl . '/front/config.form.php',
        'assignature'=> $pluginUrl . '/front/assignature.php',
    ],
    'csrf' => tralla_csrf_token(),
];

$background = tralla_valid_background((string) $board->fields['background']);

Html::header('Tralla', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');
echo "<script>document.title = 'Tralla';</script>";
?>
<div class="tralla-page tralla-kanban-page tr-bg-<?= tralla_h($background) ?>" id="tralla-kanban">
    <header class="tralla-kanban-head">
        <div class="tralla-kanban-title">
            <a class="tralla-icon-btn tralla-back-btn" href="<?= tralla_h($pluginUrl) ?>/front/board.php" title="Voltar aos quadros" aria-label="Voltar aos quadros">
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
            </a>
            <div class="tralla-board-heading">
                <h1><?= tralla_h($board->fields['name']) ?></h1>
            </div>
        </div>
        <div class="tralla-kanban-search" role="search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <label class="tralla-visually-hidden" for="tralla-board-search">Pesquisar cards</label>
            <input id="tralla-board-search" type="search" placeholder="Pesquisar entidade, técnico ou ID..." autocomplete="off" spellcheck="false">
            <span class="tralla-search-count" data-search-count hidden></span>
            <button type="button" class="tralla-icon-btn tralla-search-clear" data-search-clear hidden aria-label="Limpar pesquisa" title="Limpar pesquisa">
                <i class="ti ti-x" aria-hidden="true"></i>
            </button>
        </div>
        <div class="tralla-head-actions">
            <button type="button" class="tralla-btn tralla-kanban-action" data-board-history>
                <i class="ti ti-history" aria-hidden="true"></i> Histórico
            </button>
            <?php if ($canViewDeleted): ?>
                <button type="button" class="tralla-btn tralla-kanban-action" data-board-deleted>
                    <i class="ti ti-trash" aria-hidden="true"></i> Excluídos
                </button>
            <?php endif; ?>
            <?php if ($canManageLists && !$isMaintenance): ?>
                <button type="button" class="tralla-btn tralla-kanban-action" data-tralla-add-list>
                    <i class="ti ti-plus" aria-hidden="true"></i> Lista
                </button>
            <?php endif; ?>
            <?php if ($canManageBoard): ?>
                <a class="tralla-btn tralla-kanban-action" href="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $boards_id ?>">
                    <i class="ti ti-pencil" aria-hidden="true"></i> Editar quadro
                </a>
                <a class="tralla-btn tralla-kanban-action tralla-kanban-action-icon" href="<?= tralla_h($pluginUrl) ?>/front/config.form.php" title="Configurações do Tralla" aria-label="Configurações do Tralla">
                    <i class="ti ti-settings" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="tralla-board-scroll" id="tralla-lists" aria-label="Listas do quadro"></div>

    <noscript>
        <div class="tralla-empty">
            <h2>JavaScript necessário</h2>
            <p>Ative o JavaScript para usar o quadro Kanban.</p>
        </div>
    </noscript>
</div>

<script type="application/json" id="tralla-bootstrap"><?= json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div id="tralla-modal-root"></div>
<div id="tralla-toast-root" aria-live="polite" aria-atomic="true"></div>

<?php
Html::footer();
