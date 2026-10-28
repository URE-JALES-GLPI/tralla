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
            'whatsapp_watch'   => 'Acompanhamento',
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
            ''                 => 'Em andamento',
            'pending'          => 'Pendente',
            'in_progress'      => 'Em andamento',
            'done'             => 'Concluído',
            'not_repairable'   => 'Inservível',
            'warranty'         => 'Garantia',
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

if (!function_exists('tralla_machine_service_done_allowed')) {
    function tralla_machine_service_done_allowed(string $status): bool
    {
        return in_array($status, ['pending', 'done', 'not_repairable', 'warranty'], true);
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
            'warranty'       => 'Garantia',
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
            'whatsapp'          => 'WhatsApp',
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

if (!function_exists('tralla_code_cache')) {
    /**
     * Cache por requisição usado pelo código de exibição.
     * $reset = true limpa (chamar após criar cards de separação).
     */
    function &tralla_code_cache(bool $reset = false): array
    {
        static $cache = ['split' => [], 'rows' => [], 'numbers' => []];
        if ($reset) {
            $cache = ['split' => [], 'rows' => [], 'numbers' => []];
        }
        return $cache;
    }
}

if (!function_exists('tralla_code_root_has_split')) {
    /** A raiz já foi separada (existe card principal com sequência > 1)? */
    function tralla_code_root_has_split(int $root): bool
    {
        global $DB;
        if ($root <= 0 || !isset($DB) || !is_object($DB)) {
            return false;
        }
        $cache = &tralla_code_cache();
        if (!array_key_exists($root, $cache['split'])) {
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_plugin_tralla_cards',
                'WHERE'  => [
                    'code_root_cards_id' => $root,
                    'stage'              => 1,
                    'family_seq'         => ['>', 1],
                ],
                'LIMIT'  => 1,
            ])->current();
            $cache['split'][$root] = is_array($row);
        }
        return $cache['split'][$root];
    }
}

if (!function_exists('tralla_code_number')) {
    /** Número visual do card raiz no quadro; sem número atribuído, o id interno. */
    function tralla_code_number(int $root, ?array $rootRow = null): int
    {
        global $DB;
        if ($root <= 0) {
            return 0;
        }
        $cache = &tralla_code_cache();
        if (is_array($rootRow) && (int) ($rootRow['id'] ?? 0) === $root && array_key_exists('code_number', $rootRow)) {
            $cache['numbers'][$root] = (int) $rootRow['code_number'];
        }
        if (!array_key_exists($root, $cache['numbers'])) {
            $row = isset($DB) && is_object($DB) ? $DB->request([
                'SELECT' => ['code_number'],
                'FROM'   => 'glpi_plugin_tralla_cards',
                'WHERE'  => ['id' => $root],
                'LIMIT'  => 1,
            ])->current() : null;
            $cache['numbers'][$root] = is_array($row) ? (int) $row['code_number'] : 0;
        }

        return $cache['numbers'][$root] > 0 ? $cache['numbers'][$root] : $root;
    }
}

