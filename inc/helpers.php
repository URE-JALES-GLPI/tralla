<?php
/**
 * Helpers puros e utilitários compartilhados do Tralla.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/domain.php';

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
            'update_future'         => 'Atualização Futura',
            'update_pending'        => 'Atualização Pendente',
            'update_in_progress'    => 'Atualização Em Andamento',
            'update_done'           => 'Atualização Concluído',
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

if (!function_exists('tralla_update_categories')) {
    function tralla_update_categories(): array
    {
        return ['update_future', 'update_pending', 'update_in_progress', 'update_done'];
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
            'update_future'          => '#7c8ba7',
            'update_pending'         => '#f59e0b',
            'update_in_progress'     => '#0284c7',
            'update_done'            => '#22a35a',
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
            'update_future'    => 'Futura',
            'update_pending'   => 'Pendente',
            'update_in_progress' => 'Em andamento',
            'update_done'      => 'Concluído',
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
            'not_repairable'   => 'Inservível',
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
            'not_repairable' => 'Inservível',
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
            'ocean'       => 'Oceano',
            'aurora'      => 'Aurora',
            'sunset'      => 'Pôr do sol',
            'forest'      => 'Floresta',
            'graphite'    => 'Grafite',
            'candy'       => 'Doce',
            'linux'       => 'Linux',
            'windows'     => 'Windows',
            'mac'         => 'Mac',
            'tickets'     => 'Chamados',
            'maintenance' => 'Manutenção',
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

if (!function_exists('tralla_background_color')) {
    function tralla_background_color(string $background): string
    {
        $colors = [
            'ocean'       => '#0079bf',
            'aurora'      => '#4338ca',
            'sunset'      => '#c2410c',
            'forest'      => '#15803d',
            'graphite'    => '#374151',
            'candy'       => '#be185d',
            'linux'       => '#9a3412',
            'windows'     => '#0369a1',
            'mac'         => '#475569',
            'tickets'     => '#155e75',
            'maintenance' => '#92400e',
        ];

        return $colors[tralla_valid_background($background)];
    }
}

if (!function_exists('tralla_display_code')) {
    /**
     * Código do card: AP-<raiz>/<seq> para aprovação, <raiz>/<seq> para
     * manutenção e #<id> para quadros normais.
     */
    function tralla_display_code(array $card, string $board_type = ''): string
    {
        $stage = (int) ($card['stage'] ?? 1);
        $id = (int) ($card['id'] ?? 0);
        $familyRoot = (int) ($card['code_root_cards_id'] ?? 0);
        if ($familyRoot <= 0) {
            $familyRoot = (int) ($card['family_cards_id'] ?? 0);
        }
        $familySeq = (int) ($card['family_seq'] ?? 0);

        if ($stage === 2) {
            if ($familyRoot <= 0) {
                $familyRoot = (int) ($card['parent_cards_id'] ?? 0);
            }
            if ($familySeq <= 0) {
                $familySeq = 2;
            }

            return $familyRoot > 0 ? 'AP-' . $familyRoot . '/' . $familySeq : '';
        }

        $isMaintenance = $board_type === 'maintenance' || !empty($card['is_maintenance']);

        if ($isMaintenance && $id > 0) {
            if ($familyRoot <= 0) {
                $familyRoot = $id;
            }
            if ($familySeq <= 0) {
                $familySeq = 1;
            }

            return $familyRoot . '/' . $familySeq;
        }

        return $id > 0 ? '#' . $id : '';
    }
}

if (!function_exists('tralla_approval_kind_labels')) {
    function tralla_approval_kind_labels(): array
    {
        return [
            'maintenance' => 'Aprovação de finalização',
            'deletion'    => 'Aprovação de exclusão',
            'edit'        => 'Aprovação de edição',
            'split'       => 'Aprovação de separação',
            'call_release' => 'Liberação e prioridade',
        ];
    }
}

if (!function_exists('tralla_approval_kind_label')) {
    function tralla_approval_kind_label(string $kind): string
    {
        $labels = tralla_approval_kind_labels();

        return $labels[$kind] ?? $labels['maintenance'];
    }
}

