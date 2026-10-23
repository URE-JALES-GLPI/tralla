<?php
require __DIR__ . '/../inc/domain.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: {$name}\n"); }
}

check('member is below admin', tralla_role_at_least('member', 'admin') === false);
check('admin reaches member', tralla_role_at_least('admin', 'member') === true);
check('superadmin reaches admin', tralla_role_at_least('superadmin', 'admin') === true);
check('invalid role normalizes empty', tralla_normalize_board_role('owner') === '');
check('role label', tralla_board_role_label('admin') === 'Admin');

check('backfill needs empty board', tralla_should_backfill_creator(0, 5));
check('backfill skips non-empty board', !tralla_should_backfill_creator(1, 5));
check('backfill skips missing creator', !tralla_should_backfill_creator(0, 0));

$f = tralla_parse_history_card_filter('123');
check('plain id', $f['ok'] && $f['type'] === 'all' && $f['id'] === 123);
$f = tralla_parse_history_card_filter('#123');
check('hash id', $f['ok'] && $f['id'] === 123);
$f = tralla_parse_history_card_filter('123/2');
check('stage id', $f['ok'] && $f['type'] === 'stage' && $f['stage'] === 2);
check('bad filter', tralla_parse_history_card_filter('abc')['ok'] === false);
$f = tralla_parse_history_card_filter('123/3');
check('any positive stage parsed', $f['ok'] && $f['type'] === 'stage' && $f['stage'] === 3);

$d = tralla_validate_history_dates('2026-10-01', '2026-10-22');
check('date end inclusive', $d['ok'] && $d['to'] === '2026-10-22 23:59:59');
check('bad date', tralla_validate_history_dates('2026-02-31', '')['ok'] === false);
check('inverted range', tralla_validate_history_dates('2026-10-22', '2026-10-01')['ok'] === false);

check('reason strips tags', tralla_normalize_deletion_reason('  <b>foo</b>  ') === 'foo');
check('reason max 2000', strlen(tralla_normalize_deletion_reason(str_repeat('a', 2001))) === 2000);
check('empty reason invalid', tralla_is_valid_deletion_reason('  ') === false);

$card = ['stage' => 1, 'is_deleted' => 0, 'status' => 'pending', 'is_locked' => 0];
check('member with delete can request', tralla_can_request_deletion($card, true, true, false));
check('member without delete cannot', !tralla_can_request_deletion($card, true, false, false));
check('purge can request', tralla_can_request_deletion($card, false, false, true));
check('stage 2 cannot', !tralla_can_request_deletion(['stage' => 2] + $card, true, true, false));
check('locked cannot', !tralla_can_request_deletion(['is_locked' => 1] + $card, true, true, false));

$lists = [['id' => 7, 'category' => ''], ['id' => 8, 'category' => 'maintenance_pending']];
check('restore previous list', tralla_restore_list_id(['deletion_previous_lists_id' => 7], $lists, 'normal') === 7);
check('restore normal fallback', tralla_restore_list_id(['deletion_previous_lists_id' => 99], $lists, 'normal') === 7);
check('restore maintenance fallback', tralla_restore_list_id(['deletion_previous_lists_id' => 99], $lists, 'maintenance') === 8);
check('restore no list', tralla_restore_list_id(['deletion_previous_lists_id' => 0], [], 'normal') === 0);
check('restore normal status fallback', tralla_restore_status(['deletion_previous_status' => ''], 'normal') === 'normal');
check('restore maintenance status fallback', tralla_restore_status(['deletion_previous_status' => ''], 'maintenance') === 'maintenance_pending');
check('restore previous status', tralla_restore_status(['deletion_previous_status' => 'in_progress'], 'normal') === 'in_progress');

