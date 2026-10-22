<?php
/**
 * Helpers puros e utilitários compartilhados do Tralla.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

if (!function_exists('tralla_now')) {
    function tralla_now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }
}

if (!function_exists('tralla_h')) {
    function tralla_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('tralla_category_labels')) {
    function tralla_category_labels(): array
    {
        return [
            ''                      => 'Sem categoria (normal)',
            'maintenance_pending'   => 'Manutenção Pendente',
            'maintenance_in_progress' => 'Manutenção Em Andamento',
            'maintenance_approval'  => 'Manutenção Aprovação',
            'maintenance_withdrawal' => 'Manutenção Retirada',
            'maintenance_done'      => 'Manutenção Concluído',
        ];
    }
}

if (!function_exists('tralla_maintenance_categories')) {
    function tralla_maintenance_categories(): array
    {
        return [
            'maintenance_pending',
            'maintenance_in_progress',
            'maintenance_approval',
            'maintenance_withdrawal',
            'maintenance_done',
        ];
    }
}

if (!function_exists('tralla_is_maintenance_category')) {
    function tralla_is_maintenance_category(string $category): bool
    {
        return in_array($category, tralla_maintenance_categories(), true);
    }
}

if (!function_exists('tralla_category_label')) {
    function tralla_category_label(string $category): string
    {
        $labels = tralla_category_labels();

        return $labels[$category] ?? $category;
    }
}

if (!function_exists('tralla_category_colors')) {
    function tralla_category_colors(): array
    {
        return [
            ''                       => '#97a0af',
            'maintenance_pending'    => '#ff9f1a',
            'maintenance_in_progress' => '#0079bf',
            'maintenance_approval'   => '#c377e0',
            'maintenance_withdrawal' => '#f2d600',
            'maintenance_done'       => '#61bd4f',
        ];
    }
}

if (!function_exists('tralla_category_color')) {
    function tralla_category_color(string $category): string
    {
        $colors = tralla_category_colors();

        return $colors[$category] ?? '#97a0af';
    }
}

if (!function_exists('tralla_status_labels')) {
    function tralla_status_labels(): array
    {
        return [
            ''                 => '—',
            'normal'           => 'Aberto',
            'pending'          => 'Pendente',
            'in_progress'      => 'Em andamento',
            'waiting_approval' => 'Aguardando aprovação',
            'withdrawal'       => 'Aguardando retirada',
            'done'             => 'Concluído',
            'approved'         => 'Aprovado',
            'rejected'         => 'Reprovado',
        ];
    }
}

if (!function_exists('tralla_status_label')) {
    function tralla_status_label(string $status): string
    {
        $labels = tralla_status_labels();

        return $labels[$status] ?? $status;
    }
}

if (!function_exists('tralla_machine_status_labels')) {
    function tralla_machine_status_labels(): array
    {
        return [
            ''                 => 'Não preenchido',
            'pending'          => 'Pendente',
            'in_progress'      => 'Em andamento',
            'done'             => 'Concluído',
            'not_repairable'   => 'Não reparável',
        ];
    }
}

if (!function_exists('tralla_machine_status_label')) {
    function tralla_machine_status_label(string $status): string
    {
        $labels = tralla_machine_status_labels();

        return $labels[$status] ?? $status;
    }
}

if (!function_exists('tralla_machine_status_choices')) {
    function tralla_machine_status_choices(): array
    {
        return [
            'pending'        => 'Pendente',
            'in_progress'    => 'Em andamento',
            'done'           => 'Concluído',
            'not_repairable' => 'Não reparável',
        ];
    }
}

if (!function_exists('tralla_source_labels')) {
    function tralla_source_labels(): array
    {
        return [
            ''                  => 'Não informada',
            'notebook_desktop'  => 'Notebook / Desktop',
            'tablet_mobile'     => 'Tablet / Celular',
            'manual'            => 'Manual',
        ];
    }
}

if (!function_exists('tralla_source_choices')) {
    function tralla_source_choices(): array
    {
        return [
            'notebook_desktop' => 'Notebook / Desktop',
            'tablet_mobile'    => 'Tablet / Celular',
            'manual'           => 'Manual',
        ];
    }
}

if (!function_exists('tralla_source_label')) {
    function tralla_source_label(string $source): string
    {
        $labels = tralla_source_labels();

        return $labels[$source] ?? $source;
    }
}

if (!function_exists('tralla_backgrounds')) {
    function tralla_backgrounds(): array
    {
        return [
            'ocean'    => 'Oceano',
            'aurora'   => 'Aurora',
            'sunset'   => 'Pôr do sol',
            'forest'   => 'Floresta',
            'graphite' => 'Grafite',
            'candy'    => 'Doce',
        ];
    }
}

if (!function_exists('tralla_valid_color')) {
    function tralla_valid_color(string $color, string $default = '#0079bf'): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $default;
    }
}

if (!function_exists('tralla_valid_background')) {
    function tralla_valid_background(string $background, string $default = 'ocean'): string
    {
        return array_key_exists($background, tralla_backgrounds()) ? $background : $default;
    }
}

if (!function_exists('tralla_display_code')) {
    /**
     * Código do card: 300/1, 300/2 ou #300.
     */
    function tralla_display_code(array $card, string $board_type = ''): string
    {
        $stage = (int) ($card['stage'] ?? 1);
        $parent = (int) ($card['parent_cards_id'] ?? 0);
        $id = (int) ($card['id'] ?? 0);

        if ($stage === 2 && $parent > 0) {
            return $parent . '/2';
        }

        $isMaintenance = $board_type === 'maintenance' || !empty($card['is_maintenance']);

        if ($isMaintenance && $id > 0) {
            return $id . '/1';
        }

        return $id > 0 ? '#' . $id : '';
    }
}

