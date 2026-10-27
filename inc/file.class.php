<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/** Arquivos privados vinculados aos cards de Chamado e Atualizações. */
class PluginTrallaFile
{
    public const TABLE = 'glpi_plugin_tralla_files';
    public const MAX_SIZE = 104_857_600;
    public const CHUNK_SIZE = 1_048_576;

    private static function linkedCard(int $cardId): array
    {
        $card = PluginTrallaCard::getCard($cardId);
        if ($card === null || (int) ($card['stage'] ?? 0) !== 1
            || !empty($card['is_deleted'])) {
            throw new RuntimeException('Card não encontrado.');
        }
        $boardType = PluginTrallaCard::getBoardType((int) $card['plugin_tralla_boards_id']);
        if (!(($boardType === 'maintenance' && (string) ($card['workflow_type'] ?? '') === 'call')
            || ($boardType === 'updates' && (string) ($card['workflow_type'] ?? '') === 'update'))) {
            throw new RuntimeException('Este tipo de card não aceita arquivos.');
        }
        if (!PluginTrallaBoard::canViewBoard((int) $card['plugin_tralla_boards_id'])) {
            throw new RuntimeException('Você não tem acesso a este card.');
        }
        return $card;
    }

    public static function canUpload(array $card, int $userId): bool
    {
        $workflow = (string) ($card['workflow_type'] ?? '');
        $role = PluginTrallaBoard::roleFor((int) ($card['plugin_tralla_boards_id'] ?? 0), $userId);
        $updateAllowed = $workflow !== 'update'
            || (in_array((string) ($card['status'] ?? ''), ['update_future', 'update_pending'], true)
                && ((int) ($card['users_id'] ?? 0) === $userId || tralla_role_at_least($role, 'admin')))
            || ((string) ($card['status'] ?? '') === 'update_in_progress'
                && (int) ($card['technician_id'] ?? 0) === $userId);
        return (int) ($card['stage'] ?? 0) === 1
            && in_array((string) ($card['workflow_type'] ?? ''), ['call', 'update'], true)
            && $updateAllowed
            && empty($card['is_archived'])
            && empty($card['is_deleted'])
            && (string) ($card['status'] ?? '') !== 'deletion_pending'
            && Session::haveRight('plugin_tralla', UPDATE)
            && $role !== '';
    }

    private static function validateItem(array $card, int $itemId): void
    {
        global $DB;
        $isUpdate = (string) ($card['workflow_type'] ?? '') === 'update';
        $mode = (string) ($card['update_mode'] ?? '');
        if (!$isUpdate || $mode === 'description') {
            if ($itemId !== 0) {
                throw new RuntimeException('Este arquivo deve ser vinculado diretamente ao card.');
            }
            return;
        }
        if ($mode !== 'checklist' || $itemId <= 0) {
            throw new RuntimeException('Selecione um item válido da checklist para o arquivo.');
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_tralla_update_items',
            'WHERE' => ['id' => $itemId, 'plugin_tralla_cards_id' => (int) $card['id']],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            throw new RuntimeException('Item da checklist não encontrado neste card.');
        }
    }