if (!function_exists('tralla_machine_is_filled')) {
    function tralla_machine_is_filled(array $machine): bool
    {
        $report = trim((string) ($machine['report'] ?? ''));
        $status = (string) ($machine['status'] ?? '');
        $serviceDone = !empty($machine['service_done']);

        return $report !== '' && $serviceDone && in_array($status, ['done', 'not_repairable', 'pending'], true);
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
                return $stage === 1 && $status === 'withdrawal' && !$locked
                    && (string) ($card['workflow_type'] ?? 'maintenance') !== 'call';
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

if (!function_exists('tralla_call_categories')) {
    /** Categorias e subcategorias importadas da planilha de atendimento. */
    function tralla_call_categories(): array
    {
        static $categories = null;
        if ($categories === null) {
            $json = @file_get_contents(__DIR__ . '/../data/call_categories.json');
            $decoded = is_string($json) ? json_decode($json, true) : null;
            $categories = is_array($decoded) ? $decoded : [];
        }
        return $categories;
    }
}

if (!function_exists('tralla_valid_call_category')) {
    function tralla_valid_call_category(string $category, string $subcategory): bool
    {
        $categories = tralla_call_categories();
        return isset($categories[$category])
            && is_array($categories[$category])
            && in_array($subcategory, $categories[$category], true);
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

if (!function_exists('tralla_user_profile')) {
    function tralla_user_profile(int $users_id): array
    {
        static $cache = [];

        if ($users_id <= 0) {
            return ['name' => '', 'picture_url' => ''];
        }
        if (array_key_exists($users_id, $cache)) {
            return $cache[$users_id];
        }

        $profile = ['name' => '', 'picture_url' => ''];
        try {
            $user = new User();
            if ($user->getFromDB($users_id)) {
                $profile['name'] = (string) $user->getFriendlyName();
                $picture = trim((string) ($user->fields['picture'] ?? ''));
                if ($picture !== '') {
                    try {
                        $profile['picture_url'] = User::getURLForPicture($picture);
                    } catch (Throwable $e) {
                        $profile['picture_url'] = '';
                    }
                }
            }
        } catch (Throwable $e) {
            $profile = ['name' => '', 'picture_url' => ''];
        }

        $cache[$users_id] = $profile;

        return $profile;
    }
}

if (!function_exists('tralla_user_name')) {
    function tralla_user_name(int $users_id): string
    {
        return tralla_user_profile($users_id)['name'];
    }
}

if (!function_exists('tralla_user_picture_url')) {
    function tralla_user_picture_url(int $users_id): string
    {
        return tralla_user_profile($users_id)['picture_url'];
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

if (!function_exists('tralla_board_state')) {
    /**
     * Estado do quadro para o Kanban.
     *
     * A lista da categoria "Manutenção Aprovação" e os cards dela só são
     * expostos para quem tem permissão de aprovação neste quadro.
     *
     * @return array{lists: array<int, array<string, mixed>>, cards: array<int, array<string, mixed>>}
     */
    function tralla_board_state(int $boards_id, bool $canApprove): array
    {
        $lists = PluginTrallaList::getListsForBoard($boards_id);
        $cards = PluginTrallaCard::getCardsForBoard($boards_id);

        if ($canApprove) {
            return ['lists' => $lists, 'cards' => $cards];
        }

        $hidden = [];
        foreach ($lists as $list) {
            if ((string) ($list['category'] ?? '') === 'maintenance_approval') {
                $hidden[(int) $list['id']] = true;
            }
        }

        if ($hidden === []) {
            return ['lists' => $lists, 'cards' => $cards];
        }

        $lists = array_values(array_filter($lists, static function (array $list) use ($hidden): bool {
            return !isset($hidden[(int) $list['id']]);
        }));
        $cards = array_values(array_filter($cards, static function (array $card) use ($hidden): bool {
            return !isset($hidden[(int) ($card['plugin_tralla_lists_id'] ?? 0)]);
        }));

        return ['lists' => $lists, 'cards' => $cards];
    }
}

/* ------------------------------------------------------------------ prioridade e SLA */

if (!function_exists('tralla_priorities')) {
    /**
     * Prioridades e SLAs dos cards de manutenção.
     * Prazos em horas ('h') são corridos; em dias ('bd') são dias úteis (seg–sex).
     */
    function tralla_priorities(): array
    {
        return [
            'P1' => ['label' => 'P1 · Alta',      'name' => 'Alta',       'color' => '#d93025',
                'first' => ['h' => 0.5], 'palliative' => ['h' => 4],    'definitive' => ['h' => 8]],
            'P2' => ['label' => 'P2 · Média',     'name' => 'Média',      'color' => '#f29900',
                'first' => ['h' => 4],   'palliative' => ['bd' => 1],   'definitive' => ['bd' => 3]],
            'P3' => ['label' => 'P3 · Baixa',     'name' => 'Baixa',      'color' => '#2e7d32',
                'first' => ['h' => 8],   'palliative' => ['bd' => 2],   'definitive' => ['bd' => 4]],
            'P4' => ['label' => 'P4 · Mínima',    'name' => 'Mínima',     'color' => '#5f6368',
                'first' => ['bd' => 5],  'palliative' => ['bd' => 15],  'definitive' => ['bd' => 15]],
            'P5' => ['label' => 'P5 · Inventário', 'name' => 'Inventário', 'color' => '#1a4fa0',
                'first' => ['bd' => 14], 'palliative' => ['bd' => 60],  'definitive' => ['bd' => 60]],
        ];
    }
}

if (!function_exists('tralla_valid_priority')) {
    function tralla_valid_priority(string $priority): bool
    {
        return array_key_exists($priority, tralla_priorities());
    }
}

if (!function_exists('tralla_sla_span_label')) {
    function tralla_sla_span_label(array $span): string
    {
        if (isset($span['h'])) {
            $hours = (float) $span['h'];
            if ($hours < 1) {
                return (int) round($hours * 60) . ' min';
            }
            return rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ',') . ' h';
        }
        $days = (int) ($span['bd'] ?? 0);
        return $days . ($days === 1 ? ' dia útil' : ' dias úteis');
    }
}

if (!function_exists('tralla_sla_deadline')) {
    function tralla_sla_deadline(string $start, array $span): string
    {
        $time = strtotime($start);
        if ($time === false) {
            return '';
        }
        if (isset($span['h'])) {
            return date('Y-m-d H:i:s', $time + (int) round((float) $span['h'] * 3600));
        }
        $days = (int) ($span['bd'] ?? 0);
        $date = new DateTime(date('Y-m-d H:i:s', $time));
        while ($days > 0) {
            $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $days--;
            }
        }
        return $date->format('Y-m-d H:i:s');
    }
}

if (!function_exists('tralla_card_sla')) {
    /**
     * SLA de um card principal (etapa 1) de manutenção/chamado.
     * 1º atendimento = técnico clicou em Pegar; resposta paliativa = técnico finalizou
     * (enviou para aprovação); resposta definitiva = finalização aprovada.
     */
    function tralla_card_sla(array $card): ?array
    {
        $priority = (string) ($card['priority'] ?? '');
        $start = (string) ($card['date_sla_start'] ?? '');
        $priorities = tralla_priorities();
        if ($priority === '' || !isset($priorities[$priority]) || (int) ($card['stage'] ?? 1) !== 1) {
            return null;
        }
        $spec = $priorities[$priority];
        $status = (string) ($card['status'] ?? '');
        $finishedDefinitive = in_array($status, ['withdrawal', 'done'], true);
        $metAt = [
            'first'      => (string) ($card['date_taken'] ?? ''),
            'palliative' => (string) ($card['date_finalized'] ?? ''),
            'definitive' => $finishedDefinitive ? (string) ($card['date_approved'] ?? '') : '',
        ];
        if ($finishedDefinitive && $metAt['palliative'] === '') {
            $metAt['palliative'] = $metAt['definitive'];
        }
        $titles = [
            'first'      => '1º atendimento',
            'palliative' => 'Resposta paliativa',
            'definitive' => 'Resposta definitiva',
        ];
        $now = tralla_now();
        $steps = [];
        $next = null;
        foreach ($titles as $key => $title) {
            $deadline = $start !== '' ? tralla_sla_deadline($start, $spec[$key]) : '';
            $met = $metAt[$key] !== '' ? $metAt[$key] : '';
            if ($start === '') {
                $state = 'waiting';
            } elseif ($met !== '') {
                $state = strtotime($met) <= strtotime($deadline) ? 'met' : 'late_met';
            } else {
                $state = strtotime($now) > strtotime($deadline) ? 'overdue' : 'running';
            }
            $step = [
                'key'      => $key,
                'title'    => $title,
                'span'     => tralla_sla_span_label($spec[$key]),
                'deadline' => $deadline,
                'met_at'   => $met,
                'state'    => $state,
            ];
            if ($next === null && in_array($state, ['running', 'overdue'], true)) {
                $next = $step;
            }
            $steps[] = $step;
        }

        return [
            'priority' => $priority,
            'label'    => $spec['label'],
            'name'     => $spec['name'],
            'color'    => $spec['color'],
            'start'    => $start,
            'steps'    => $steps,
            'next'     => $next,
        ];
    }
}

if (!function_exists('tralla_priority_choices_for_js')) {
    function tralla_priority_choices_for_js(): array
    {
        $out = [];
        foreach (tralla_priorities() as $key => $spec) {
            $out[] = [
                'key'        => $key,
                'label'      => $spec['label'],
                'name'       => $spec['name'],
                'color'      => $spec['color'],
                'first'      => tralla_sla_span_label($spec['first']),
                'palliative' => tralla_sla_span_label($spec['palliative']),
                'definitive' => tralla_sla_span_label($spec['definitive']),
            ];
        }
        return $out;
    }
}