if (!function_exists('tralla_machine_is_filled')) {
    function tralla_machine_is_filled(array $machine): bool
    {
        $report = trim((string) ($machine['report'] ?? ''));
        $status = (string) ($machine['status'] ?? '');

        return $report !== '' && in_array($status, ['done', 'not_repairable'], true);
    }
}

if (!function_exists('tralla_all_machines_filled')) {
    function tralla_all_machines_filled(array $machines): bool
    {
        if (count($machines) === 0) {
            return false;
        }

        foreach ($machines as $machine) {
            if (!tralla_machine_is_filled($machine)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('tralla_machines_progress')) {
    function tralla_machines_progress(array $machines): array
    {
        $total = count($machines);
        $filled = 0;
        foreach ($machines as $machine) {
            if (tralla_machine_is_filled($machine)) {
                $filled++;
            }
        }

        return [
            'total'   => $total,
            'filled'  => $filled,
            'percent' => $total > 0 ? (int) round(($filled / $total) * 100) : 0,
        ];
    }
}

if (!function_exists('tralla_can_transition')) {
    /**
     * Regras puras de transição. A decisão final sempre inclui permissões
     * e vínculo com o técnico, avaliados nas classes de domínio.
     */
    function tralla_can_transition(array $card, string $action, string $boardType = 'maintenance'): bool
    {
        $stage = (int) ($card['stage'] ?? 1);
        $status = (string) ($card['status'] ?? '');
        $locked = !empty($card['is_locked']);
        $archived = !empty($card['is_archived']);

        if ($archived) {
            return false;
        }

        if ($boardType !== 'maintenance') {
            return false;
        }

        switch ($action) {
            case 'take':
                return $stage === 1 && $status === 'pending' && !$locked;
            case 'finalize':
                return $stage === 1 && $status === 'in_progress' && !$locked;
            case 'approve':
            case 'reject':
                return $stage === 2 && $status === 'waiting_approval';
            case 'withdraw':
                return $stage === 1 && $status === 'withdrawal' && !$locked;
        }

        return false;
    }
}

if (!function_exists('tralla_normalize_report')) {
    function tralla_normalize_report($text): string
    {
        $text = trim((string) $text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 200000);
        }

        return substr($text, 0, 200000);
    }
}

if (!function_exists('tralla_normalize_label')) {
    function tralla_normalize_label($text): string
    {
        $text = trim(strip_tags((string) $text));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 255);
        }

        return substr($text, 0, 255);
    }
}

if (!function_exists('tralla_all_entity_options')) {
    /**
     * Entidades disponíveis para o combobox de nome do card.
     *
     * A lista é intencionalmente global (sem filtro de acesso) para que o
     * operador possa nomear cards com qualquer entidade do GLPI. Entidades
     * removidas não permanecem na tabela, portanto não há flag de exclusão.
     *
     * @return array<int, array{id:int,name:string,completename:string}>
     */
    function tralla_all_entity_options(): array
    {
        global $DB;

        $entities = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_entities',
            'ORDER' => 'completename ASC, name ASC',
        ]) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $complete = trim((string) ($row['completename'] ?? ''));
            $entities[] = [
                'id'           => (int) ($row['id'] ?? 0),
                'name'         => $name,
                'completename' => $complete !== '' ? $complete : $name,
            ];
        }

        return $entities;
    }
}

