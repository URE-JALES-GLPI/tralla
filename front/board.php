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
        <div class="tralla-brand">
            <img class="tralla-brand-logo" src="<?= tralla_h($pluginUrl) ?>/logo.png?v=<?= (int) @filemtime(GLPI_ROOT . '/plugins/tralla/logo.png') ?>" alt="">
            <div>
                <h1>Quadros Tralla</h1>
                <p>Organize projetos e o fluxo de manutenção sem sair do GLPI.</p>
            </div>
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
                            <span class="tralla-pill is-in-progress"><i class="ti ti-progress"></i> <?= (int) $stats['in_progress'] ?> em andamento</span>
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
<?php
$typeChoices = PluginTrallaBoard::getTypeChoices();
$typeMeta = [
    'normal' => [
        'title'       => 'Normal',
        'description' => 'Listas e cards livres para organizar qualquer projeto.',
        'icon'        => 'ti-layout-kanban',
    ],
    'maintenance' => [
        'title'       => 'Manutenção',
        'description' => 'Fluxo com aprovação, retirada e histórico.',
        'icon'        => 'ti-tool',
    ],
];
$backgrounds = tralla_backgrounds();
?>
<div class="tralla-modal-backdrop" id="tralla-board-create" hidden>
    <div class="tralla-modal tralla-board-create-modal" role="dialog" aria-modal="true" aria-labelledby="tralla-board-create-title">
        <header class="tralla-modal-head tralla-board-create-head">
            <div class="tralla-board-create-heading">
                <span class="tralla-board-create-icon" aria-hidden="true"><i class="ti ti-layout-kanban"></i></span>
                <div>
                    <h2 id="tralla-board-create-title">Novo quadro</h2>
                    <p>Crie um espaço para organizar projetos ou acompanhar manutenções.</p>
                </div>
            </div>
            <button type="button" class="tralla-icon-btn" data-tralla-close="tralla-board-create" aria-label="Fechar">
                <i class="ti ti-x"></i>
            </button>
        </header>
        <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php" class="tralla-form tralla-board-create-form">
            <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
            <div class="tralla-modal-body tralla-board-create-body">
                <section class="tralla-board-create-section" aria-labelledby="tralla-board-create-info-title">
                    <div class="tralla-board-create-section-head">
                        <span class="tralla-board-create-section-icon" aria-hidden="true"><i class="ti ti-forms"></i></span>
                        <div>
                            <h3 id="tralla-board-create-info-title">Informações do quadro</h3>
                            <p>Defina o nome e o comportamento das listas.</p>
                        </div>
                    </div>
                    <div class="tralla-field">
                        <label for="tralla-board-name">Nome do quadro</label>
                        <input id="tralla-board-name" type="text" name="name" maxlength="255" placeholder="Ex.: Manutenção da linha 3" required autofocus>
                    </div>
                    <fieldset class="tralla-board-create-type" aria-describedby="tralla-board-type-hint">
                        <legend>Tipo de quadro</legend>
                        <div class="tralla-board-create-types">
                            <?php foreach ($typeChoices as $value => $label): ?>
                                <?php $meta = $typeMeta[$value] ?? ['title' => $label, 'description' => '', 'icon' => 'ti-layout-kanban']; ?>
                                <label class="tralla-board-create-type-card">
                                    <input type="radio" name="type" value="<?= tralla_h($value) ?>" <?= $value === 'normal' ? 'checked' : '' ?>>
                                    <span class="tralla-board-create-type-icon" aria-hidden="true"><i class="ti <?= tralla_h($meta['icon']) ?>"></i></span>
                                    <span class="tralla-board-create-type-copy">
                                        <strong><?= tralla_h($meta['title']) ?></strong>
                                        <small><?= tralla_h($meta['description']) ?></small>
                                    </span>
                                    <span class="tralla-board-create-check" aria-hidden="true"><i class="ti ti-check"></i></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small id="tralla-board-type-hint">O tipo é definido na criação e não pode ser alterado depois.</small>
                    </fieldset>
                </section>

                <section class="tralla-board-create-section" aria-labelledby="tralla-board-create-appearance-title">
                    <div class="tralla-board-create-section-head">
                        <span class="tralla-board-create-section-icon" aria-hidden="true"><i class="ti ti-palette"></i></span>
                        <div>
                            <h3 id="tralla-board-create-appearance-title">Aparência</h3>
                            <p>Escolha o tema visual e a cor de destaque do quadro.</p>
                        </div>
                    </div>
                    <fieldset class="tralla-board-create-theme">
                        <legend>Tema</legend>
                        <div class="tralla-board-create-themes">
                            <?php foreach ($backgrounds as $value => $label): ?>
                                <label class="tralla-board-create-theme-card">
                                    <input type="radio" name="background" value="<?= tralla_h($value) ?>" <?= $value === 'ocean' ? 'checked' : '' ?>>
                                    <span class="tralla-board-create-theme-swatch tr-bg-<?= tralla_h($value) ?>" aria-hidden="true"></span>
                                    <span class="tralla-board-create-theme-label"><?= tralla_h($label) ?></span>
                                    <span class="tralla-board-create-check" aria-hidden="true"><i class="ti ti-check"></i></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="tralla-field">
                        <label for="tralla-board-color">Cor de destaque</label>
                        <div class="tralla-board-create-color">
                            <input id="tralla-board-color" type="color" name="color" value="#0079bf">
                            <output id="tralla-board-color-value" for="tralla-board-color">#0079bf</output>
                        </div>
                    </div>
                </section>

                <section class="tralla-board-create-section" aria-labelledby="tralla-board-create-organization-title">
                    <div class="tralla-board-create-section-head">
                        <span class="tralla-board-create-section-icon" aria-hidden="true"><i class="ti ti-building"></i></span>
                        <div>
                            <h3 id="tralla-board-create-organization-title">Organização</h3>
                            <p>Vincule o quadro à entidade correta e adicione contexto.</p>
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
                        <textarea id="tralla-board-comment" name="comment" rows="3" maxlength="2000" placeholder="Ex.: Acompanhamento das manutenções preventivas da linha 3."></textarea>
                    </div>
                </section>
            </div>
            <footer class="tralla-modal-actions tralla-board-create-actions">
                <p class="tralla-board-create-hint">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    Você poderá adicionar listas e cards depois.
                </p>
                <div class="tralla-board-create-action-buttons">
                    <button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close="tralla-board-create">Cancelar</button>
                    <button type="submit" name="add" value="1" class="tralla-btn tralla-btn-primary">
                        <i class="ti ti-check"></i> Criar quadro
                    </button>
                </div>
            </footer>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
Html::footer();
