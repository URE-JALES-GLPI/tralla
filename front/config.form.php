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
    PluginTrallaConfig::set('notification_enabled', !empty($_POST['notification_enabled']) ? '1' : '0');
    PluginTrallaConfig::set('notification_emails', implode(', ', array_unique($emails)));
    PluginTrallaConfig::set('pending_hours', (string) max(1, tralla_post_int('pending_hours', 1)));
    PluginTrallaConfig::set('withdrawal_hours', (string) max(1, tralla_post_int('withdrawal_hours', 24)));
    PluginTrallaConfig::set('ticket_sync_enabled', !empty($_POST['ticket_sync_enabled']) ? '1' : '0');
    $sheetBoardId = max(0, tralla_post_int('sheet_board_id'));
    if ($sheetBoardId > 0) {
        $sheetBoard = new PluginTrallaBoard();
        if (!$sheetBoard->getFromDB($sheetBoardId) || (string) $sheetBoard->fields['type'] !== 'maintenance') {
            Session::addMessageAfterRedirect('Selecione um quadro de manutenção válido para a planilha.', false, ERROR);
            Html::back();
        }
    }
    PluginTrallaConfig::set('sheet_sync_enabled', !empty($_POST['sheet_sync_enabled']) ? '1' : '0');
    PluginTrallaConfig::set('sheet_board_id', (string) $sheetBoardId);
    PluginTrallaConfig::set('sheet_tab', trim(tralla_post_string('sheet_tab', 'Página1')));
    PluginTrallaConfig::set('sheet_credentials_path', trim(tralla_post_string('sheet_credentials_path')));
    foreach (array_keys(tralla_priorities()) as $priority) {
        foreach (['tto' => SLM::TTO, 'ttr' => SLM::TTR] as $kind => $type) {
            $slaId = max(0, (int) ($_POST['ticket_sla_' . $kind . '_' . $priority] ?? 0));
            $sla = new SLA();
            if ($slaId > 0 && (!$sla->getFromDB($slaId) || (int) $sla->fields['type'] !== $type)) {
                Session::addMessageAfterRedirect('O SLA selecionado para ' . $priority . ' é inválido.', false, ERROR);
                Html::back();
            }
            PluginTrallaConfig::set('ticket_sla_' . $kind . '_' . $priority, (string) $slaId);
        }
    }
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

if (isset($_POST['ticket_sync_existing'])) {
    require_once GLPI_ROOT . '/plugins/tralla/inc/ticket.class.php';
    PluginTrallaConfig::set('ticket_sync_enabled', '1');
    PluginTrallaConfig::set('ticket_sync_start_history_id', '0');
    foreach (array_keys(tralla_priorities()) as $priority) {
        foreach (['tto' => SLM::TTO, 'ttr' => SLM::TTR] as $kind => $type) {
            $slaId = max(0, (int) ($_POST['ticket_sla_' . $kind . '_' . $priority] ?? 0));
            $sla = new SLA();
            if ($slaId > 0 && (!$sla->getFromDB($slaId) || (int) $sla->fields['type'] !== $type)) {
                Session::addMessageAfterRedirect('O SLA selecionado para ' . $priority . ' é inválido.', false, ERROR);
                Html::back();
            }
            PluginTrallaConfig::set('ticket_sla_' . $kind . '_' . $priority, (string) $slaId);
        }
    }
    PluginTrallaConfig::clearCache();
    $synced = PluginTrallaTicketSync::syncPending(200);
    $reconciled = PluginTrallaTicketSync::reconcileLinked();
    Session::addMessageAfterRedirect($synced . ' movimentações sincronizadas; ' . $reconciled
        . ' chamados existentes atualizados. Eventos restantes serão processados pelo cron do GLPI.', true);
    Html::back();
}

