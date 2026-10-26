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

$id = tralla_get_int('id');
if ($id <= 0) {
    Html::redirect($pluginUrl . '/front/board.php');
}

if (!$board->getFromDB($id) || !PluginTrallaBoard::canViewBoard($id)) {
    Session::addMessageAfterRedirect('Quadro não encontrado.', false, ERROR);
    Html::redirect($pluginUrl . '/front/board.php');
}

if (!PluginTrallaBoard::canAdminBoard($id)) {
    Session::addMessageAfterRedirect('Sem permissão para configurar este quadro.', false, ERROR);
    Html::redirect($pluginUrl . '/front/kanban.php?boards_id=' . $id);
}

$canGovern = PluginTrallaBoard::canGovernBoard($id);
$canUpdate = PluginTrallaBoard::canAdminBoard($id) && Session::haveRight('plugin_tralla', UPDATE);
$canArchive = PluginTrallaBoard::canGovernBoard($id) && Session::haveRight('plugin_tralla', UPDATE);
$canDelete = PluginTrallaBoard::canGovernBoard($id) && Session::haveRight('plugin_tralla', DELETE);

if (isset($_POST['update'])) {
    Session::checkRight('plugin_tralla', UPDATE);
    tralla_check_csrf($_POST);

    if (!$canUpdate) {
        Session::addMessageAfterRedirect('Sem permissão para editar este quadro.', false, ERROR);
        Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=general');
    }

    $input = [
        'id'          => $id,
        'name'        => tralla_post_string('name'),
        'background'  => tralla_post_string('background', 'ocean'),
        'comment'     => tralla_post_string('comment'),
    ];

    if ($canArchive) {
        $input['is_archived'] = !empty($_POST['is_archived']) ? 1 : 0;
    } else {
        unset($input['is_archived']);
    }

    $board->check($id, UPDATE);

    $board->update($input);
    Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=general');
}

if (isset($_POST['delete'])) {
    Session::checkRight('plugin_tralla', DELETE);
    tralla_check_csrf($_POST);

    if (!$canDelete) {
        Session::addMessageAfterRedirect('Sem permissão para excluir este quadro.', false, ERROR);
        Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=general');
    }

    $board->check($id, DELETE);

    $board->delete(['id' => $id], 1);
    Html::redirect($pluginUrl . '/front/board.php');
}

if (isset($_POST['member_add']) || isset($_POST['member_update']) || isset($_POST['member_remove'])) {
    Session::checkRight('plugin_tralla', UPDATE);
    tralla_check_csrf($_POST);

    if (!$canGovern) {
        Session::addMessageAfterRedirect('Sem permissão para gerenciar membros.', false, ERROR);
        Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=members');
    }

    $memberId = tralla_post_int('users_id');
    $role = tralla_normalize_board_role(tralla_post_string('role'));

    try {
        if (isset($_POST['member_remove'])) {
            PluginTrallaBoardUser::remove($id, $memberId);
            Session::addMessageAfterRedirect('Membro removido.', true);
        } else {
            PluginTrallaBoardUser::setRole($id, $memberId, $role);
            Session::addMessageAfterRedirect(
                isset($_POST['member_add']) ? 'Membro adicionado.' : 'Papel atualizado.',
                true
            );
        }
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }

    Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=members');
}

if (isset($_POST['approvers_save'])) {
    Session::checkRight('plugin_tralla', UPDATE);
    tralla_check_csrf($_POST);

    if (!$canGovern) {
        Session::addMessageAfterRedirect('Sem permissão para definir aprovadores.', false, ERROR);
        Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=members');
    }

    $approverIds = $_POST['approver_ids'] ?? [];
    if (!is_array($approverIds)) {
        $approverIds = [];
    }

    try {
        PluginTrallaBoardUser::setApprovers($id, $approverIds);
        Session::addMessageAfterRedirect('Aprovadores atualizados.', true);
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }

    Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=members');
}

$tab = (($_GET['tab'] ?? 'general') === 'members') ? 'members' : 'general';