    private static function assertAvailable(): void
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            throw new RuntimeException('Atualize a instalação do Tralla para habilitar os arquivos vinculados.');
        }
    }

    private static function baseDir(bool $create = false): string
    {
        if (!defined('GLPI_PLUGIN_DOC_DIR')) {
            throw new RuntimeException('O armazenamento privado dos plugins não está configurado.');
        }
        $dir = rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') . '/tralla/files';
        if ($create && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta privada dos arquivos.');
        }
        return $dir;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $name));
        return $name !== '' && $name !== '.' && $name !== '..'
            ? mb_substr($name, 0, 255) : 'arquivo';
    }

    private static function rowForAccess(int $fileId): array
    {
        global $DB;
        self::assertAvailable();
        $row = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['id' => $fileId],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            throw new RuntimeException('Arquivo não encontrado.');
        }
        self::linkedCard((int) $row['plugin_tralla_cards_id']);
        return $row;
    }

    public static function listForCard(int $cardId, int $userId): array
    {
        global $DB;
        $card = self::linkedCard($cardId);
        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }
        $role = PluginTrallaBoard::roleFor((int) $card['plugin_tralla_boards_id'], $userId);
        $canEdit = self::canUpload($card, $userId);
        $canManage = $canEdit && tralla_role_at_least($role, 'admin');
        $result = [];
        foreach ($DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['plugin_tralla_cards_id' => $cardId],
            'ORDER' => 'id DESC',
        ]) as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['original_name'],
                'size' => (int) $row['file_size'],
                'item_id' => (int) ($row['plugin_tralla_update_items_id'] ?? 0),
                'uploaded_by' => tralla_user_name((int) $row['users_id']),
                'date_creation' => (string) $row['date_creation'],
                'url' => tralla_web_dir('tralla') . '/front/call_file.php?id=' . (int) $row['id'],
                'can_delete' => $canEdit
                    && ($canManage || (int) $row['users_id'] === $userId),
            ];
        }
        return $result;
    }

    /** Recebe até 1 MiB por requisição para funcionar mesmo com o limite padrão do PHP. */
    public static function uploadChunk(
        int $cardId,
        int $userId,
        string $uploadId,
        int $index,
        int $count,
        int $fileSize,
        string $fileName,
        array $file,
        int $itemId = 0
    ): array {
        global $DB;
        self::assertAvailable();
        $card = self::linkedCard($cardId);
        if (!self::canUpload($card, $userId)) {
            throw new RuntimeException('Sem permissão para vincular arquivos a este card.');
        }
        self::validateItem($card, $itemId);
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)
            || $fileSize < 1 || $fileSize > self::MAX_SIZE
            || $count !== (int) ceil($fileSize / self::CHUNK_SIZE)
            || $index < 0 || $index >= $count) {
            throw new RuntimeException('Arquivo ou envio inválido. O limite é 100 MB por arquivo.');
        }
        $expectedSize = min(self::CHUNK_SIZE, $fileSize - $index * self::CHUNK_SIZE);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))
            || (int) ($file['size'] ?? 0) !== $expectedSize) {
            throw new RuntimeException('Parte do arquivo não recebida. Verifique os limites de upload do PHP.');
        }

        $name = self::cleanName($fileName);
        $base = self::baseDir(true);
        if ($index === 0) {
            self::cleanupAbandonedUploads($base);
        }
        $dir = $base . '/tmp-' . $uploadId;
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível iniciar o envio do arquivo.');
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Não foi possível bloquear o envio do arquivo.');
        }
        $completed = false;
        try {
            $manifestPath = $dir . '/manifest.json';
            $manifest = is_file($manifestPath)
                ? json_decode((string) file_get_contents($manifestPath), true) : null;
            if ($manifest === null && $index === 0) {
                $manifest = [
                    'card_id' => $cardId, 'user_id' => $userId,
                    'name' => $name, 'size' => $fileSize, 'count' => $count,
                    'item_id' => $itemId,
                ];
                if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Não foi possível registrar o envio do arquivo.');
                }
            }
            if (!is_array($manifest) || (int) ($manifest['card_id'] ?? 0) !== $cardId
                || (int) ($manifest['user_id'] ?? 0) !== $userId
                || (string) ($manifest['name'] ?? '') !== $name
                || (int) ($manifest['size'] ?? 0) !== $fileSize
                || (int) ($manifest['count'] ?? 0) !== $count
                || (int) ($manifest['item_id'] ?? 0) !== $itemId) {
                throw new RuntimeException('Este envio não pertence ao card ou usuário informado.');
            }

            if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $index . '.part')) {
                throw new RuntimeException('Não foi possível salvar uma parte do arquivo.');
            }
            @chmod($dir . '/' . $index . '.part', 0600);
            if ($index !== $count - 1) {
                return ['complete' => false, 'received' => $index + 1, 'total' => $count];
            }

            $storedName = bin2hex(random_bytes(16)) . '.bin';
            $path = $base . '/' . $storedName;
            $output = fopen($path, 'x+b');
            if ($output === false) {
                throw new RuntimeException('Não foi possível finalizar o arquivo.');
            }
            $written = 0;
            try {
                for ($part = 0; $part < $count; $part++) {
                    $partPath = $dir . '/' . $part . '.part';
                    $input = is_file($partPath) ? fopen($partPath, 'rb') : false;
                    if ($input === false) {
                        throw new RuntimeException('Falta uma parte do arquivo. Tente enviar novamente.');
                    }
                    try {
                        $copied = stream_copy_to_stream($input, $output);
                        $expected = min(self::CHUNK_SIZE, $fileSize - $part * self::CHUNK_SIZE);
                        if ($copied !== $expected) {
                            throw new RuntimeException('O arquivo recebido está incompleto.');
                        }
                        $written += $copied;
                    } finally {
                        fclose($input);
                    }
                }
            } catch (Throwable $e) {
                fclose($output);
                @unlink($path);
                throw $e;
            }
            fclose($output);
            if ($written !== $fileSize) {
                @unlink($path);
                throw new RuntimeException('O tamanho do arquivo recebido não confere.');
            }
            @chmod($path, 0600);

            $DB->beginTransaction();
            try {
                $DB->doQuery('SELECT `id` FROM `glpi_plugin_tralla_cards` WHERE `id` = '
                    . $cardId . ' FOR UPDATE');
                $freshCard = self::linkedCard($cardId);
                if (!self::canUpload($freshCard, $userId)) {
                    throw new RuntimeException('Este card não aceita mais arquivos.');
                }
                self::validateItem($freshCard, $itemId);
                if ($itemId > 0) {
                    $DB->doQuery('SELECT `id` FROM `glpi_plugin_tralla_update_items` WHERE `id` = '
                        . $itemId . ' FOR UPDATE');
                    self::validateItem($card, $itemId);
                    if (countElementsInTable(self::TABLE, ['plugin_tralla_update_items_id' => $itemId]) >= 10) {
                        throw new RuntimeException('Cada item da checklist aceita até 10 arquivos.');
                    }
                }
                $inserted = $DB->insert(self::TABLE, [
                    'plugin_tralla_cards_id' => $cardId,
                    'plugin_tralla_update_items_id' => $itemId,
                    'original_name' => $name,
                    'stored_name' => $storedName,
                    'file_size' => $fileSize,
                    'users_id' => $userId,
                    'date_creation' => tralla_now(),
                ]);
                if (!$inserted) {
                    throw new RuntimeException('Não foi possível vincular o arquivo ao card.');
                }
                $fileId = (int) $DB->insertId();
                PluginTrallaHistory::log($cardId, $userId, 'file_added', 'Arquivo vinculado: ' . $name);
                $DB->commit();
            } catch (Throwable $e) {
                $DB->rollBack();
                @unlink($path);
                throw $e;
            }
            $completed = true;
            return ['complete' => true, 'file_id' => $fileId];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($completed) {
                for ($part = 0; $part < $count; $part++) {
                    @unlink($dir . '/' . $part . '.part');
                }
                @unlink($dir . '/manifest.json');
                @unlink($dir . '/.lock');
                @rmdir($dir);
            }
        }
    }

    private static function cleanupAbandonedUploads(string $base): void
    {
        foreach (new DirectoryIterator($base) as $entry) {
            if (!$entry->isDir() || $entry->isLink()
                || !preg_match('/^tmp-[a-f0-9]{32}$/', $entry->getFilename())
                || $entry->getMTime() >= time() - 86400) {
                continue;
            }
            $dir = $entry->getPathname();
            foreach (new DirectoryIterator($dir) as $part) {
                if ($part->isFile() && preg_match('/^(?:\d+\.part|manifest\.json|\.lock)$/', $part->getFilename())) {
                    @unlink($part->getPathname());
                }
            }
            @rmdir($dir);
        }
    }

    public static function download(int $fileId): array
    {
        $row = self::rowForAccess($fileId);
        $storedName = (string) $row['stored_name'];
        if (!preg_match('/^[a-f0-9]{32}\.bin$/', $storedName)) {
            throw new RuntimeException('Arquivo inválido.');
        }
        $path = self::baseDir() . '/' . $storedName;
        if (!is_file($path) || !is_readable($path) || filesize($path) !== (int) $row['file_size']) {
            throw new RuntimeException('Arquivo indisponível no armazenamento.');
        }
        return ['path' => $path, 'name' => (string) $row['original_name'], 'size' => (int) $row['file_size']];
    }

    public static function delete(int $fileId, int $userId): int
    {
        global $DB;
        $row = self::rowForAccess($fileId);
        $cardId = (int) $row['plugin_tralla_cards_id'];
        $card = self::linkedCard($cardId);
        $role = PluginTrallaBoard::roleFor((int) $card['plugin_tralla_boards_id'], $userId);
        if (!self::canUpload($card, $userId)
            || ((int) $row['users_id'] !== $userId && !tralla_role_at_least($role, 'admin'))) {
            throw new RuntimeException('Sem permissão para remover este arquivo.');
        }
        $storedName = (string) $row['stored_name'];
        if (!preg_match('/^[a-f0-9]{32}\.bin$/', $storedName)) {
            throw new RuntimeException('Arquivo inválido.');
        }
        if (!$DB->delete(self::TABLE, ['id' => $fileId])) {
            throw new RuntimeException('Não foi possível remover o arquivo.');
        }
        @unlink(self::baseDir() . '/' . $storedName);
        PluginTrallaHistory::log($cardId, $userId, 'file_deleted', 'Arquivo removido: ' . (string) $row['original_name']);
        return $cardId;
    }

    /** Remove metadados e arquivos privados quando cards são eliminados definitivamente. */
    public static function purgeForCards(array $cardIds): void
    {
        global $DB;
        $cardIds = array_values(array_filter(array_map('intval', $cardIds)));
        if ($cardIds === []) {
            return;
        }
        if ($DB->tableExists(self::TABLE)) {
            $rows = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'stored_name'],
                'FROM' => self::TABLE,
                'WHERE' => ['plugin_tralla_cards_id' => $cardIds],
            ]) as $row) {
                $rows[] = $row;
            }
            foreach ($rows as $row) {
                $name = (string) $row['stored_name'];
                if ($DB->delete(self::TABLE, ['id' => (int) $row['id']])
                    && preg_match('/^[a-f0-9]{32}\.bin$/', $name)) {
                    @unlink(self::baseDir() . '/' . $name);
                }
            }
        }
        if ($DB->tableExists('glpi_plugin_tralla_update_items')) {
            $DB->delete('glpi_plugin_tralla_update_items', ['plugin_tralla_cards_id' => $cardIds]);
        }
    }
}
