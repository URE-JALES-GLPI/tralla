<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $CFG_GLPI;

$root = (string) $CFG_GLPI['root_doc'];
$pluginUrl = $root . '/plugins/tralla';
$board = new PluginTrallaBoard();

if (isset($_POST['add'])) {
    Session::checkRight('plugin_tralla', CREATE);
    tralla_check_csrf($_POST);

    $input = [
        'name'         => tralla_post_string('name'),
        'type'         => tralla_post_string('type', 'normal'),
        'color'        => tralla_post_string('color', '#0079bf'),
        'background'   => tralla_post_string('background', 'ocean'),
        'comment'      => tralla_post_string('comment'),
        'entities_id'  => tralla_post_int('entities_id', (int) ($_SESSION['glpiactive_entity'] ?? 0)),
        'is_recursive' => !empty($_POST['is_recursive']) ? 1 : 0,
    ];

    $board->check(-1, CREATE, $input);
    $newId = $board->add($input);
    if (!$newId) {
        Html::back();
    }

    Html::redirect($pluginUrl . '/front/kanban.php?boards_id=' . (int) $newId);
}

if (isset($_POST['update'])) {
    Session::checkRight('plugin_tralla', UPDATE);
    tralla_check_csrf($_POST);

    $id = tralla_post_int('id');
    if ($id <= 0 || !PluginTrallaBoard::canViewBoard($id)) {
        Session::addMessageAfterRedirect('Quadro inválido.', false, ERROR);
        Html::back();
    }

    $input = [
        'id'          => $id,
        'name'        => tralla_post_string('name'),
        'color'       => tralla_post_string('color', '#0079bf'),
        'background'  => tralla_post_string('background', 'ocean'),
        'comment'     => tralla_post_string('comment'),
        'is_archived' => !empty($_POST['is_archived']) ? 1 : 0,
    ];

    $board->check($id, UPDATE);
    $board->update($input);
    Html::back();
}

if (isset($_POST['delete'])) {
    Session::checkRight('plugin_tralla', DELETE);
    tralla_check_csrf($_POST);

    $id = tralla_post_int('id');
    if ($id > 0 && PluginTrallaBoard::canViewBoard($id)) {
        $board->check($id, DELETE);
        $board->delete(['id' => $id], 1);
    }

    Html::redirect($pluginUrl . '/front/board.php');
}

$id = tralla_get_int('id');
if ($id <= 0) {
    Html::redirect($pluginUrl . '/front/board.php');
}

if (!$board->getFromDB($id) || !PluginTrallaBoard::canViewBoard($id)) {
    Session::addMessageAfterRedirect('Quadro não encontrado.', false, ERROR);
    Html::redirect($pluginUrl . '/front/board.php');
}

$canUpdate = Session::haveRight('plugin_tralla', UPDATE);
$canDelete = Session::haveRight('plugin_tralla', DELETE);

Html::header('Editar quadro — [URE] Tralla', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');
?>
<div class="tralla-page tralla-form-page">
    <header class="tralla-page-head">
        <div>
            <h1><i class="ti ti-edit"></i> Editar quadro</h1>
            <p><?= tralla_h($board->fields['name']) ?></p>
        </div>
        <div class="tralla-head-actions">
            <a class="tralla-btn tralla-btn-ghost" href="<?= tralla_h($pluginUrl) ?>/front/kanban.php?boards_id=<?= (int) $id ?>">
                <i class="ti ti-arrow-left"></i> Voltar ao quadro
            </a>
        </div>
    </header>

    <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php" class="tralla-card tralla-form">
        <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
        <?= Html::hidden('id', ['value' => (int) $id]) ?>
        <div class="tralla-field">
            <label for="tralla-edit-name">Nome do quadro</label>
            <input id="tralla-edit-name" type="text" name="name" maxlength="255" required
                   value="<?= tralla_h($board->fields['name']) ?>" <?= $canUpdate ? '' : 'disabled' ?>>
        </div>
        <div class="tralla-form-row">
            <div class="tralla-field">
                <label for="tralla-edit-background">Tema</label>
                <select id="tralla-edit-background" name="background" <?= $canUpdate ? '' : 'disabled' ?>>
                    <?php foreach (tralla_backgrounds() as $value => $label): ?>
                        <option value="<?= tralla_h($value) ?>" <?= $board->fields['background'] === $value ? 'selected' : '' ?>>
                            <?= tralla_h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tralla-field">
                <label for="tralla-edit-color">Cor de destaque</label>
                <input id="tralla-edit-color" type="color" name="color"
                       value="<?= tralla_h($board->fields['color']) ?>" <?= $canUpdate ? '' : 'disabled' ?>>
            </div>
        </div>
        <div class="tralla-field">
            <label for="tralla-edit-comment">Descrição</label>
            <textarea id="tralla-edit-comment" name="comment" rows="4" maxlength="2000" <?= $canUpdate ? '' : 'disabled' ?>><?= tralla_h($board->fields['comment']) ?></textarea>
        </div>
        <?php if ($canUpdate): ?>
            <label class="tralla-check">
                <input type="checkbox" name="is_archived" value="1" <?= !empty($board->fields['is_archived']) ? 'checked' : '' ?>>
                Arquivar quadro
            </label>
            <footer class="tralla-form-actions">
                <?php if ($canDelete): ?>
                    <button type="submit" name="delete" value="1" class="tralla-btn tralla-btn-danger"
                            onclick="return confirm('Excluir este quadro e todos os cards? Esta ação não pode ser desfeita.');">
                        <i class="ti ti-trash"></i> Excluir quadro
                    </button>
                <?php endif; ?>
                <button type="submit" name="update" value="1" class="tralla-btn tralla-btn-primary">
                    <i class="ti ti-device-floppy"></i> Salvar alterações
                </button>
            </footer>
        <?php endif; ?>
    </form>
</div>
<?php
Html::footer();
