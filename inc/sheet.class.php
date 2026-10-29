<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/** Exporta os cards principais de um quadro para a planilha SEINTEC. */
class PluginTrallaSheetSync
{
    private const SHEET_ID = '1FOViixQ3jH2QwTOzzJglUDaWXf1cxfjvxxbvYI7UMMU';

    public static function syncIfDue(): int
    {
        if (!PluginTrallaConfig::getBool('sheet_sync_enabled')
            || PluginTrallaConfig::getInt('sheet_board_id') <= 0) {
            return 0;
        }
        $last = PluginTrallaConfig::getInt('sheet_last_attempt');
        if ($last > time() - 1800) { return 0; }
        PluginTrallaConfig::set('sheet_last_attempt', (string) time());
        try {
            return self::sync();
        } catch (Throwable $e) {
            PluginTrallaConfig::set('sheet_last_error', mb_substr($e->getMessage(), 0, 500));
            error_log('[Tralla] Sincronização SEINTEC: ' . $e->getMessage());
            return 0;
        }
    }

    public static function sync(): int
    {
        global $DB;
        $boardId = PluginTrallaConfig::getInt('sheet_board_id');
        $board = new PluginTrallaBoard();
        if ($boardId <= 0 || !$board->getFromDB($boardId)
            || (string) $board->fields['type'] !== 'maintenance') {
            throw new RuntimeException('Selecione um quadro de manutenção válido para a planilha.');
        }
        $tab = trim(PluginTrallaConfig::get('sheet_tab', 'Página1'));
        if ($tab === '' || mb_strlen($tab) > 100) {
            throw new RuntimeException('Informe o nome da aba da planilha.');
        }
        $path = realpath(PluginTrallaConfig::get('sheet_credentials_path'));
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('O arquivo de credenciais Google não está acessível ao usuário do PHP.');
        }
        $glpiRoot = realpath(GLPI_ROOT);
        if ($glpiRoot !== false && str_starts_with($path, $glpiRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Guarde o arquivo de credenciais fora da pasta pública do GLPI.');
        }
        $credentials = json_decode((string) file_get_contents($path), true);
        if (!is_array($credentials) || ($credentials['type'] ?? '') !== 'service_account'
            || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('O JSON da conta de serviço Google é inválido.');
        }
        $token = self::accessToken($credentials);
        $range = "'" . str_replace("'", "''", $tab) . "'!A:H";
        $endpoint = 'https://sheets.googleapis.com/v4/spreadsheets/' . self::SHEET_ID . '/values/';
        $existing = self::request('GET', $endpoint . rawurlencode($range), $token);
        $rows = (array) ($existing['values'] ?? []);
        $byCode = [];
        foreach ($rows as $index => $row) {
            if ($index === 0) { continue; }
            $code = trim((string) ($row[0] ?? ''));
            if ($code !== '' && !isset($byCode[$code])) { $byCode[$code] = $index + 1; }
        }

        $updates = [];
        $appends = [];
        $pausedList = PluginTrallaBoard::findListByCategory($boardId, 'maintenance_paused');
        $cards = iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_tralla_cards',
            'WHERE' => [
                'plugin_tralla_boards_id' => $boardId,
                'stage' => 1,
                'is_archived' => 0,
                'is_deleted' => 0,
            ],
            'ORDER' => 'code_number ASC, id ASC',
        ]));
        $machinesByCard = [];
        $modelIds = [];
        $cardIds = array_map(static fn(array $card): int => (int) $card['id'], $cards);
        if ($cardIds !== []) {
            foreach ($DB->request([
                'FROM' => 'glpi_plugin_tralla_machines',
                'WHERE' => ['plugin_tralla_cards_id' => $cardIds],
                'ORDER' => 'rank ASC, id ASC',
            ]) as $machine) {
                $machinesByCard[(int) $machine['plugin_tralla_cards_id']][] = $machine;
                $modelId = (int) ($machine['plugin_tralla_machine_models_id'] ?? 0);
                if ($modelId > 0) { $modelIds[$modelId] = $modelId; }
            }
        }
        $modelNames = PluginTrallaMachineModel::getModelNames(array_values($modelIds));
        foreach ($cards as $card) {
            $code = tralla_display_code($card, 'maintenance');
            $status = (string) $card['status'];
            $isPaused = !empty($card['is_paused'])
                || ($pausedList !== null && (int) $card['plugin_tralla_lists_id'] === (int) $pausedList['id']);
            $label = $isPaused ? 'Em andamento'
                : ($status === 'withdrawal' ? 'Concluido' : tralla_status_label($status));
            $kind = (string) ($card['workflow_type'] ?? 'maintenance');
            $description = $kind === 'call'
                ? (string) ($card['description'] ?? '')
                : str_replace(' · ', ' | ', PluginTrallaMachine::summarizeModels(
                    $machinesByCard[(int) $card['id']] ?? [], $modelNames
                ));
            $record = [
                $code,
                self::formatDate((string) ($card['date_taken'] ?? '')),
                self::formatDate((string) ($card['date_finalized'] ?? '')),
                $kind === 'call' ? (string) ($card['call_category'] ?? '') : 'Equipamentos',
                $label,
                $description,
                (string) $card['name'],
                tralla_user_name((int) ($card['technician_id'] ?? 0)),
            ];
            if (isset($byCode[$code])) {
                $rowNumber = $byCode[$code];
                $current = array_pad(array_map('strval', array_slice(
                    (array) ($rows[$rowNumber - 1] ?? []), 0, 8
                )), 8, '');
                if ($current !== $record) {
                    $updates[] = ['range' => "'" . str_replace("'", "''", $tab) . "'!A{$rowNumber}:H{$rowNumber}",
                        'values' => [$record]];
                }
            } else {
                $appends[] = $record;
                $byCode[$code] = -1;
            }
        }

        if ($updates !== []) {
            self::request('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . self::SHEET_ID
                . '/values:batchUpdate', $token,
                ['valueInputOption' => 'RAW', 'data' => $updates]);
        }
        if ($appends !== []) {
            self::request('POST', $endpoint . rawurlencode($range) . ':append?valueInputOption=RAW'
                . '&insertDataOption=INSERT_ROWS', $token,
                ['majorDimension' => 'ROWS', 'values' => $appends]);
        }
        PluginTrallaConfig::set('sheet_last_success', (string) time());
        PluginTrallaConfig::set('sheet_last_error', '');
        return count($updates) + count($appends);
    }

    private static function formatDate(string $value): string
    {
        $time = $value !== '' ? strtotime($value) : false;
        return $time === false ? '' : date('d/m/Y', $time);
    }

    private static function accessToken(array $credentials): string
    {
        $now = time();
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $encode([
            'iss' => (string) $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $unsigned = $header . '.' . $claims;
        if (!openssl_sign($unsigned, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Não foi possível assinar a autenticação Google.');
        }
        $jwt = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $result = self::request('POST', 'https://oauth2.googleapis.com/token', '', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ], true);
        $token = (string) ($result['access_token'] ?? '');
        if ($token === '') { throw new RuntimeException('Google não retornou um token de acesso.'); }
        return $token;
    }

    private static function request(string $method, string $url, string $token, ?array $body = null,
        bool $form = false): array
    {
        $handle = curl_init($url);
        if ($handle === false) { throw new RuntimeException('Não foi possível iniciar a conexão Google.'); }
        $headers = ['Accept: application/json'];
        if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
            curl_setopt($handle, CURLOPT_POSTFIELDS, $form
                ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) { throw new RuntimeException('Conexão com Google falhou: ' . $error); }
        $data = json_decode((string) $response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['error_description'] ?? '') : '';
            throw new RuntimeException('Google Sheets retornou HTTP ' . $status . ': ' . mb_substr($message, 0, 200));
        }
        return is_array($data) ? $data : [];
    }
}
