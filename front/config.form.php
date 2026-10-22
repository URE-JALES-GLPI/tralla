<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $CFG_GLPI, $DB;

Session::checkRight('plugin_tralla', UPDATE);
tralla_check_csrf($_POST);

$pluginUrl = (string) $CFG_GLPI['root_doc'] . '/plugins/tralla';

if (isset($_POST['save'])) {
    $url = trim(tralla_post_string('assignature_url'));
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        Session::addMessageAfterRedirect('A URL do assignat-ure é inválida.', false, ERROR);
        Html::back();
    }

    $emails = [];
    foreach (preg_split('/[\s,;]+/', tralla_post_string('notification_emails')) ?: [] as $email) {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    PluginTrallaConfig::set('assignature_url', $url);
    PluginTrallaConfig::set('approval_group', (string) max(0, tralla_post_int('approval_group')));
    PluginTrallaConfig::set('notification_enabled', !empty($_POST['notification_enabled']) ? '1' : '0');
    PluginTrallaConfig::set('notification_emails', implode(', ', array_unique($emails)));
    PluginTrallaConfig::set('pending_hours', (string) max(1, tralla_post_int('pending_hours', 1)));
    PluginTrallaConfig::set('withdrawal_hours', (string) max(1, tralla_post_int('withdrawal_hours', 24)));
    PluginTrallaConfig::clearCache();

    Session::addMessageAfterRedirect('Configurações salvas.', true);
    Html::back();
}

if (isset($_POST['regenerate_token'])) {
    PluginTrallaConfig::set('assignature_token', bin2hex(random_bytes(24)));
    PluginTrallaConfig::clearCache();
    Session::addMessageAfterRedirect('Novo token gerado. Atualize o assignat-ure com o novo valor.', true);
    Html::back();
}

if (isset($_POST['model_add'])) {
    $model = new PluginTrallaMachineModel();
    $newId = $model->add([
        'name'          => tralla_post_string('model_name'),
        'comment'       => tralla_post_string('model_comment'),
        'entities_id'   => (int) ($_SESSION['glpiactive_entity'] ?? 0),
        'is_recursive'  => !empty($_POST['model_is_recursive']) ? 1 : 0,
    ]);
    Session::addMessageAfterRedirect(
        $newId ? 'Modelo de máquina criado.' : 'Informe o nome do modelo.',
        $newId > 0,
        $newId > 0 ? INFO : ERROR
    );
    Html::back();
}

if (isset($_POST['model_archive']) || isset($_POST['model_restore'])) {
    $modelId = (int) ($_POST['model_id'] ?? 0);
    $model = $modelId > 0 ? PluginTrallaMachineModel::getModel($modelId, true) : null;
    if ($model === null) {
        Session::addMessageAfterRedirect('Modelo de máquina não encontrado.', false, ERROR);
        Html::back();
    }
    if (!Session::haveAccessToEntity((int) $model['entities_id'], (bool) $model['is_recursive'])) {
        Session::addMessageAfterRedirect('Sem acesso a este modelo.', false, ERROR);
        Html::back();
    }

    $item = new PluginTrallaMachineModel();
    $item->update([
        'id'          => $modelId,
        'is_archived' => isset($_POST['model_archive']) ? 1 : 0,
        'date_mod'    => tralla_now(),
    ]);
    Session::addMessageAfterRedirect(
        isset($_POST['model_archive']) ? 'Modelo arquivado.' : 'Modelo restaurado.',
        true
    );
    Html::back();
}

