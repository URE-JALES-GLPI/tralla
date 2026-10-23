<?php
$hook = file_get_contents(__DIR__ . '/../hook.php');
$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: {$name}\n"); }
}
check('boards_users table', str_contains($hook, 'glpi_plugin_tralla_boards_users'));
check('is_deleted column', str_contains($hook, '`is_deleted`'));
check('approval_kind column', str_contains($hook, '`approval_kind`'));
check('deletion_previous_lists_id column', str_contains($hook, '`deletion_previous_lists_id`'));
check('uninstall includes boards_users', str_contains($hook, "'glpi_plugin_tralla_boards_users'"));
check('creator backfill uses empty-board guard', str_contains($hook, 'tralla_should_backfill_creator'));
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
echo "OK\n";
