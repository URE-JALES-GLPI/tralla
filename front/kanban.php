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

$lists = PluginTrallaList::getListsForBoard($boards_id);
$cards = PluginTrallaCard::getCardsForBoard($boards_id);
$models = PluginTrallaMachineModel::getModels();

$bootstrap = [
    'board' => [
        'id'             => (int) $boards_id,
        'name'           => (string) $board->fields['name'],
        'type'           => (string) $board->fields['type'],
        'type_label'     => $isMaintenance ? 'Manutenção' : 'Normal',
        'background'     => (string) $board->fields['background'],
        'color'          => (string) $board->fields['color'],
        'comment'        => (string) $board->fields['comment'],
        'is_maintenance' => $isMaintenance,
        'entities_id'    => (int) $board->fields['entities_id'],
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
    'models' => array_map(static function (array $model): array {
        return [
            'id'   => (int) $model['id'],
            'name' => (string) $model['name'],
        ];
    }, $models),
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
        'approve' => PluginTrallaCard::userIsApprover(),
    ],
    'user' => [
        'id'   => (int) Session::getLoginUserID(),
        'name' => tralla_user_name((int) Session::getLoginUserID()),
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
$canCreate = Session::haveRight('plugin_tralla', CREATE);
$canUpdate = Session::haveRight('plugin_tralla', UPDATE);

Html::header($board->fields['name'] . ' — [URE] Tralla', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');
?>
<div class="tralla-page tralla-kanban-page tr-bg-<?= tralla_h($background) ?>" id="tralla-kanban">
    <header class="tralla-kanban-head">
        <div class="tralla-kanban-title">
            <a class="tralla-icon-btn" href="<?= tralla_h($pluginUrl) ?>/front/board.php" title="Voltar aos quadros">
                <i class="ti ti-arrow-left"></i>
            </a>
            <div>
                <span class="tralla-board-type <?= $isMaintenance ? 'is-maintenance' : '' ?>">
                    <i class="ti <?= $isMaintenance ? 'ti-tool' : 'ti-layout-kanban' ?>"></i>
                    <?= $isMaintenance ? 'Manutenção' : 'Normal' ?>
                </span>
                <h1><?= tralla_h($board->fields['name']) ?></h1>
                <?php if (trim((string) $board->fields['comment']) !== ''): ?>
                    <p><?= tralla_h($board->fields['comment']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="tralla-head-actions">
            <?php if ($canCreate): ?>
                <button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-add-list>
                    <i class="ti ti-plus"></i> Lista
                </button>
            <?php endif; ?>
            <?php if ($canUpdate): ?>
                <a class="tralla-btn tralla-btn-ghost" href="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $boards_id ?>">
                    <i class="ti ti-pencil"></i> Editar quadro
                </a>
                <a class="tralla-btn tralla-btn-ghost" href="<?= tralla_h($pluginUrl) ?>/front/config.form.php" title="Configurações do Tralla">
                    <i class="ti ti-settings"></i>
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
