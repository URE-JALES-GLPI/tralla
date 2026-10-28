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
            'description' => 'Tralla: notifica cards pendentes há mais de 1h e reenvia lembretes de retirada.',
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

        require_once __DIR__ . '/ticket.class.php';
        $count += PluginTrallaTicketSync::syncPending(200);

        if (method_exists($task, 'addVolume')) {
            $task->addVolume($count);
        }

        return 1;
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
