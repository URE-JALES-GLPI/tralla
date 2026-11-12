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

    /** Garante a coluna do mês no quadro (cria sozinha se a atualização do plugin ainda não foi aplicada). */
    public static function ensurePeriodColumn(): void
    {
        global $DB;
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (!$DB->fieldExists('glpi_plugin_tralla_boards', 'period')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tralla_boards` ADD COLUMN `period` VARCHAR(7) DEFAULT NULL COMMENT 'Preventiva: mês das respostas (AAAA-MM)'");
            // Quadros já existentes assumem o mês em que foram criados.
            $DB->doQuery("UPDATE `glpi_plugin_tralla_boards` SET `period` = DATE_FORMAT(`date_creation`, '%Y-%m')"
                . " WHERE `type` = 'preventiva' AND (`period` IS NULL OR `period` = '')");
        }
    }

    /** "2026-11" válido ou ''. */
    public static function normalizePeriod(string $period): string
    {
        return preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', trim($period)) === 1 ? trim($period) : '';
    }

    /** "2026-11" -> "11/2026". */
    public static function periodLabel(string $period): string
    {
        $period = self::normalizePeriod($period);
        return $period === '' ? '' : substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    /** Quadros Preventiva não arquivados, do mais recente para o mais antigo. */
    public static function boards(): array
    {
        global $DB;
        self::ensurePeriodColumn();
        $out = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_boards',
            'WHERE' => ['type' => 'preventiva', 'is_archived' => 0],
            'ORDER' => ['period DESC', 'id DESC'],
        ]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Quadro de um mês (AAAA-MM). Sem mês informado: o do mês atual; se não houver, o mais recente.
     */
    public static function targetBoard(?string $period = null): ?array
    {
        $boards = self::boards();
        if ($boards === []) {
            return null;
        }
        $wanted = $period !== null ? self::normalizePeriod($period) : date('Y-m');
        foreach ($boards as $board) {
            if ((string) $board['period'] === $wanted) {
                return $board;
            }
        }
        return $period !== null ? null : $boards[0];
    }

    /** Resposta do formulário ligada ao card (campos separados) ou null. */
    public static function answerFor(int $cardId): ?array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_tralla_preventiva_answers')) {
            return null;
        }
        $row = $DB->request([
            'FROM' => 'glpi_plugin_tralla_preventiva_answers', 'WHERE' => ['plugin_tralla_cards_id' => $cardId], 'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            return null;
        }
        return [
            'answered_at'  => (string) $row['answered_at'],
            'has_problems' => (int) $row['has_problems'] === 1,
            'problems'     => (string) $row['problems_text'],
            'improvements' => (string) $row['improvements_text'],
        ];
    }

    /** Busca as respostas novas no formulário e cria um card para cada uma. */
    public static function importResponses(): int
    {
        global $DB;

        if (!self::isConfigured()) {
            throw new RuntimeException('Informe a URL e o segredo do formulário de Preventiva.');
        }
        // Cada quadro recebe só as respostas do mês dele (o formulário é reenviado todo mês).
        $byPeriod = [];
        foreach (self::boards() as $candidate) {
            $month = self::normalizePeriod((string) $candidate['period']);
            if ($month === '' || isset($byPeriod[$month])) {
                continue;
            }
            $boardLists = PluginTrallaList::getListsForBoard((int) $candidate['id']);
            if ($boardLists === []) {
                continue;
            }
            $noIssueList = $boardLists[0];
            foreach ($boardLists as $list) {
                if (mb_strtolower(trim((string) $list['name'])) === 'sem problemas relatados') {
                    $noIssueList = $list;
                }
            }
            $byPeriod[$month] = ['id' => (int) $candidate['id'], 'inbox' => $boardLists[0], 'noIssue' => $noIssueList];
        }
        if ($byPeriod === []) {
            throw new RuntimeException('Crie um quadro do tipo Preventiva (com o mês) para receber as respostas.');
        }

        $result = self::call('respostas', ['limit' => 50, 'meses' => array_keys($byPeriod)]);
        $created = 0;
        $ack = [];
        foreach ((array) ($result['respostas'] ?? []) as $answer) {
            $responseId = preg_replace('/[^a-f0-9-]/i', '', (string) ($answer['id'] ?? ''));
            if ($responseId === '') {
                continue;
            }
            $answerMonth = substr((string) ($answer['recebido_em'] ?? ''), 0, 7);
            if (!isset($byPeriod[$answerMonth])) {
                continue; // sem quadro para esse mês: a resposta fica na planilha até o quadro existir
            }
            $boardId = $byPeriod[$answerMonth]['id'];
            $inbox = $byPeriod[$answerMonth]['inbox'];
            $noIssue = $byPeriod[$answerMonth]['noIssue'];
            $marker = 'Resposta do formulário ' . $responseId;
            // Já importada e o card ainda existe (a confirmação anterior pode ter falhado): só confirma de novo.
            // Card excluído libera a resposta para ser importada outra vez.
            $alive = $DB->request([
                'COUNT'      => 'n',
                'FROM'       => 'glpi_plugin_tralla_history AS h',
                'INNER JOIN' => ['glpi_plugin_tralla_cards AS c' => ['ON' => ['c' => 'id', 'h' => 'plugin_tralla_cards_id']]],
                'WHERE'      => [
                    'h.action' => 'preventiva_import', 'h.details' => ['LIKE', '%' . $responseId . '%'],
                    'c.is_deleted' => 0,
                ],
            ])->current();
            if ((int) ($alive['n'] ?? 0) > 0) {
                $ack[] = $responseId;
                continue;
            }
            // Resposta de um card que foi excluído: remove o vínculo antigo para recriar.
            $DB->delete('glpi_plugin_tralla_preventiva_answers', ['response_id' => $responseId]);

            $entityId = (int) ($answer['escola_id'] ?? 0);
            $entity = $entityId > 0
                ? $DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entityId], 'LIMIT' => 1])->current()
                : null;
            $school = trim((string) ($answer['escola'] ?? '')) ?: (is_array($entity) ? (string) $entity['name'] : 'Escola');
            $hasProblems = !empty($answer['tem_problemas']);
            $received = strtotime((string) ($answer['recebido_em'] ?? '')) ?: time();
            $improvements = trim((string) ($answer['melhorias'] ?? ''));

            $problems = $hasProblems ? trim((string) ($answer['descricao'] ?? '')) : '';

            $list = $hasProblems ? $inbox : $noIssue;
            $now = tralla_now();
            $DB->beginTransaction();
            try {
                $DB->insert('glpi_plugin_tralla_cards', [
                    'plugin_tralla_boards_id' => $boardId,
                    'plugin_tralla_lists_id'  => (int) $list['id'],
                    'entities_id'             => is_array($entity) ? (int) $entity['id'] : 0,
                    'name'                    => tralla_normalize_label($school),
                    'description'             => '',
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
                $DB->insert('glpi_plugin_tralla_preventiva_answers', [
                    'plugin_tralla_cards_id' => $cardId,
                    'response_id'            => $responseId,
                    'answered_at'            => date('Y-m-d H:i:s', $received),
                    'has_problems'           => $hasProblems ? 1 : 0,
                    'problems_text'          => mb_substr($problems, 0, 60000),
                    'improvements_text'      => mb_substr($improvements, 0, 60000),
                    'date_creation'          => $now,
                ]);
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
        if (!self::isConfigured() || self::boards() === []
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
