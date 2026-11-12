<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Formulário de Preventiva (Apps Script + GitHub Pages, ver appscript/preventiva).
 * O Google não acessa o GLPI: o Tralla envia a lista de escolas (entidades) para o script.
 */
class PluginTrallaPreventiva
{
    /** Envia a lista no máximo uma vez por dia pelo cron. */
    private const SYNC_INTERVAL = 86400;

    public static function url(): string
    {
        return trim(PluginTrallaConfig::get('preventiva_url'));
    }

    public static function secret(): string
    {
        $encrypted = PluginTrallaConfig::get('preventiva_secret_encrypted');
        return $encrypted === '' ? '' : (string) (new GLPIKey())->decrypt($encrypted);
    }

    public static function isConfigured(): bool
    {
        return self::url() !== '' && PluginTrallaConfig::get('preventiva_secret_encrypted') !== '';
    }

    /** IDs de entidades que não aparecem no formulário (configurável; padrão 9 e 25). */
    public static function excludedIds(): array
    {
        $ids = array_map('intval', preg_split('/[^0-9]+/', PluginTrallaConfig::get('preventiva_exclude', '9,25')) ?: []);
        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    public static function saveExcluded(string $text): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $text) ?: []),
            static fn (int $id): bool => $id > 0)));
        PluginTrallaConfig::set('preventiva_exclude', implode(', ', $ids));
        PluginTrallaConfig::clearCache();
    }

    public static function saveConfig(string $url, string $secret, bool $removeSecret): void
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('~^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$~D', $url)) {
            throw new RuntimeException('A URL do formulário deve ser a do App da Web do Apps Script (termina em /exec).');
        }
        PluginTrallaConfig::set('preventiva_url', $url);
        if ($removeSecret) {
            PluginTrallaConfig::set('preventiva_secret_encrypted', '');
        } elseif (trim($secret) !== '') {
            if (!preg_match('~^[A-Za-z0-9]{32,128}$~D', trim($secret))) {
                throw new RuntimeException('O segredo do formulário é inválido.');
            }
            PluginTrallaConfig::set('preventiva_secret_encrypted', (new GLPIKey())->encrypt(trim($secret)));
        }
        PluginTrallaConfig::clearCache();
    }

    /**
     * Escolas = entidades do GLPI sem entidades filhas (as entidades-mãe, como a raiz, ficam fora).
     *
     * @return array<int, array{id:int, nome:string}>
     */
    public static function schools(): array
    {
        global $DB;

        $parents = [];
        $rows = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => 'glpi_entities']) as $row) {
            if ((int) $row['id'] > 0) {
                $parents[(int) $row['entities_id']] = true;
            }
            $rows[] = $row;
        }
        $out = [];
        $excluded = array_flip(self::excludedIds());
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $name = trim((string) $row['name']);
            if ($id > 0 && $name !== '' && !isset($parents[$id]) && !isset($excluded[$id])) {
                $out[] = ['id' => $id, 'nome' => $name];
            }
        }
        usort($out, static fn ($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
        return $out;
    }

    /** Envia a lista agora. Devolve quantas escolas o formulário recebeu. */
    public static function sendSchools(): int
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Informe a URL e o segredo do formulário de Preventiva.');
        }
        $schools = self::schools();
        if ($schools === []) {
            throw new RuntimeException('Nenhuma entidade encontrada para enviar.');
        }
        $result = self::call('escolas', ['escolas' => $schools]);
        PluginTrallaConfig::set('preventiva_last_sync', (string) time());
        PluginTrallaConfig::set('preventiva_last_error', '');
        return (int) ($result['total'] ?? 0);
    }

    /** Usado pelo cron do Tralla: reenvia a lista uma vez por dia. */
    public static function syncIfDue(): int
    {
        if (!self::isConfigured()
            || PluginTrallaConfig::getInt('preventiva_last_attempt') > time() - self::SYNC_INTERVAL) {
            return 0;
        }
        PluginTrallaConfig::set('preventiva_last_attempt', (string) time());
        try {
            return self::sendSchools() > 0 ? 1 : 0;
        } catch (Throwable $e) {
            PluginTrallaConfig::set('preventiva_last_error', mb_substr($e->getMessage(), 0, 500));
            error_log('[Tralla] Preventiva: ' . $e->getMessage());
            return 0;
        }
    }

    /** Quadro que recebe as respostas: o primeiro quadro Preventiva não arquivado. */
    public static function targetBoard(): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_boards',
            'WHERE' => ['type' => 'preventiva', 'is_archived' => 0],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? $row : null;
    }

    /** Busca as respostas novas no formulário e cria um card para cada uma. */
    public static function importResponses(): int
    {
        global $DB;

        if (!self::isConfigured()) {
            throw new RuntimeException('Informe a URL e o segredo do formulário de Preventiva.');
        }
        $board = self::targetBoard();
        if ($board === null) {
            throw new RuntimeException('Crie um quadro do tipo Preventiva para receber as respostas.');
        }
        $boardId = (int) $board['id'];
        $lists = PluginTrallaList::getListsForBoard($boardId);
        if ($lists === []) {
            throw new RuntimeException('O quadro Preventiva não tem listas.');
        }
        $inbox = $lists[0];
        $noIssue = $inbox;
        foreach ($lists as $list) {
            if (mb_strtolower(trim((string) $list['name'])) === 'sem problemas relatados') {
                $noIssue = $list;
            }
        }

        $result = self::call('respostas', ['limit' => 50]);
        $created = 0;
        $ack = [];
        foreach ((array) ($result['respostas'] ?? []) as $answer) {
            $responseId = preg_replace('/[^a-f0-9-]/i', '', (string) ($answer['id'] ?? ''));
            if ($responseId === '') {
                continue;
            }
            $marker = 'Resposta do formulário ' . $responseId;
            // Já importada (a confirmação anterior pode ter falhado): só confirma de novo.
            if (countElementsInTable('glpi_plugin_tralla_history', [
                'action' => 'preventiva_import', 'details' => ['LIKE', '%' . $responseId . '%'],
            ]) > 0) {
                $ack[] = $responseId;
                continue;
            }

            $entityId = (int) ($answer['escola_id'] ?? 0);
            $entity = $entityId > 0
                ? $DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entityId], 'LIMIT' => 1])->current()
                : null;
            $school = trim((string) ($answer['escola'] ?? '')) ?: (is_array($entity) ? (string) $entity['name'] : 'Escola');
            $hasProblems = !empty($answer['tem_problemas']);
            $received = strtotime((string) ($answer['recebido_em'] ?? '')) ?: time();
            $improvements = trim((string) ($answer['melhorias'] ?? ''));

            $description = 'Resposta do formulário de Preventiva em ' . date('d/m/Y H:i', $received) . ".\n\n"
                . 'Problemas de T.I.: ' . ($hasProblems ? 'Sim' : 'Não') . "\n";
            if ($hasProblems) {
                $description .= trim((string) ($answer['descricao'] ?? '')) . "\n";
            }
            $description .= "\nComo a equipe SEINTEC pode atender melhor:\n"
                . ($improvements !== '' ? $improvements : '(não respondeu)');

            $list = $hasProblems ? $inbox : $noIssue;
            $now = tralla_now();
            $DB->beginTransaction();
            try {
                $DB->insert('glpi_plugin_tralla_cards', [
                    'plugin_tralla_boards_id' => $boardId,
                    'plugin_tralla_lists_id'  => (int) $list['id'],
                    'entities_id'             => is_array($entity) ? (int) $entity['id'] : 0,
                    'name'                    => tralla_normalize_label($school),
                    'description'             => mb_substr($description, 0, 60000),
                    'rank'                    => PluginTrallaCard::nextRankForList((int) $list['id']),
                    'users_id'                => 0,
                    'technician_id'           => 0,
                    'stage'                   => 1,
                    'parent_cards_id'         => 0,
                    'approval_cards_id'       => 0,
                    'status'                  => 'normal',
                    'is_locked'               => 0,
                    'is_archived'             => 0,
                    'source'                  => 'manual',
                    'machines_total'          => 0,
                    'date_creation'           => $now,
                    'date_mod'                => $now,
                ]);
                $cardId = (int) $DB->insertId();
                PluginTrallaHistory::log($cardId, 0, 'preventiva_import', $marker . '.');
                $DB->commit();
            } catch (Throwable $e) {
                $DB->rollBack();
                throw $e;
            }
            $ack[] = $responseId;
            $created++;
        }
        if ($ack !== []) {
            self::call('ack', ['ids' => $ack]);
        }
        PluginTrallaConfig::set('preventiva_last_import', (string) time());
        PluginTrallaConfig::set('preventiva_last_error', '');

        return $created;
    }

    /** Cron: busca respostas a cada 5 minutos. */
    public static function importIfDue(): int
    {
        if (!self::isConfigured() || self::targetBoard() === null
            || PluginTrallaConfig::getInt('preventiva_last_import_attempt') > time() - 300) {
            return 0;
        }
        PluginTrallaConfig::set('preventiva_last_import_attempt', (string) time());
        try {
            return self::importResponses();
        } catch (Throwable $e) {
            PluginTrallaConfig::set('preventiva_last_error', mb_substr($e->getMessage(), 0, 500));
            error_log('[Tralla] Preventiva (respostas): ' . $e->getMessage());
            return 0;
        }
    }

    private static function call(string $action, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensão cURL do PHP não está disponível.');
        }
        $handle = curl_init(self::url());
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['secret' => self::secret(), 'action' => $action] + $payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            // O Apps Script responde com redirecionamento para o resultado.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($body === false) {
            throw new RuntimeException('Não foi possível falar com o formulário: ' . $error);
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('O formulário respondeu algo inesperado (HTTP ' . $status . '). Confira a URL e se a implantação está como "qualquer pessoa".');
        }
        if (empty($json['ok'])) {
            throw new RuntimeException('Formulário: ' . (string) ($json['error'] ?? 'erro desconhecido'));
        }
        return $json;
    }
}