check('member sees board', tralla_role_can_view(true, false, true, true));
check('purge without entity cannot see', !tralla_role_can_view(false, true, false, true));
check('no member no purge cannot see', !tralla_role_can_view(false, false, true, true));
check('member cannot manage board', !tralla_can_manage_members('member', true));
check('admin cannot manage members', !tralla_can_manage_members('admin', true));
check('superadmin manages members', tralla_can_manage_members('superadmin', true));
check('superadmin can govern', tralla_can_manage_members('superadmin', true));
check('admin cannot govern', !tralla_can_manage_members('admin', true));
check('view requires entity', !tralla_role_can_view(true, false, false, true));
check('view requires read', !tralla_role_can_view(true, false, true, false));
check('admin can restore with update', tralla_can_restore('admin', true));
check('admin cannot restore without update', !tralla_can_restore('admin', false));
check('member cannot restore', !tralla_can_restore('member', true));
check('superadmin can restore', tralla_can_restore('superadmin', true));
check('superadmin cannot be removed by pure rule', tralla_role_rank('superadmin') === 3);

$partition = tralla_finalization_partition([
    ['id' => 11, 'status' => 'done'],
    ['id' => 12, 'status' => 'pending'],
    ['id' => 13, 'status' => 'not_repairable'],
    ['id' => 14, 'status' => 'pending'],
]);
check('finalization keeps completed and unserviceable machines', $partition['ready'] === [11, 13]);
check('finalization groups all pending machines', $partition['pending'] === [12, 14]);
check('all pending machines cannot finalize', tralla_finalization_partition([
    ['id' => 15, 'status' => 'pending'],
])['ready'] === []);

check('restore lock dropped on status fallback',
    tralla_restore_is_locked(['deletion_previous_status' => '', 'deletion_previous_is_locked' => 1]) === 0);
check('restore lock kept for restorable status',
    tralla_restore_is_locked(['deletion_previous_status' => 'in_progress', 'deletion_previous_is_locked' => 1]) === 1);
check('plain zero rejected', tralla_parse_history_card_filter('0')['ok'] === false);
$f = tralla_parse_history_card_filter('123/1');
check('stage one parsed', $f['ok'] && $f['type'] === 'stage' && $f['stage'] === 1);
$d = tralla_validate_history_dates('', '');
check('empty dates valid', $d['ok'] === true && $d['from'] === '' && $d['to'] === '');

check('filter result keys', array_keys(tralla_parse_history_card_filter('1')) === ['ok', 'type', 'id', 'stage', 'error']);
check('date result keys', array_keys(tralla_validate_history_dates('', '')) === ['ok', 'from', 'to', 'error']);
check('whitespace stage filter', tralla_parse_history_card_filter('  #12/1  ')['ok'] === true);
check('negative id rejected', tralla_parse_history_card_filter('-1')['ok'] === false);
check('zero stage rejected', tralla_parse_history_card_filter('123/0')['ok'] === false);
check('extra slash rejected', tralla_parse_history_card_filter('123/1/2')['ok'] === false);
check('from start of day', tralla_validate_history_dates('2026-10-01', '')['from'] === '2026-10-01 00:00:00');
check('impossible day rejected', tralla_validate_history_dates('', '2026-04-31')['ok'] === false);

check('archived previous list falls back',
    tralla_restore_list_id(
        ['deletion_previous_lists_id' => 7],
        [['id' => 7, 'category' => '', 'is_archived' => 1], ['id' => 8, 'category' => '', 'is_archived' => 0]],
        'normal'
    ) === 8);
check('missing is_archived means active',
    tralla_restore_list_id([], [['id' => 10, 'category' => '']], 'normal') === 10);
check('exact 2000 reason valid', tralla_is_valid_deletion_reason(str_repeat('a', 2000)) === true);
check('deleted cannot request', !tralla_can_request_deletion(
    ['stage' => 1, 'is_deleted' => 1, 'status' => 'deleted', 'is_locked' => 0], true, true, false
));
check('deletion pending cannot request', !tralla_can_request_deletion(
    ['stage' => 1, 'is_deleted' => 0, 'status' => 'deletion_pending', 'is_locked' => 0], true, true, false
));

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
echo "OK\n";