if (!function_exists('tralla_post_string')) {
    function tralla_post_string(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}

if (!function_exists('tralla_post_int')) {
    function tralla_post_int(string $key, int $default = 0): int
    {
        $value = $_POST[$key] ?? $default;

        return is_scalar($value) ? (int) $value : $default;
    }
}

if (!function_exists('tralla_get_int')) {
    function tralla_get_int(string $key, int $default = 0): int
    {
        $value = $_GET[$key] ?? $default;

        return is_scalar($value) ? (int) $value : $default;
    }
}

if (!function_exists('tralla_user_name')) {
    function tralla_user_name(int $users_id): string
    {
        static $cache = [];

        $users_id = (int) $users_id;
        if ($users_id <= 0) {
            return '';
        }
        if (array_key_exists($users_id, $cache)) {
            return $cache[$users_id];
        }

        $name = '';
        try {
            $user = new User();
            if ($user->getFromDB($users_id)) {
                $name = (string) $user->getFriendlyName();
            }
        } catch (Throwable $e) {
            $name = '';
        }

        $cache[$users_id] = $name;

        return $name;
    }
}

if (!function_exists('tralla_date')) {
    function tralla_date($value): string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '—';
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
    }
}

if (!function_exists('tralla_log')) {
    function tralla_log(string $message): void
    {
        try {
            if (class_exists('Toolbox')) {
                Toolbox::logInFile('tralla', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
            }
        } catch (Throwable $e) {
            // Log nunca deve interromper o fluxo.
        }
    }
}

if (!function_exists('tralla_json')) {
    function tralla_json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('tralla_mail_row')) {
    /**
     * Monta a linha da fila oficial de e-mails do GLPI.
     * Puro o suficiente para ser testado; nunca envia diretamente.
     */
    function tralla_mail_row(string $to, string $subject, string $htmlBody, int $entities_id = 0, int $cards_id = 0): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        $subject = trim($subject);
        if ($subject === '') {
            $subject = '[URE] Tralla';
        }
        if (function_exists('mb_substr') && mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        } elseif (strlen($subject) > 255) {
            $subject = substr($subject, 0, 255);
        }

        $text = html_entity_decode(
            strip_tags((string) preg_replace('/<(br|p|div|li|tr)[^>]*>/i', "\n", $htmlBody)),
            ENT_QUOTES,
            'UTF-8'
        );
        $text = trim((string) preg_replace("/[ \t]+/", ' ', $text));

        $sender = '';
        $senderName = '[URE] Tralla';
        try {
            if (class_exists('Config') && method_exists('Config', 'getAdminEmailSender')) {
                $admin = Config::getAdminEmailSender();
                if (is_array($admin)) {
                    if (trim((string) ($admin['email'] ?? '')) !== '') {
                        $sender = trim((string) $admin['email']);
                    }
                    if (trim((string) ($admin['name'] ?? '')) !== '') {
                        $senderName = trim((string) $admin['name']);
                    }
                }
            }
        } catch (Throwable $e) {
            // Usa fallback do CFG_GLPI abaixo.
        }

        if ($sender === '') {
            try {
                global $CFG_GLPI;
                $sender = trim((string) ($CFG_GLPI['admin_email'] ?? ''));
                $configuredName = trim((string) ($CFG_GLPI['admin_email_name'] ?? ''));
                if ($configuredName !== '') {
                    $senderName = $configuredName;
                }
            } catch (Throwable $e) {
                // Mantém remetente vazio; o GLPI usa o padrão.
            }
        }

        $attachNoDocument = 0;
        try {
            if (class_exists('NotificationSetting') && defined('NotificationSetting::ATTACH_NO_DOCUMENT')) {
                $attachNoDocument = (int) constant('NotificationSetting::ATTACH_NO_DOCUMENT');
            }
        } catch (Throwable $e) {
            $attachNoDocument = 0;
        }

        $now = date('Y-m-d H:i:s');

        return [
            'name'                     => $subject,
            'sender'                   => $sender,
            'sendername'               => $senderName,
            'recipient'                => $to,
            'recipientname'            => '',
            'replyto'                  => '',
            'replytoname'              => '',
            'headers'                  => '',
            'body_html'                => $htmlBody,
            'body_text'                => $text,
            'messageid'                => '',
            'mode'                     => 'mailing',
            'itemtype'                 => 'PluginTrallaCard',
            'items_id'                 => $cards_id,
            'notificationtemplates_id' => 0,
            'entities_id'              => $entities_id,
            'event'                    => null,
            'attach_documents'         => $attachNoDocument,
            'is_deleted'               => 0,
            'create_time'              => $now,
            'send_time'                => $now,
        ];
    }
}

if (!function_exists('tralla_queue_email')) {
    /**
     * Enfileira e-mail na fila do GLPI. Falhas nunca interrompem o fluxo.
     */
    function tralla_queue_email(string $to, string $subject, string $htmlBody, int $entities_id = 0, int $cards_id = 0): bool
    {
        $row = tralla_mail_row($to, $subject, $htmlBody, $entities_id, $cards_id);
        if ($row === []) {
            return false;
        }

        try {
            if (!class_exists('QueuedNotification')) {
                return false;
            }
            $queue = new QueuedNotification();
            return (bool) $queue->add($row);
        } catch (Throwable $e) {
            tralla_log('Falha ao enfileirar e-mail: ' . $e->getMessage());

            return false;
        }
    }
}
