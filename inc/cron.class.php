<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Tarefas agendadas do Tralla.
 */
class PluginTrallaCron extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTypeName($nb = 0)
    {
        return _n('Notificação Tralla', 'Notificações Tralla', $nb, 'tralla');
    }

    public static function cronInfo(): array
    {
        return [
            'description' => 'Tralla: lembretes de cards pendentes por prioridade, avisos de SLA e lembretes de retirada.',
            'parameter'   => '',
        ];
    }

    public static function cronNotify(CronTask $task): int
    {
        global $DB;

        $count = 0;
        $now = time();

        // 1) Cards Pendentes sem "Pegar" há mais de pending_hours.
        $pendingLimit = $now - (PluginTrallaConfig::pendingHours() * 3600);
        $pending = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'stage'          => 1,
                'status'         => 'pending',
                'is_archived'    => 0,
                'pending_notified' => 0,
                'is_paused'      => 0,
            ],
        ]);

        foreach ($pending as $card) {
            $created = strtotime((string) $card['date_creation']);
            if ($created === false || $created > $pendingLimit) {
                continue;
            }

            $message = 'Card ' . tralla_display_code($card, 'maintenance')
                . ' (' . $card['name'] . ') está pendente há mais de '
                . PluginTrallaConfig::pendingHours() . 'h sem ser pego.';

            self::notifyCard((int) $card['id'], 'pending_1h', $message);
            $DB->update('glpi_plugin_tralla_cards', [
                'pending_notified' => 1,
                'date_mod'         => tralla_now(),
            ], ['id' => (int) $card['id']]);

            PluginTrallaHistory::log((int) $card['id'], 0, 'pending_notified', $message);
            $count++;
        }

        // 2) Cards em Retirada: reenvia até a confirmação.
        $reminderLimit = $now - (PluginTrallaConfig::withdrawalHours() * 3600);
        $withdrawal = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'stage'       => 1,
                'status'      => 'withdrawal',
                'is_archived' => 0,
            ],
        ]);

        foreach ($withdrawal as $card) {
            $approved = strtotime((string) $card['date_approved']);
            if ($approved === false || $approved > $reminderLimit) {
                continue;
            }

            $lastReminder = $card['withdrawal_reminded_at'] ? strtotime((string) $card['withdrawal_reminded_at']) : false;
            if ($lastReminder !== false && $lastReminder > $reminderLimit) {
                continue;
            }

            $message = 'Card ' . tralla_display_code($card, 'maintenance')
                . ' (' . $card['name'] . ') aguarda retirada. Lembrete reenviado.';

            self::notifyCard((int) $card['id'], 'withdrawal_reminder', $message);
            $DB->update('glpi_plugin_tralla_cards', [
                'withdrawal_reminded_at' => tralla_now(),
                'date_mod'               => tralla_now(),
            ], ['id' => (int) $card['id']]);

            PluginTrallaHistory::log((int) $card['id'], 0, 'withdrawal_reminded', $message);
            $count++;
        }

        $count += self::remindPendingByPriority();
        $count += self::warnSlaDeadlines();

        require_once __DIR__ . '/ticket.class.php';
        $count += PluginTrallaTicketSync::syncPending(200);
        require_once __DIR__ . '/sheet.class.php';
        $count += PluginTrallaSheetSync::syncIfDue();

        if (method_exists($task, 'addVolume')) {
            $task->addVolume($count);
        }

        return 1;
    }

    /** Resultado dos envios da execução atual (usado pelo "Forçar notificações"). */
    private static array $delivery = ['email_queued' => 0, 'email_failed' => 0, 'whatsapp_sent' => 0, 'whatsapp_failed' => 0];

    /**
     * "Forçar notificações" do quadro: envia agora, sem esperar o intervalo, o lembrete
     * de cada card liberado no Pendente e o aviso de cada card a 4 horas ou menos do SLA.
     */
    public static function forceForBoard(int $boardId): array
    {
        self::$delivery = array_map(static fn () => 0, self::$delivery);
        $pending = self::remindPendingByPriority($boardId, true);
        $sla = self::warnSlaDeadlines($boardId, true);

        return ['pending' => $pending, 'sla' => $sla] + self::$delivery;
    }

    /**
     * Cards no Pendente, já liberados com prioridade: lembra os membros do quadro
     * a cada intervalo da prioridade, contando a partir da criação (ou liberação).
     */
    private static function remindPendingByPriority(int $boardId = 0, bool $force = false): int
    {
        global $DB;

        $count = 0;
        $now = time();
        foreach ($DB->request([
            'SELECT'     => ['c.*', 'b.name AS board_name', 'b.entities_id AS board_entities_id',
                'b.notify_email AS board_notify_email', 'b.notify_whatsapp AS board_notify_whatsapp'],
            'FROM'       => 'glpi_plugin_tralla_cards AS c',
            'INNER JOIN' => ['glpi_plugin_tralla_boards AS b' => ['ON' => ['c' => 'plugin_tralla_boards_id', 'b' => 'id']]],
            'WHERE'      => [
                'b.type' => 'maintenance', 'b.is_archived' => 0,
                'OR' => ['b.notify_email' => 1, 'b.notify_whatsapp' => 1],
                'c.stage' => 1, 'c.status' => 'pending', 'c.is_locked' => 0,
                'c.is_paused' => 0,
                'c.is_archived' => 0, 'c.is_deleted' => 0, 'c.priority' => ['!=', ''],
            ] + ($boardId > 0 ? ['c.plugin_tralla_boards_id' => $boardId] : []),
        ]) as $card) {
            $minutes = tralla_pending_reminder_minutes((string) $card['priority']);
            $base = strtotime((string) ($card['pending_reminded_at'] ?: ($card['date_sla_start'] ?: $card['date_creation'])));
            if ($minutes <= 0 || $base === false || (!$force && $now < $base + $minutes * 60)) {
                continue;
            }

            $code = tralla_display_code($card, 'maintenance');
            $waiting = max(1, (int) round(($now - strtotime((string) ($card['date_sla_start'] ?: $card['date_creation']))) / 60));
            $text = 'Tralla: o card #' . $code . ' (' . $card['name'] . ') está no Pendente há '
                . self::durationLabel($waiting) . ' sem técnico. Prioridade ' . (tralla_priorities()[$card['priority']]['label'] ?? $card['priority'])
                . '. Clique em Pegar no quadro ' . $card['board_name'] . '.';
            self::notifyUsers($card, self::boardMemberIds((int) $card['plugin_tralla_boards_id']), 'pending_priority',
                'card pendente #' . $code, $text);
            $DB->update('glpi_plugin_tralla_cards', ['pending_reminded_at' => tralla_now()], ['id' => (int) $card['id']]);
            $count++;
        }

        return $count;
    }

    /**
     * Cards em andamento: avisa o técnico e os aprovadores 4, 3, 2 e 1 hora antes
     * do prazo das respostas paliativa e definitiva. Cada marca é enviada uma vez;
     * forçado, reenvia para todo card a 4 horas ou menos do prazo.
     */
    private static function warnSlaDeadlines(int $boardId = 0, bool $force = false): int
    {
        global $DB;

        $count = 0;
        $now = time();
        foreach ($DB->request([
            'SELECT'     => ['c.*', 'b.name AS board_name', 'b.entities_id AS board_entities_id',
                'b.notify_email AS board_notify_email', 'b.notify_whatsapp AS board_notify_whatsapp'],
            'FROM'       => 'glpi_plugin_tralla_cards AS c',
            'INNER JOIN' => ['glpi_plugin_tralla_boards AS b' => ['ON' => ['c' => 'plugin_tralla_boards_id', 'b' => 'id']]],
            'WHERE'      => [
                'b.type' => 'maintenance', 'b.is_archived' => 0,
                'OR' => ['b.notify_email' => 1, 'b.notify_whatsapp' => 1],
                'c.stage' => 1, 'c.status' => 'in_progress', 'c.technician_id' => ['>', 0],
                'c.is_paused' => 0,
                'c.is_archived' => 0, 'c.is_deleted' => 0, 'c.priority' => ['!=', ''],
            ] + ($boardId > 0 ? ['c.plugin_tralla_boards_id' => $boardId] : []),
        ]) as $card) {
            $sla = tralla_card_sla($card);
            if ($sla === null) {
                continue;
            }
            $sent = array_filter(explode(',', (string) $card['sla_alerts_sent']));
            $newMarks = [];
            $alerts = [];
            foreach ($sla['steps'] as $step) {
                if (!in_array($step['key'], ['palliative', 'definitive'], true) || $step['state'] !== 'running') {
                    continue;
                }
                $left = strtotime((string) $step['deadline']) - $now;
                $crossed = [];
                foreach ([4, 3, 2, 1] as $hours) {
                    $mark = $step['key'] . ':' . $hours;
                    if ($left <= $hours * 3600 && ($force || !in_array($mark, $sent, true))) {
                        $crossed[] = $mark;
                    }
                }
                if ($crossed !== [] && $left > 0) {
                    $newMarks = array_merge($newMarks, $crossed);
                    $alerts[] = $step['title'] . ' vence em ' . self::durationLabel((int) ceil($left / 60))
                        . ' (' . tralla_date((string) $step['deadline']) . ')';
                }
            }
            if ($alerts === []) {
                continue;
            }

            $code = tralla_display_code($card, 'maintenance');
            $text = 'Tralla: SLA do card #' . $code . ' (' . $card['name'] . ') está perto de estourar. '
                . implode('; ', $alerts) . '. Técnico: ' . tralla_user_name((int) $card['technician_id']) . '.';
            $recipients = array_merge([(int) $card['technician_id']], self::boardApproverIds((int) $card['plugin_tralla_boards_id']));
            self::notifyUsers($card, $recipients, 'sla_alert', 'SLA perto de estourar #' . $code, $text);
            $DB->update('glpi_plugin_tralla_cards', [
                'sla_alerts_sent' => substr(implode(',', array_unique(array_merge($sent, $newMarks))), 0, 255),
            ], ['id' => (int) $card['id']]);
            PluginTrallaHistory::log((int) $card['id'], 0, 'sla_alert', $text);
            $count++;
        }

        return $count;
    }

    private static function durationLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours . 'h' . ($rest > 0 ? sprintf('%02d', $rest) : '');
    }

    /** @return int[] Membros do quadro que operam cards (sem o papel Tablet). */
    private static function boardMemberIds(int $boardId): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => ['plugin_tralla_boards_id' => $boardId, 'role' => ['member', 'admin', 'superadmin']],
        ]) as $row) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    /** @return int[] */
    private static function boardApproverIds(int $boardId): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_plugin_tralla_boards_users',
            'WHERE'  => ['plugin_tralla_boards_id' => $boardId, 'can_approve' => 1, 'NOT' => ['role' => 'tablet']],
        ]) as $row) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    /**
     * Envia a mensagem a cada usuário pelos canais ligados no quadro do card:
     * e-mail padrão do GLPI e WhatsApp pelo celular (ou telefone) do cadastro.
     */
    private static function notifyUsers(array $card, array $userIds, string $type, string $subject, string $text): void
    {
        global $DB, $CFG_GLPI;

        $cardId = (int) $card['id'];
        $link = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/tralla/front/kanban.php?boards_id='
            . (int) $card['plugin_tralla_boards_id'];
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            $user = $DB->request([
                'SELECT' => ['mobile', 'phone'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $userId, 'is_active' => 1, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ])->current();
            if (!is_array($user)) {
                continue;
            }
            if (!empty($card['board_notify_email'])) {
                $email = $DB->request([
                    'SELECT' => ['email'],
                    'FROM'   => 'glpi_useremails',
                    'WHERE'  => ['users_id' => $userId],
                    'ORDER'  => 'is_default DESC',
                    'LIMIT'  => 1,
                ])->current();
                $address = is_array($email) ? (string) $email['email'] : '';
                $html = '<p>' . tralla_h($text) . '</p><p><a href="' . tralla_h($link) . '">Abrir o quadro no Tralla</a></p>';
                $sent = $address !== '' && tralla_queue_email($address, '[URE] Tralla — ' . $subject, $html,
                    (int) ($card['board_entities_id'] ?? 0), $cardId);
                self::logNotification($cardId, $type, 'email', $address, $sent ? 'queued' : 'failed', $text);
                self::$delivery[$sent ? 'email_queued' : 'email_failed']++;
            }
            if (!empty($card['board_notify_whatsapp'])) {
                $phone = trim((string) ($user['mobile'] ?: $user['phone']));
                $sent = $phone !== '' && tralla_whatsapp_send($phone, $text . "\n" . $link);
                self::logNotification($cardId, $type, 'whatsapp', $phone, $sent ? 'sent' : 'failed', $text);
                self::$delivery[$sent ? 'whatsapp_sent' : 'whatsapp_failed']++;
            }
        }
    }

    private static function notifyCard(int $cards_id, string $type, string $message): void
    {
        global $DB;

        $card = PluginTrallaCard::getCard($cards_id);
        if ($card === null) {
            return;
        }

        $board = $DB->request([
            'SELECT' => ['name', 'entities_id'],
            'FROM'   => 'glpi_plugin_tralla_boards',
            'WHERE'  => ['id' => (int) $card['plugin_tralla_boards_id']],
            'LIMIT'  => 1,
        ])->current();

        $entitiesId = (int) ($board['entities_id'] ?? 0);
        $boardName = (string) ($board['name'] ?? 'Quadro');
        $code = tralla_display_code($card, 'maintenance');

        $emails = [];
        if (PluginTrallaConfig::notificationsEnabled()) {
            $emails = PluginTrallaConfig::notificationEmails();
        }

        if ($emails === []) {
            self::logNotification($cards_id, $type, 'log', '', 'sent', $message);
            return;
        }

        $subject = '[URE] Tralla — ' . $code . ' — ' . ($type === 'pending_1h' ? 'card pendente' : 'retirada pendente');
        $html = '<p>' . tralla_h($message) . '</p>'
            . '<p><strong>Quadro:</strong> ' . tralla_h($boardName) . '<br>'
            . '<strong>Card:</strong> ' . tralla_h($code) . '<br>'
            . '<strong>Nome:</strong> ' . tralla_h((string) $card['name']) . '</p>';

        foreach ($emails as $email) {
            $sent = tralla_queue_email($email, $subject, $html, $entitiesId, $cards_id);
            self::logNotification(
                $cards_id,
                $type,
                'email',
                $email,
                $sent ? 'queued' : 'failed',
                $message
            );
        }
    }

    private static function logNotification(
        int $cards_id,
        string $type,
        string $channel,
        string $recipient,
        string $status,
        string $message
    ): void {
        global $DB;

        $DB->insert('glpi_plugin_tralla_notifications', [
            'plugin_tralla_cards_id' => $cards_id,
            'type'                   => $type,
            'channel'                => $channel,
            'recipient'              => $recipient,
            'status'                 => $status,
            'message'                => $message,
            'date_creation'          => tralla_now(),
        ]);
    }
}