if (isset($_POST['sheet_sync_now'])) {
    require_once GLPI_ROOT . '/plugins/tralla/inc/sheet.class.php';
    try {
        $count = PluginTrallaSheetSync::sync();
        Session::addMessageAfterRedirect($count . ' linha(s) atualizada(s) na planilha SEINTEC.', true);
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect('Sincronização da planilha falhou: ' . $e->getMessage(), false, ERROR);
    }
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
$ticketSlas = ['tto' => [], 'ttr' => []];
$maintenanceBoards = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_tralla_boards',
    'WHERE' => ['type' => 'maintenance', 'is_archived' => 0], 'ORDER' => 'name ASC']) as $sheetBoard) {
    $maintenanceBoards[(int) $sheetBoard['id']] = (string) $sheetBoard['name'];
}
foreach ($DB->request(['SELECT' => ['id', 'name', 'type'], 'FROM' => 'glpi_slas', 'ORDER' => 'name ASC']) as $slaRow) {
    $kind = (int) $slaRow['type'] === SLM::TTO ? 'tto' : 'ttr';
    $ticketSlas[$kind][(int) $slaRow['id']] = (string) $slaRow['name'];
}

Html::header('Tralla', $_SERVER['PHP_SELF'], 'tools', 'PluginTrallaBoard');
echo "<script>document.title = 'Tralla';</script>";
?>
<div class="tralla-page tralla-config-page">
    <header class="tralla-page-head">
        <div class="tralla-brand">
            <img class="tralla-brand-logo" src="<?= tralla_h($pluginUrl) ?>/logo.png?v=<?= (int) @filemtime(GLPI_ROOT . '/plugins/tralla/public/logo.png') ?>" alt="">
            <div>
                <h1>Configurações</h1>
                <p>Integração com chamados GLPI, assignat-ure, aprovações e notificações.</p>
            </div>
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
                <p>Vincule o Tralla ao Assignat-ure para coletar a assinatura e concluir a retirada automaticamente.</p>
            </header>
            <div class="tralla-field">
                <label for="tralla-assignature-url">URL do assignat-ure</label>
                <input id="tralla-assignature-url" type="url" name="assignature_url" maxlength="255"
                       placeholder="https://SEU-GLPI/plugins/assignature/front/request.php"
                       value="<?= tralla_h($config['assignature_url']) ?>">
                <small>O Tralla envia <code>?card={id}</code> para esta URL.</small>
            </div>
            <div class="tralla-field">
                <label for="tralla-assignature-token">Token de acesso</label>
                <div class="tralla-input-group">
                    <input id="tralla-assignature-token" type="text" readonly value="<?= tralla_h($token) ?>">
                    <button type="submit" name="regenerate_token" value="1" class="tralla-btn tralla-btn-ghost"
                            data-tralla-confirm="Gerar um novo token? O valor antigo deixará de funcionar."
                            data-tralla-confirm-title="Gerar novo token"
                            data-tralla-confirm-label="Gerar token"
                            data-tralla-confirm-icon="ti-refresh"
                            data-tralla-confirm-tone="primary">
                        <i class="ti ti-refresh"></i> Gerar novo
                    </button>
                </div>
                <small>Copie este token para a configuração do assignat-ure. Ele não é exibido em nenhuma outra tela.</small>
            </div>
        </section>

        <section class="tralla-card tralla-config-card">
            <header>
                <h2><i class="ti ti-ticket"></i> Chamados do GLPI</h2>
                <p>Um chamado por card principal de manutenção ou chamado. Movimentações e alterações entram como acompanhamentos no mesmo chamado.</p>
            </header>
            <label class="tralla-check"><input type="checkbox" name="ticket_sync_enabled" value="1" <?= ($config['ticket_sync_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Criar e atualizar chamados automaticamente</label>
            <p class="tralla-hint">Vincule as prioridades do Tralla aos SLAs nativos já cadastrados no GLPI. Sem associação, valem as regras de SLA do próprio GLPI.</p>
            <?php foreach (tralla_priorities() as $priority => $spec): ?>
                <div class="tralla-form-row">
                    <div class="tralla-field">
                        <label for="tralla-sla-tto-<?= tralla_h($priority) ?>"><?= tralla_h($spec['label']) ?> · SLA de 1º atendimento</label>
                        <select id="tralla-sla-tto-<?= tralla_h($priority) ?>" name="ticket_sla_tto_<?= tralla_h($priority) ?>">
                            <option value="0">Usar regras do GLPI</option>
                            <?php foreach ($ticketSlas['tto'] as $slaId => $slaName): ?><option value="<?= $slaId ?>" <?= (int) ($config['ticket_sla_tto_' . $priority] ?? 0) === $slaId ? 'selected' : '' ?>><?= tralla_h($slaName) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tralla-field">
                        <label for="tralla-sla-ttr-<?= tralla_h($priority) ?>"><?= tralla_h($spec['label']) ?> · SLA de solução</label>
                        <select id="tralla-sla-ttr-<?= tralla_h($priority) ?>" name="ticket_sla_ttr_<?= tralla_h($priority) ?>">
                            <option value="0">Usar regras do GLPI</option>
                            <?php foreach ($ticketSlas['ttr'] as $slaId => $slaName): ?><option value="<?= $slaId ?>" <?= (int) ($config['ticket_sla_ttr_' . $priority] ?? 0) === $slaId ? 'selected' : '' ?>><?= tralla_h($slaName) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="submit" name="ticket_sync_existing" value="1" class="tralla-btn tralla-btn-ghost"
                    data-tralla-confirm="Criar chamados GLPI para os cards de manutenção existentes e registrar seu histórico?"
                    data-tralla-confirm-title="Sincronizar cards existentes"
                    data-tralla-confirm-label="Sincronizar">
                <i class="ti ti-refresh"></i> Sincronizar cards existentes
            </button>
        </section>

        <section class="tralla-card tralla-config-card">
            <header>
                <h2><i class="ti ti-table"></i> Planilha SEINTEC</h2>
                <p>Atualiza a aba do Google Sheets a cada 30 minutos, uma linha por card principal. Cards de aprovação não são exportados.</p>
            </header>
            <a href="https://docs.google.com/spreadsheets/d/1FOViixQ3jH2QwTOzzJglUDaWXf1cxfjvxxbvYI7UMMU/edit?gid=0" target="_blank" rel="noopener noreferrer">Abrir planilha SEINTEC</a>
            <label class="tralla-check"><input type="checkbox" name="sheet_sync_enabled" value="1" <?= ($config['sheet_sync_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Ativar sincronização</label>
            <div class="tralla-field">
                <label for="tralla-sheet-board">Quadro de manutenção</label>
                <select id="tralla-sheet-board" name="sheet_board_id">
                    <option value="0">Selecione o quadro...</option>
                    <?php foreach ($maintenanceBoards as $boardId => $boardName): ?>
                        <option value="<?= $boardId ?>" <?= (int) ($config['sheet_board_id'] ?? 0) === $boardId ? 'selected' : '' ?>><?= tralla_h($boardName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tralla-field">
                <label for="tralla-sheet-tab">Nome da aba</label>
                <input id="tralla-sheet-tab" type="text" name="sheet_tab" maxlength="100" value="<?= tralla_h($config['sheet_tab'] ?? 'Página1') ?>">
            </div>
            <div class="tralla-field">
                <label for="tralla-sheet-key">Caminho do JSON da conta de serviço no servidor</label>
                <input id="tralla-sheet-key" type="text" name="sheet_credentials_path" maxlength="500"
                       placeholder="/etc/glpi/tralla-google-service-account.json"
                       value="<?= tralla_h($config['sheet_credentials_path'] ?? '') ?>">
                <small>Crie uma conta de serviço com a API Google Sheets ativa, compartilhe a planilha com o e-mail dela como Editor e coloque o JSON fora da pasta pública do GLPI, legível pelo www-data.</small>
            </div>
            <small>Colunas: Código Card, Data Inclusão (Pegar), Data Finalização (Finalizar), Tipo, Situação, Descrição, Escola e Observação.</small>
            <?php if ((int) ($config['sheet_last_success'] ?? 0) > 0): ?>
                <p class="tralla-hint">Última sincronização: <?= tralla_h(date('d/m/Y H:i', (int) $config['sheet_last_success'])) ?></p>
            <?php endif; ?>
            <?php if (($config['sheet_last_error'] ?? '') !== ''): ?>
                <p class="tralla-hint">Último erro: <?= tralla_h($config['sheet_last_error']) ?></p>
            <?php endif; ?>
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

    <form method="post" action="<?= tralla_h($pluginUrl) ?>/front/config.form.php">
        <?= Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]) ?>
        <button type="submit" name="sheet_sync_now" value="1" class="tralla-btn tralla-btn-ghost">
            <i class="ti ti-refresh"></i> Sincronizar planilha agora
        </button>
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