if (!function_exists('tralla_display_code')) {
    /**
     * Código do card de manutenção: <raiz> normalmente; <raiz>/<seq> só quando
     * a raiz foi separada (3/1 é o card original e 3/2 o separado).
     * Cards de aprovação mostram o mesmo código do card de origem.
     * Quadros normais: #<id>.
     */
    function tralla_display_code(array $card, string $board_type = ''): string
    {
        global $DB;
        $stage = (int) ($card['stage'] ?? 1);
        $id = (int) ($card['id'] ?? 0);

        if ($stage === 2) {
            $parentId = (int) ($card['parent_cards_id'] ?? 0);
            if ($parentId > 0 && isset($DB) && is_object($DB)) {
                $cache = &tralla_code_cache();
                if (!array_key_exists($parentId, $cache['rows'])) {
                    $parent = $DB->request([
                        'SELECT' => ['id', 'stage', 'code_root_cards_id', 'family_cards_id', 'family_seq'],
                        'FROM'   => 'glpi_plugin_tralla_cards',
                        'WHERE'  => ['id' => $parentId],
                        'LIMIT'  => 1,
                    ])->current();
                    $cache['rows'][$parentId] = is_array($parent) ? $parent : null;
                }
                if (is_array($cache['rows'][$parentId]) && (int) $cache['rows'][$parentId]['stage'] === 1) {
                    return tralla_display_code($cache['rows'][$parentId], 'maintenance');
                }
            }
            $root = (int) ($card['code_root_cards_id'] ?? 0) ?: (int) ($card['family_cards_id'] ?? 0) ?: $parentId;
            return $root > 0 ? (string) tralla_code_number($root) : ($id > 0 ? '#' . $id : '');
        }

        $isMaintenance = $board_type === 'maintenance' || !empty($card['is_maintenance']);

        if ($isMaintenance && $id > 0) {
            $familyRoot = (int) ($card['code_root_cards_id'] ?? 0);
            if ($familyRoot <= 0) {
                $familyRoot = (int) ($card['family_cards_id'] ?? 0);
            }
            if ($familyRoot <= 0) {
                $familyRoot = $id;
            }
            $familySeq = max(1, (int) ($card['family_seq'] ?? 1));
            $number = tralla_code_number($familyRoot, $card);

            if ($familySeq > 1 || tralla_code_root_has_split($familyRoot)) {
                return $number . '/' . $familySeq;
            }
            return (string) $number;
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
            'whatsapp'    => 'Acompanhamento (WhatsApp)',
            'pause'       => 'Aprovação de pausa',
            'resume'      => 'Aprovação para despausar',
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

        return $report !== '' && $serviceDone && in_array($status, ['done', 'not_repairable', 'warranty', 'pending'], true);
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
        // Card pausado ou com pausa/despausa aguardando aprovação não avança no fluxo.
        $blocked = !empty($card['is_paused']) || (int) ($card['approval_cards_id'] ?? 0) > 0;

        if ($archived) {
            return false;
        }

        if ($boardType !== 'maintenance') {
            return false;
        }

        switch ($action) {
            case 'take':
                return $stage === 1 && $status === 'pending' && !$locked && !$blocked;
            case 'finalize':
                return $stage === 1 && $status === 'in_progress' && !$locked && !$blocked;
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
                $profile['name'] = tralla_person_name(
                    (string) ($user->fields['firstname'] ?? ''),
                    (string) ($user->fields['realname'] ?? ''),
                    (string) ($user->fields['name'] ?? '')
                );
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
        ];
    }
}

if (!function_exists('tralla_history_change')) {
    /** Detalhe do histórico com o texto anterior e o novo, sem resumir. */
    function tralla_history_change(string $field, string $old, string $new): string
    {
        $quote = static fn (string $text): string => trim($text) === '' ? '(vazio)' : '"' . trim($text) . '"';

        return $field . ' alterado de ' . $quote($old) . ' para ' . $quote($new) . '.';
    }
}

if (!function_exists('tralla_whatsapp_chat_ids')) {
    /** Atendimentos do card: os anteriores à transferência e o atual, em ordem. */
    function tralla_whatsapp_chat_ids(array $card): array
    {
        $ids = array_filter(array_map('intval', explode(',', (string) ($card['whatsapp_previous_chats'] ?? ''))));
        $current = (int) ($card['whatsapp_chats_id'] ?? 0);
        if ($current > 0) {
            $ids[] = $current;
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('tralla_whatsapp_live_transcript')) {
    /**
     * Conversa atual dos atendimentos do card (anteriores à transferência e o
     * atual, nessa ordem), montada pelo WhatsAppSimples.
     *
     * Lida só quando o card é aberto: nenhum custo extra no recebimento ou
     * envio de mensagens, e edições/exclusões já aparecem. Retorna null quando
     * o WhatsAppSimples não está ativo ou os atendimentos não existem mais.
     *
     * @param int[] $chatIds
     */
    function tralla_whatsapp_live_transcript(array $chatIds): ?string
    {
        $service = 'GlpiPlugin\Whatsappsimples\Service\TrallaIntegrationService';
        if ($chatIds === [] || !Plugin::isPluginActive('whatsappsimples') || !class_exists($service)
            || !method_exists($service, 'transcriptForChats')) {
            return null;
        }
        try {
            return (new $service())->transcriptForChats($chatIds);
        } catch (Throwable $e) {
            Toolbox::logInFile('tralla', 'Conversa do WhatsApp indisponível: ' . $e->getMessage() . PHP_EOL);
            return null;
        }
    }
}

if (!function_exists('tralla_whatsapp_send')) {
    /** Envia texto pela EvolutionAPI configurada no plugin WhatsAppSimples. */
    function tralla_whatsapp_send(string $phone, string $text): bool
    {
        global $DB;

        $number = tralla_whatsapp_number($phone);
        if ($number === '' || !function_exists('curl_init')
            || !$DB->tableExists('glpi_plugin_whatsappsimples_configs')) {
            return false;
        }
        $config = [];
        foreach ($DB->request([
            'SELECT' => ['name', 'value'],
            'FROM'   => 'glpi_plugin_whatsappsimples_configs',
            'WHERE'  => ['name' => ['server_url', 'api_token', 'instance_name']],
        ]) as $row) {
            $config[(string) $row['name']] = trim((string) $row['value']);
        }
        if (($config['server_url'] ?? '') === '' || ($config['api_token'] ?? '') === '' || ($config['instance_name'] ?? '') === '') {
            return false;
        }

        $ch = curl_init(rtrim($config['server_url'], '/') . '/message/sendText/' . rawurlencode($config['instance_name']));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $config['api_token']],
            CURLOPT_POSTFIELDS     => json_encode([
                'number'      => $number,
                'options'     => ['delay' => 1200, 'presence' => 'composing'],
                'text'        => $text,
                'textMessage' => ['text' => $text],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status >= 200 && $status < 300;
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
        // O tempo pausado (acumulado + pausa em curso) empurra os prazos para frente.
        $paused = !empty($card['is_paused']);
        $pausedSeconds = (int) ($card['paused_seconds'] ?? 0);
        if ($paused && !empty($card['paused_at'])) {
            $pausedSeconds += max(0, time() - (int) strtotime((string) $card['paused_at']));
        }
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
            if ($deadline !== '' && $pausedSeconds > 0) {
                $deadline = date('Y-m-d H:i:s', (int) strtotime($deadline) + $pausedSeconds);
            }
            $met = $metAt[$key] !== '' ? $metAt[$key] : '';
            if ($start === '') {
                $state = 'waiting';
            } elseif ($met !== '') {
                $state = strtotime($met) <= strtotime($deadline) ? 'met' : 'late_met';
            } elseif ($paused) {
                $state = 'paused';
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
            if ($next === null && in_array($state, ['running', 'overdue', 'paused'], true)) {
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
            'paused'   => $paused,
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
