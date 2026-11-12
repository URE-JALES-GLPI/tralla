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
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $name = trim((string) $row['name']);
            if ($id > 0 && $name !== '' && !isset($parents[$id])) {
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