if (isset($_POST['test_email'])) {
    $userId = (int) Session::getLoginUserID();
    $email = '';
    $row = $DB->request([
        'SELECT' => ['email'],
        'FROM'   => 'glpi_useremails',
        'WHERE'  => ['users_id' => $userId, 'is_default' => 1],
        'LIMIT'  => 1,
    ])->current();
    if ($row) {
        $email = (string) $row['email'];
    }
    if ($email === '') {
        $row = $DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $userId],
            'LIMIT'  => 1,
        ])->current();
        $email = $row ? (string) $row['email'] : '';
    }

    if ($email === '') {
        Session::addMessageAfterRedirect('Seu usuário não possui e-mail cadastrado.', false, ERROR);
    } else {
        $ok = tralla_queue_email(
            $email,
            '[URE] Tralla — teste de notificação',
            '<p>Este é um e-mail de teste do plugin <strong>[URE] Tralla</strong>.</p>'
            . '<p>Se você recebeu esta mensagem, a fila de notificações do GLPI está operacional.</p>',
            (int) ($_SESSION['glpiactive_entity'] ?? 0)
        );
        Session::addMessageAfterRedirect(
            $ok ? 'E-mail de teste enfileirado para ' . $email . '.' : 'Não foi possível enfileirar o e-mail de teste.',
            $ok,
            $ok ? INFO : ERROR
        );
    }
    Html::back();
}

$config = PluginTrallaConfig::getAll();
$token = PluginTrallaConfig::assignatureToken();
$notifications = PluginTrallaConfig::notificationEmails();