Html::header('Tralla', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');
echo "<script>document.title = 'Tralla';</script>";
?>
<div class="tralla-page tralla-form-page">
    <header class="tralla-page-head">
        <div class="tralla-brand">
            <img class="tralla-brand-logo" src="<?= tralla_h($pluginUrl) ?>/logo.png?v=<?= (int) @filemtime(GLPI_ROOT . '/plugins/tralla/public/logo.png') ?>" alt="">
            <div>
                <h1>Editar quadro</h1>
                <p><?= tralla_h($board->fields['name']) ?></p>
            </div>
        </div>
        <div class="tralla-head-actions">
            <a class="tralla-btn tralla-btn-ghost" href="<?= tralla_h($pluginUrl) ?>/front/kanban.php?boards_id=<?= (int) $id ?>">
                <i class="ti ti-arrow-left"></i> Voltar ao quadro
            </a>
        </div>
    </header>

    <nav class="tralla-tabs" aria-label="Seções do quadro">
        <a class="tralla-tab<?= $tab === 'general' ? ' is-active' : '' ?>"
           href="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $id ?>&amp;tab=general"
           <?= $tab === 'general' ? 'aria-current="page"' : '' ?>>Geral</a>
        <a class="tralla-tab<?= $tab === 'members' ? ' is-active' : '' ?>"
           href="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $id ?>&amp;tab=members"
           <?= $tab === 'members' ? 'aria-current="page"' : '' ?>>Membros</a>
    </nav>

    <?php if ($tab === 'general'): ?>
        <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $id ?>&amp;tab=general" class="tralla-card tralla-form">
            <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
            <?= Html::hidden('id', ['value' => (int) $id]) ?>
            <div class="tralla-field">
                <label for="tralla-edit-name">Nome do quadro</label>
                <input id="tralla-edit-name" type="text" name="name" maxlength="255" required
                       value="<?= tralla_h($board->fields['name']) ?>" <?= $canUpdate ? '' : 'disabled' ?>>
            </div>
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
                <label for="tralla-edit-comment">Descrição</label>
                <textarea id="tralla-edit-comment" name="comment" rows="4" maxlength="2000" <?= $canUpdate ? '' : 'disabled' ?>><?= tralla_h($board->fields['comment']) ?></textarea>
            </div>
            <?php if ($canArchive): ?>
                <label class="tralla-check">
                    <input type="checkbox" name="is_archived" value="1" <?= !empty($board->fields['is_archived']) ? 'checked' : '' ?>>
                    Arquivar quadro
                </label>
            <?php endif; ?>
            <?php if ($canUpdate || $canDelete): ?>
                <footer class="tralla-form-actions">
                    <?php if ($canDelete): ?>
                        <button type="submit" name="delete" value="1" class="tralla-btn tralla-btn-danger"
                                data-tralla-confirm="Excluir este quadro e todos os cards? Esta ação não pode ser desfeita."
                                data-tralla-confirm-title="Excluir quadro"
                                data-tralla-confirm-label="Excluir quadro"
                                data-tralla-confirm-icon="ti-trash"
                                data-tralla-confirm-tone="danger">
                            <i class="ti ti-trash"></i> Excluir quadro
                        </button>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <button type="submit" name="update" value="1" class="tralla-btn tralla-btn-primary">
                            <i class="ti ti-device-floppy"></i> Salvar alterações
                        </button>
                    <?php endif; ?>
                </footer>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <?php
        $members = PluginTrallaBoardUser::getMembers($id);
        $hasSingleSuperadmin = PluginTrallaBoardUser::countSuperadmins($id) <= 1;
        $lastSuperadminTitle = 'O último SuperAdmin do quadro não pode ser rebaixado ou removido.';
        ?>
        <section class="tralla-card tralla-members">
            <header class="tralla-members-head">
                <div>
                    <h2><i class="ti ti-users"></i> Membros do quadro</h2>
                    <p>Admin e SuperAdmin configuram o quadro. Apenas SuperAdmin gerencia membros.</p>
                </div>
            </header>

            <?php if ($canGovern): ?>
                <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $id ?>&amp;tab=members" class="tralla-member-add">
                    <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
                    <?= Html::hidden('id', ['value' => (int) $id]) ?>
                    <div class="tralla-field">
                        <label for="tralla-member-add-user">Usuário</label>
                        <?php User::dropdown(['name' => 'users_id', 'right' => 'all', 'display_emptychoice' => true,
                            'used' => array_column($members, 'users_id')]); ?>
                    </div>
                    <div class="tralla-field">
                        <label for="tralla-member-add-role">Papel</label>
                        <select id="tralla-member-add-role" name="role">
                            <?php foreach (tralla_board_role_labels() as $roleValue => $roleLabel): ?>
                                <option value="<?= tralla_h($roleValue) ?>" <?= $roleValue === 'member' ? 'selected' : '' ?>>
                                    <?= tralla_h($roleLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="member_add" value="1" class="tralla-btn tralla-btn-primary">
                        <i class="ti ti-plus"></i> Adicionar
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($members === []): ?>
                <p class="tralla-empty-mini">Nenhum membro cadastrado neste quadro.</p>
            <?php else: ?>
                <div class="tralla-members-list">
                    <div class="tralla-members-columns" aria-hidden="true">
                        <span>Usuário</span>
                        <span>Papel</span>
                        <span>Incluído em</span>
                        <span>Ações</span>
                    </div>
                    <?php foreach ($members as $member): ?>
                        <?php
                        $memberUsersId = (int) $member['users_id'];
                        $memberRole = (string) $member['role'];
                        $lastSuperadmin = $hasSingleSuperadmin && $memberRole === 'superadmin';
                        $lastSuperadminAttr = $lastSuperadmin
                            ? ' disabled title="' . tralla_h($lastSuperadminTitle) . '"'
                            : '';
                        $glpiName = trim((string) $member['realname'] . ' ' . (string) $member['firstname']);
                        if ($glpiName === '') {
                            $glpiName = trim((string) $member['name']);
                        }
                        $isRemoved = (int) $member['is_deleted'] === 1 || $glpiName === '';
                        $displayName = $isRemoved ? 'Usuário removido' : $glpiName;
                        $login = trim((string) $member['name']);
                        $includedAt = trim((string) $member['date_creation']);
                        ?>
                        <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $id ?>&amp;tab=members" class="tralla-member-row">
                            <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
                            <?= Html::hidden('id', ['value' => (int) $id]) ?>
                            <?= Html::hidden('users_id', ['value' => $memberUsersId]) ?>
                            <div class="tralla-member-info">
                                <span class="tralla-member-name"><?= tralla_h($displayName) ?><?php if ((int) ($member['can_approve'] ?? 0) === 1): ?><span class="tralla-approver-badge" title="Aprovador deste quadro">Aprovador</span><?php endif; ?></span>
                                <?php if ($login !== ''): ?>
                                    <span class="tralla-member-login"><?= tralla_h($login) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="tralla-member-role">
                                <?php if ($canGovern): ?>
                                    <select name="role" aria-label="Papel de <?= tralla_h($displayName) ?>"<?= $lastSuperadminAttr ?>>
                                        <?php foreach (tralla_board_role_labels() as $roleValue => $roleLabel): ?>
                                            <option value="<?= tralla_h($roleValue) ?>" <?= $memberRole === $roleValue ? 'selected' : '' ?>>
                                                <?= tralla_h($roleLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <span class="tralla-role-badge"><?= tralla_h(tralla_board_role_label($memberRole)) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="tralla-member-date">
                                <?= $includedAt !== '' ? tralla_h(tralla_date($includedAt)) : '—' ?>
                            </div>
                            <div class="tralla-member-actions">
                                <?php if ($canGovern): ?>
                                    <button type="submit" name="member_update" value="1" class="tralla-btn tralla-btn-ghost"<?= $lastSuperadminAttr ?>>
                                        <i class="ti ti-device-floppy"></i> Salvar
                                    </button>
                                    <button type="submit" name="member_remove" value="1" class="tralla-btn tralla-btn-danger"
                                            data-tralla-confirm="Remover este membro do quadro?"
                                            data-tralla-confirm-title="Remover membro"
                                            data-tralla-confirm-label="Remover"
                                            data-tralla-confirm-icon="ti-user-minus"
                                            data-tralla-confirm-tone="danger"<?= $lastSuperadminAttr ?>>
                                        <i class="ti ti-user-minus"></i> Remover
                                    </button>
                                <?php else: ?>
                                    <span class="tralla-muted">—</span>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php
        $approverIds = [];
        foreach ($members as $member) {
            if ((int) ($member['can_approve'] ?? 0) === 1) {
                $approverIds[(int) $member['users_id']] = true;
            }
        }
        ?>
        <section class="tralla-card tralla-approvers">
            <header class="tralla-members-head">
                <div>
                    <h2><i class="ti ti-shield-check"></i> Aprovadores do quadro</h2>
                    <p>Somente os aprovadores selecionados veem a lista "Manutenção Aprovação" e podem aprovar ou recusar manutenções e exclusões deste quadro.</p>
                </div>
            </header>

            <?php if ($members === []): ?>
                <p class="tralla-empty-mini">Adicione membros ao quadro antes de definir aprovadores.</p>
            <?php else: ?>
                <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/board.form.php?id=<?= (int) $id ?>&amp;tab=members" class="tralla-approvers-form">
                    <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
                    <?= Html::hidden('id', ['value' => (int) $id]) ?>
                    <div class="tralla-approver-grid">
                        <?php foreach ($members as $member): ?>
                            <?php
                            $approverUsersId = (int) $member['users_id'];
                            $approverName = trim((string) $member['realname'] . ' ' . (string) $member['firstname']);
                            if ($approverName === '') {
                                $approverName = trim((string) $member['name']);
                            }
                            if ((int) $member['is_deleted'] === 1 || $approverName === '') {
                                $approverName = 'Usuário removido';
                            }
                            $approverLogin = trim((string) $member['name']);
                            ?>
                            <label class="tralla-approver-option">
                                <input type="checkbox" name="approver_ids[]" value="<?= $approverUsersId ?>"
                                       <?= isset($approverIds[$approverUsersId]) ? 'checked' : '' ?>
                                       <?= $canGovern ? '' : 'disabled' ?>>
                                <span class="tralla-approver-name"><?= tralla_h($approverName) ?></span>
                                <?php if ($approverLogin !== ''): ?>
                                    <span class="tralla-member-login"><?= tralla_h($approverLogin) ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canGovern): ?>
                        <footer class="tralla-form-actions">
                            <button type="submit" name="approvers_save" value="1" class="tralla-btn tralla-btn-primary">
                                <i class="ti ti-device-floppy"></i> Salvar aprovadores
                            </button>
                        </footer>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php
Html::footer();
