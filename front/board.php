<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $CFG_GLPI;

Session::checkRight('plugin_tralla', READ);

$canCreate = Session::haveRight('plugin_tralla', CREATE);
$canUpdate = Session::haveRight('plugin_tralla', UPDATE);
$showArchived = isset($_GET['archived']) && (int) $_GET['archived'] === 1;
$boards = PluginTrallaBoard::getVisibleBoards($showArchived);

Html::header('[URE] Tralla — Quadros', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');

$root = (string) $CFG_GLPI['root_doc'];
$pluginUrl = $root . '/plugins/tralla';
?>
<div class="tralla-page tralla-boards-page">
    <header class="tralla-page-head">
        <div>
            <h1><i class="ti ti-layout-kanban"></i> Quadros Tralla</h1>
            <p>Organize projetos e o fluxo de manutenção sem sair do GLPI.</p>
        </div>
        <div class="tralla-head-actions">
            <a class="tralla-btn tralla-btn-ghost" href="?archived=<?= $showArchived ? '0' : '1' ?>">
                <i class="ti ti-archive"></i>
                <?= $showArchived ? 'Ver ativos' : 'Ver arquivados' ?>
            </a>
            <?php if ($canUpdate): ?>
                <a class="tralla-btn tralla-btn-ghost" href="<?= tralla_h($pluginUrl) ?>/front/config.form.php">
                    <i class="ti ti-settings"></i> Configurações
                </a>
            <?php endif; ?>
            <?php if ($canCreate): ?>
                <button type="button" class="tralla-btn tralla-btn-primary" data-tralla-modal="tralla-board-create">
                    <i class="ti ti-plus"></i> Novo quadro
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($boards === []): ?>
        <div class="tralla-empty">
            <i class="ti ti-layout-kanban"></i>
            <h2>Nenhum quadro encontrado</h2>
            <p>Crie um quadro normal para projetos livres ou um quadro de manutenção para o fluxo controlado.</p>
            <?php if ($canCreate): ?>
                <button type="button" class="tralla-btn tralla-btn-primary" data-tralla-modal="tralla-board-create">
                    <i class="ti ti-plus"></i> Criar o primeiro quadro
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tralla-board-grid">
            <?php foreach ($boards as $board): ?>
                <?php
                $boardId = (int) $board['id'];
                $stats = PluginTrallaBoard::getStats($boardId);
                $type = (string) $board['type'];
                $isMaintenance = $type === 'maintenance';
                $background = tralla_valid_background((string) $board['background']);
                ?>
                <a class="tralla-board-tile tr-bg-<?= tralla_h($background) ?>"
                   href="<?= tralla_h($pluginUrl) ?>/front/kanban.php?boards_id=<?= $boardId ?>">
                    <span class="tralla-board-type <?= $isMaintenance ? 'is-maintenance' : '' ?>">
                        <i class="ti <?= $isMaintenance ? 'ti-tool' : 'ti-layout-kanban' ?>"></i>
                        <?= $isMaintenance ? 'Manutenção' : 'Normal' ?>
                    </span>
                    <h2><?= tralla_h($board['name']) ?></h2>
                    <?php if (trim((string) $board['comment']) !== ''): ?>
                        <p class="tralla-board-comment"><?= tralla_h($board['comment']) ?></p>
                    <?php endif; ?>
                    <div class="tralla-board-metrics">
                        <span><i class="ti ti-list"></i> <?= (int) $stats['lists'] ?> listas</span>
                        <span><i class="ti ti-cards"></i> <?= (int) $stats['cards'] ?> cards</span>
                        <?php if ($isMaintenance): ?>
                            <span class="tralla-pill pending"><i class="ti ti-clock"></i> <?= (int) $stats['pending'] ?> pendentes</span>
                            <span class="tralla-pill progress"><i class="ti ti-progress"></i> <?= (int) $stats['in_progress'] ?> em andamento</span>
                            <span class="tralla-pill withdrawal"><i class="ti ti-package"></i> <?= (int) $stats['withdrawal'] ?> retiradas</span>
                        <?php endif; ?>
                    </div>
                    <footer>
                        <span><?= tralla_h(tralla_user_name((int) $board['users_id'])) ?></span>
                        <span><?= tralla_h(tralla_date($board['date_mod'])) ?></span>
                    </footer>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canCreate): ?>
<div class="tralla-modal-backdrop" id="tralla-board-create" hidden>
    <div class="tralla-modal" role="dialog" aria-modal="true" aria-labelledby="tralla-board-create-title">
        <header class="tralla-modal-head">
            <h2 id="tralla-board-create-title"><i class="ti ti-plus"></i> Novo quadro</h2>
            <button type="button" class="tralla-icon-btn" data-tralla-close="tralla-board-create" aria-label="Fechar">
                <i class="ti ti-x"></i>
            </button>
        </header>
        <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php" class="tralla-form">
            <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
            <div class="tralla-field">
                <label for="tralla-board-name">Nome do quadro</label>
                <input id="tralla-board-name" type="text" name="name" maxlength="255" required autofocus>
            </div>
            <div class="tralla-field">
                <label for="tralla-board-type">Tipo</label>
                <select id="tralla-board-type" name="type">
                    <?php foreach (PluginTrallaBoard::getTypeChoices() as $value => $label): ?>
                        <option value="<?= tralla_h($value) ?>"><?= tralla_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>O tipo é definido na criação e não pode ser alterado depois.</small>
            </div>
            <div class="tralla-form-row">
                <div class="tralla-field">
                    <label for="tralla-board-background">Tema</label>
                    <select id="tralla-board-background" name="background">
                        <?php foreach (tralla_backgrounds() as $value => $label): ?>
                            <option value="<?= tralla_h($value) ?>"><?= tralla_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tralla-field">
                    <label for="tralla-board-color">Cor de destaque</label>
                    <input id="tralla-board-color" type="color" name="color" value="#0079bf">
                </div>
            </div>
            <div class="tralla-field">
                <label for="tralla-board-entity">Entidade</label>
                <?php Entity::dropdown([
                    'name'  => 'entities_id',
                    'value' => (int) ($_SESSION['glpiactive_entity'] ?? 0),
                ]); ?>
            </div>
            <div class="tralla-field">
                <label for="tralla-board-comment">Descrição <span class="tralla-muted">(opcional)</span></label>
                <textarea id="tralla-board-comment" name="comment" rows="3" maxlength="2000"></textarea>
            </div>
            <footer class="tralla-form-actions">
                <button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close="tralla-board-create">Cancelar</button>
                <button type="submit" name="add" value="1" class="tralla-btn tralla-btn-primary">
                    <i class="ti ti-check"></i> Criar quadro
                </button>
            </footer>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
Html::footer();