Html::header('Configurações — [URE] Tralla', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');
?>
<div class="tralla-page tralla-config-page">
    <header class="tralla-page-head">
        <div>
            <h1><i class="ti ti-settings"></i> Configurações</h1>
            <p>Integração com o assignat-ure, aprovações e notificações.</p>
        </div>
        <div class="tralla-head-actions">
            <a class="tralla-btn tralla-btn-ghost" href="<?= tralla_h($pluginUrl) ?>/front/board.php">
                <i class="ti ti-arrow-left"></i> Voltar aos quadros
            </a>
        </div>
    </header>

    <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/config.form.php" class="tralla-config-grid">
        <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>

        <section class="tralla-card tralla-config-card">
            <header>
                <h2><i class="ti ti-link"></i> assignat-ure</h2>
                <p>Endpoint usado pelo aplicativo externo para consultar cards de manutenção.</p>
            </header>
            <div class="tralla-field">
                <label for="tralla-assignature-url">URL do assignat-ure</label>
                <input id="tralla-assignature-url" type="url" name="assignature_url" maxlength="255"
                       placeholder="https://assignat.exemplo.com/api/tralla"
                       value="<?= tralla_h($config['assignature_url']) ?>">
                <small>O Tralla envia <code>?card={id}</code> para esta URL.</small>
            </div>
            <div class="tralla-field">
                <label for="tralla-assignature-token">Token de acesso</label>
                <div class="tralla-input-group">
                    <input id="tralla-assignature-token" type="text" readonly value="<?= tralla_h($token) ?>">
                    <button type="submit" name="regenerate_token" value="1" class="tralla-btn tralla-btn-ghost"
                            onclick="return confirm('Gerar um novo token? O valor antigo deixará de funcionar.');">
                        <i class="ti ti-refresh"></i> Gerar novo
                    </button>
                </div>
                <small>Copie este token para a configuração do assignat-ure. Ele não é exibido em nenhuma outra tela.</small>
            </div>
        </section>

        <section class="tralla-card tralla-config-card">
            <header>
                <h2><i class="ti ti-checkup-list"></i> Aprovações</h2>
                <p>Quem pode aprovar ou reprovar cards no estágio <strong>/2</strong>.</p>
            </header>
            <div class="tralla-field">
                <label for="tralla-approval-group">Grupo aprovador <span class="tralla-muted">(opcional)</span></label>
                <?php Group::dropdown([
                    'name'   => 'approval_group',
                    'value'  => (int) $config['approval_group'],
                    'entity' => (int) ($_SESSION['glpiactive_entity'] ?? 0),
                ]); ?>
                <small>Além do direito <code>plugin_tralla_approve</code>, o usuário precisa pertencer a este grupo quando definido.</small>
            </div>
        </section>

        <section class="tralla-card tralla-config-card">
            <header>
                <h2><i class="ti ti-mail"></i> Notificações</h2>
                <p>Lembretes automáticos de cards pendentes e retiradas aguardando confirmação.</p>
            </header>
            <label class="tralla-check">
                <input type="checkbox" name="notification_enabled" value="1" <?= $config['notification_enabled'] === '1' ? 'checked' : '' ?>>
                Ativar notificações por e-mail
            </label>
            <div class="tralla-field">
                <label for="tralla-notification-emails">Destinatários adicionais</label>
                <textarea id="tralla-notification-emails" name="notification_emails" rows="2"
                          placeholder="ti@empresa.com, gestor@empresa.com"><?= tralla_h(implode(', ', $notifications)) ?></textarea>
                <small>Separe os e-mails por vírgula, ponto e vírgula ou espaço.</small>
            </div>
            <div class="tralla-form-row">
                <div class="tralla-field">
                    <label for="tralla-pending-hours">Avisar após (horas pendente)</label>
                    <input id="tralla-pending-hours" type="number" min="1" max="720" name="pending_hours"
                           value="<?= (int) $config['pending_hours'] ?>">
                </div>
                <div class="tralla-field">
                    <label for="tralla-withdrawal-hours">Lembrar retirada após (horas)</label>
                    <input id="tralla-withdrawal-hours" type="number" min="1" max="720" name="withdrawal_hours"
                           value="<?= (int) $config['withdrawal_hours'] ?>">
                </div>
            </div>
            <button type="submit" name="test_email" value="1" class="tralla-btn tralla-btn-ghost">
                <i class="ti ti-send"></i> Enviar e-mail de teste para mim
            </button>
        </section>

        <footer class="tralla-config-actions">
            <button type="submit" name="save" value="1" class="tralla-btn tralla-btn-primary">
                <i class="ti ti-device-floppy"></i> Salvar configurações
            </button>
        </footer>
    </form>

<?php $models = PluginTrallaMachineModel::getModels(true); ?>
<section class="tralla-card tralla-config-card tralla-models-card">
    <header>
        <h2><i class="ti ti-device-laptop"></i> Modelos de máquina</h2>
        <p>Modelos disponíveis ao criar cards de manutenção. Arquive modelos que não devem mais ser usados.</p>
    </header>

    <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/config.form.php" class="tralla-model-add-form">
        <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
        <div class="tralla-form-row">
            <div class="tralla-field">
                <label for="tralla-model-name">Nome do modelo *</label>
                <input id="tralla-model-name" type="text" name="model_name" maxlength="255" required
                       placeholder="Ex.: Notebook Dell Latitude 5420">
            </div>
            <div class="tralla-field">
                <label for="tralla-model-comment">Observação</label>
                <input id="tralla-model-comment" type="text" name="model_comment" maxlength="255">
            </div>
        </div>
        <label class="tralla-check">
            <input type="checkbox" name="model_is_recursive" value="1">
            Disponível para subentidades
        </label>
        <button type="submit" name="model_add" value="1" class="tralla-btn tralla-btn-primary">
            <i class="ti ti-plus"></i> Adicionar modelo
        </button>
    </form>

    <?php if ($models === []): ?>
        <p class="tralla-empty-mini">Nenhum modelo cadastrado. Cadastre ao menos um para criar cards de manutenção.</p>
    <?php else: ?>
        <div class="tralla-model-list">
            <?php foreach ($models as $model): ?>
                <?php $archived = (int) $model['is_archived'] === 1; ?>
                <div class="tralla-model-row<?= $archived ? ' is-archived' : '' ?>">
                    <div class="tralla-model-info">
                        <strong><?= tralla_h((string) $model['name']) ?></strong>
                        <small>
                            <?= $archived ? 'Arquivado' : 'Ativo' ?>
                            <?php if ((string) $model['comment'] !== ''): ?>
                                · <?= tralla_h((string) $model['comment']) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/config.form.php">
                        <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
                        <input type="hidden" name="model_id" value="<?= (int) $model['id'] ?>">
                        <?php if ($archived): ?>
                            <button type="submit" name="model_restore" value="1" class="tralla-btn tralla-btn-ghost">
                                <i class="ti ti-arrow-back-up"></i> Restaurar
                            </button>
                        <?php else: ?>
                            <button type="submit" name="model_archive" value="1" class="tralla-btn tralla-btn-ghost">
                                <i class="ti ti-archive"></i> Arquivar
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
</div>
<?php
Html::footer();
