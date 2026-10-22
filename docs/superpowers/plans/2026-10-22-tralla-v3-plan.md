# Tralla v3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar papéis por quadro, aprovação por membros, histórico filtrável, exclusão com motivo/aprovação/restauração e **Pegar** no card minimizado.

**Architecture:** Aplicar as regras puras primeiro (`inc/domain.php`), depois persistência e ACL por quadro, depois domínio de exclusão, API, UI e documentação. A exclusão de manutenção reutiliza o card `/2` existente com `approval_kind='deletion'`; quadros normais usam soft delete direto. Nenhum card é apagado pela UI.

**Tech Stack:** PHP 8.2, GLPI 12, MySQL/MariaDB, JavaScript vanilla, CSS do plugin, testes PHP puros e harnesses CDP.

**Spec:** `docs/superpowers/specs/2026-10-22-tralla-v3-design.md`

## Global Constraints

- GLPI `>= 12.0.0` e `<= 12.0.99`; PHP `>= 8.2`.
- Nenhuma dependência nova de JavaScript ou PHP.
- Toda ação AJAX revalida CSRF, `plugin_tralla` `READ`, quadro e papel.
- Direitos globais são limite superior; o papel do quadro nunca concede além deles.
- `PURGE` global é `SuperAdmin` efetivo, mas ainda exige acesso à entidade do quadro.
- Somente `SuperAdmin` gerencia membros; o último `SuperAdmin` não pode ser removido nem rebaixado.
- `approval_group` deixa de ser consultado; a UI global de grupo aprovador é removida.
- Cards nunca são apagados pela UI; exclusão é soft delete com motivo.
- Exclusão em manutenção passa por `/2`; exclusão em quadro normal é direta.
- O fluxo de manutenção v2 (máquinas, aprovação, retirada, conclusão) não pode regredir.
- Commits: uma linha, português imperativo, sentence case, sem prefixo convencional, corpo ou emoji.
- Este workspace não tem PHP/MySQL/GLPI. Passos `php` devem ser executados no ambiente GLPI 12 de homologação; localmente, validar JS/CSS e registrar o bloqueio.

## Review Focus

- Usuário sem linha em `boards_users`, mas com `PURGE` global, deve ver o quadro somente se tiver acesso à entidade.
- Remoção/rebaixamento do último `SuperAdmin` deve falhar mesmo com duas requisições concorrentes.
- Card `deletion_pending` não pode aparecer no Kanban, nas estatísticas, nem duplicar no histórico.
- Restauração deve sobreviver à lista anterior removida/arquivada, caindo no fallback correto.
- Dois usuários pegando ou decidindo o mesmo card devem produzir uma única transição válida.

---

### Task 1: Pure domain rules and test harness

**Files:**
- Create: `inc/domain.php`
- Modify: `inc/helpers.php:6-10` (require `domain.php`)
- Create: `tests/run.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `tralla_role_rank(string $role): int`
  - `tralla_role_at_least(string $role, string $minimum): bool`
  - `tralla_normalize_board_role(string $role): string`
  - `tralla_board_role_labels(): array`
  - `tralla_board_role_label(string $role): string`
  - `tralla_parse_history_card_filter(string $value): array` with `ok`, `type`, `id`, `stage`, `error`
  - `tralla_validate_history_dates(string $from, string $to): array` with `ok`, `from`, `to`, `error`
  - `tralla_normalize_deletion_reason(string $reason): string`
  - `tralla_is_valid_deletion_reason(string $reason): bool`
  - `tralla_can_request_deletion(array $card, bool $isMember, bool $hasDelete, bool $isAdmin): bool`
  - `tralla_restore_list_id(array $card, array $lists, string $boardType): int`
  - `tralla_restore_status(array $card, string $boardType): string`
  - `tralla_restore_is_locked(array $card): int`
  - `tralla_role_can_view(bool $isMember, bool $isSuperAdmin, bool $hasEntityAccess, bool $hasRead): bool`
  - `tralla_can_manage_members(string $role, bool $hasUpdate): bool`
  - `tralla_can_restore(string $role, bool $hasUpdate): bool`

- [ ] **Step 1: Write the failing tests**

Create `tests/run.php` with this structure and assertions:

```php
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

$f = tralla_parse_history_card_filter('123');
check('plain id', $f['ok'] && $f['type'] === 'all' && $f['id'] === 123);
$f = tralla_parse_history_card_filter('#123');
check('hash id', $f['ok'] && $f['id'] === 123);
$f = tralla_parse_history_card_filter('123/2');
check('stage id', $f['ok'] && $f['type'] === 'stage' && $f['stage'] === 2);
check('bad filter', tralla_parse_history_card_filter('abc')['ok'] === false);
check('bad stage', tralla_parse_history_card_filter('123/3')['ok'] === false);

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
check('admin cannot manage members', !tralla_can_manage_members('admin', true));
check('superadmin manages members', tralla_can_manage_members('superadmin', true));
check('admin can restore', tralla_can_restore('admin', true));

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
echo "OK\n";
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL with `Call to undefined function tralla_role_at_least()`.

- [ ] **Step 3: Implement the pure functions in `inc/domain.php`**

Rules:

- `tralla_role_rank`: `member=1`, `admin=2`, `superadmin=3`, invalid `0`.
- `tralla_normalize_board_role`: trim/lowercase; return `''` for anything outside the three values.
- `tralla_parse_history_card_filter`: accept `123`, `#123`, `123/1`, `123/2`; reject `0`, negative, non-numeric, stage other than `1|2`.
- `tralla_validate_history_dates`: `Y-m-d` strict; empty is valid; end becomes `23:59:59`; `from > to` invalid.
- `tralla_normalize_deletion_reason`: `trim(strip_tags(...))`, max 2000 bytes/characters via `mb_substr` when available.
- `tralla_can_request_deletion`: stage 1, not deleted, status not `deletion_pending`, not locked, and (`isAdmin` or (`isMember` and `hasDelete`)).
- `tralla_restore_list_id`: previous list only if it exists in `$lists` and has no `is_archived`; maintenance fallback is the list with `category='maintenance_pending'`; normal fallback is the first active list; `0` if none.
- `tralla_restore_status`: previous status unless empty/`deleted`/`deletion_pending`; fallback `maintenance_pending` for maintenance boards, otherwise `normal`.
- `tralla_restore_is_locked`: previous value, but `0` when status fallback is used.
- `tralla_role_can_view`: `hasRead && hasEntityAccess && (isMember || isSuperAdmin)`.
- `tralla_can_manage_members`: `superadmin` + `UPDATE`.
- `tralla_can_restore`: `admin`/`superadmin` + `UPDATE`.

In `inc/helpers.php`, after the `GLPI_ROOT` guard, add:

```php
require_once __DIR__ . '/domain.php';
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: `OK`.

If PHP is unavailable, run `node --check tests/run.php` only if a PHP parser is installed; otherwise record the PHP step as blocked and continue with static review.

- [ ] **Step 5: Commit**

```bash
git add inc/domain.php inc/helpers.php tests/run.php
git commit -m "Adiciona regras puras de papéis e exclusão"
```

---

### Task 2: Schema and board membership storage

**Files:**
- Modify: `hook.php:9-242` (install/upgrade)
- Modify: `hook.php:255-284` (uninstall)
- Create: `inc/boarduser.class.php`
- Create: `tests/schema.php`

**Interfaces:**
- Consumes: `tralla_normalize_board_role()` from Task 1.
- Produces:
  - `PluginTrallaBoardUser::getMembers(int $boardId): array`
  - `PluginTrallaBoardUser::getRole(int $boardId, int $userId): string`
  - `PluginTrallaBoardUser::setRole(int $boardId, int $userId, string $role): void`
  - `PluginTrallaBoardUser::remove(int $boardId, int $userId): void`
  - `PluginTrallaBoardUser::countSuperadmins(int $boardId): int`
  - `PluginTrallaBoardUser::addCreator(int $boardId, int $userId): void`

- [ ] **Step 1: Write the failing schema test**

Create `tests/schema.php`:

```php
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
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
echo "OK\n";
```

- [ ] **Step 2: Run the schema test to verify it fails**

Run: `php tests/schema.php`
Expected: FAIL on the new table/columns.

- [ ] **Step 3: Implement schema and storage**

In `hook.php`:

- Create `glpi_plugin_tralla_boards_users` with the columns from the spec, unique key `(plugin_tralla_boards_id, users_id)`, keys on `users_id` and `role`.
- Add the eleven card columns from the spec with `fieldExists`-style idempotent checks.
- Update `status` comment to include `deletion_pending,deleted`; update `approval_kind` default `maintenance`.
- Backfill `is_deleted=0`, `approval_kind='maintenance'`.
- For each board with no members and `users_id > 0`, insert the creator as `superadmin` if the user exists.
- Add `glpi_plugin_tralla_boards_users` to the uninstall table list.

In `inc/boarduser.class.php`:

- Use `$DB` and prepared query-builder calls only.
- `getMembers` joins `glpi_users`; return `users_id`, `role`, `date_creation`, `name`, `realname`, `firstname`, `is_deleted`; sort by role rank desc then display name.
- `setRole` validates `tralla_normalize_board_role`, validates the user with `$user = new User(); $user->getFromDB($userId)`, inserts or updates, and rejects demoting the last `superadmin`. Count superadmins inside the same transaction that performs the update; optionally `SELECT ... FOR UPDATE` the board row.
- `remove` uses the same transaction/last-superadmin guard.
- `remove` rejects removing the last `superadmin`.
- `countSuperadmins` is a `COUNT(*)` query.
- `addCreator` inserts the creator as `superadmin`; ignore duplicates.

- [ ] **Step 4: Run schema test and static checks**

Run: `php tests/schema.php`
Expected: `OK`.

Run: `php -l hook.php` and `php -l inc/boarduser.class.php` in a PHP environment.
Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add hook.php inc/boarduser.class.php tests/schema.php
git commit -m "Cria membros por quadro e campos de exclusão"
```

---

### Task 3: Board ACL, visibility, and Kanban bootstrap

**Files:**
- Modify: `inc/board.class.php:35-63,151-199,211-327`
- Modify: `front/kanban.php:25-85,112-126`

**Interfaces:**
- Consumes: `PluginTrallaBoardUser`, `tralla_role_*`, `tralla_can_*`.
- Produces:
  - `PluginTrallaBoard::roleFor(int $boardId, int $userId = 0): string`
  - `PluginTrallaBoard::canViewBoard(int $boardId): bool`
  - `PluginTrallaBoard::canOperateBoard(int $boardId, int $userId = 0): bool`
  - `PluginTrallaBoard::canAdminBoard(int $boardId, int $userId = 0): bool`
  - `PluginTrallaBoard::canGovernBoard(int $boardId, int $userId = 0): bool`
  - `PluginTrallaBoard::getVisibleBoards(bool $includeArchived = false): array`
  - Bootstrap keys `board.role`, `board.role_label`, `can.create_card`, `can.move_card`, `can.manage_lists`, `can.manage_board`, `can.manage_members`, `can.view_deleted`, `can.restore`, `can.approve`.

- [ ] **Step 1: Add the failing role/visibility coverage**

Extend `tests/run.php` with:

```php
check('superadmin can govern', tralla_can_manage_members('superadmin', true));
check('admin cannot govern', !tralla_can_manage_members('admin', true));
check('view requires entity', !tralla_role_can_view(true, false, false, true));
check('view requires read', !tralla_role_can_view(true, false, true, false));
```

Run: `php tests/run.php`
Expected: PASS for the pure rules; board integration remains untested until GLPI.

- [ ] **Step 2: Implement the board ACL**

`roleFor`:

```php
$userId = $userId > 0 ? $userId : (int) Session::getLoginUserID();
$board = new self();
if ($boardId <= 0 || !$board->getFromDB($boardId)) return '';
if (!Session::haveAccessToEntity(...)) return '';
if (Session::haveRight('plugin_tralla', PURGE)) return 'superadmin';
return PluginTrallaBoardUser::getRole($boardId, $userId);
```

`canViewBoard`: `tralla_role_can_view($role !== '', $role === 'superadmin', $hasEntity, Session::haveRight('plugin_tralla', READ))`.
`canOperateBoard`: `canViewBoard` + role not empty.
`canAdminBoard`: `canOperateBoard` + `tralla_role_at_least($role, 'admin')`.
`canGovernBoard`: `canOperateBoard` + role `superadmin`.

`getVisibleBoards`: start from `where is_archived` filter; if not `PURGE`, restrict to board IDs returned by `boards_users` for the current user; then filter entity access as today.

`post_addItem`: call `PluginTrallaBoardUser::addCreator($boardId, (int) Session::getLoginUserID())` after creating default lists.

`cleanDBonPurge`: delete `glpi_plugin_tralla_boards_users` rows for the board, in addition to the existing cascade.

`canUpdateItem`: `canAdminBoard($id)` + `UPDATE`.
`canDeleteItem`: `canGovernBoard($id)` + `DELETE`.
`canPurgeItem`: `canGovernBoard($id)` + `PURGE`.

`getStats`, `countCards`, `countMaintenanceByStatus`: exclude `is_deleted=1`; skip `deletion_pending` in totals.

- [ ] **Step 3: Update `front/kanban.php`**

Compute:

```php
$role = PluginTrallaBoard::roleFor($boards_id);
$roleLabel = tralla_board_role_label($role);
$canManageLists = PluginTrallaBoard::canAdminBoard($boards_id) && Session::haveRight('plugin_tralla', UPDATE);
$canManageBoard = $canManageLists;
$canManageMembers = PluginTrallaBoard::canGovernBoard($boards_id) && Session::haveRight('plugin_tralla', UPDATE);
$canViewDeleted = PluginTrallaBoard::canAdminBoard($boards_id);
$canRestore = tralla_can_restore($role, Session::haveRight('plugin_tralla', UPDATE));
```

Add to `board`: `role`, `role_label`.
Add to `can`: `create_card`, `move_card`, `manage_lists`, `manage_board`, `manage_members`, `view_deleted`, `restore`, `approve`.
Keep `rights` for compatibility, but set `rights.approve` using `PluginTrallaCard::userIsApprover($userId, $boards_id)`.

Replace header gates:

- **Lista**: `can.manage_lists`.
- **Editar quadro**: `can.manage_board`.
- **Adicionar card**: `can.create_card`.
- Keep the config gear only for `can.manage_board` or `PURGE`; do not expose it to ordinary members.

- [ ] **Step 4: Verify**

Run: `node --check public/js/tralla.js`
Expected: no output.

Run: `php -l inc/board.class.php` and `php -l front/kanban.php` in a PHP environment.
Expected: no syntax errors.

Manual: create a board as user A, confirm A is `superadmin`; add user B as `member`; B sees only that board; user C without membership does not see it.

- [ ] **Step 5: Commit**

```bash
git add inc/board.class.php front/kanban.php tests/run.php
git commit -m "Aplica papéis e visibilidade por quadro"
```

---

### Task 4: Board settings tabs and member management

**Files:**
- Modify: `front/board.form.php:13-151`
- Modify: `public/css/tralla.css` (tabs/member table styles)

**Interfaces:**
- Consumes: `PluginTrallaBoardUser`, `PluginTrallaBoard::canGovernBoard`, `tralla_board_role_labels`.
- Produces: POST actions `member_add`, `member_update`, `member_remove`; GET `tab=general|members`.

- [ ] **Step 1: Write the failing manual acceptance checklist**

Add to the task notes (not a PHP test): with an `Admin` account, open `board.form.php?id=X&tab=members`; the member table must render read-only. With `SuperAdmin`, the add form, role selects, save and remove buttons must render. Save fails until the POST handlers exist.

- [ ] **Step 2: Implement POST handlers**

Before rendering, after resolving the board:

```php
$canGovern = PluginTrallaBoard::canGovernBoard($id);
if (isset($_POST['member_add']) || isset($_POST['member_update']) || isset($_POST['member_remove'])) {
    Session::checkRight('plugin_tralla', UPDATE);
    tralla_check_csrf($_POST);
    if (!$canGovern) { throw new RuntimeException('Sem permissão para gerenciar membros.'); }
    $memberId = tralla_post_int('users_id');
    $role = tralla_normalize_board_role(tralla_post_string('role'));
    if (isset($_POST['member_remove'])) {
        PluginTrallaBoardUser::remove($id, $memberId);
    } else {
        PluginTrallaBoardUser::setRole($id, $memberId, $role);
    }
    Html::redirect($pluginUrl . '/front/board.form.php?id=' . $id . '&tab=members');
}
```

Use `Session::addMessageAfterRedirect` for success/error and redirect on `RuntimeException`.

- [ ] **Step 3: Render tabs without nested forms**

- `$tab = ($_GET['tab'] ?? 'general') === 'members' ? 'members' : 'general';`
- Replace the page-level `$canUpdate`/`$canDelete` with `$canUpdate = PluginTrallaBoard::canAdminBoard($id) && Session::haveRight('plugin_tralla', UPDATE);` and `$canDelete = PluginTrallaBoard::canGovernBoard($id) && Session::haveRight('plugin_tralla', DELETE);` so a global editor who is only a `member` cannot edit or delete the board.
- Gate the POST `update`/`delete` handlers with those same helpers (the `check()` call remains the final authority).
- Tab links: `?id=...&tab=general` and `?id=...&tab=members`.
- General tab keeps the current board form.
- Members tab renders a table; each row has its own `<form>` for save/remove.
- Add form uses `User::dropdown(['name' => 'users_id', 'display_emptychoice' => true])` and a role select.
- `Admin` sees role labels and no controls; `SuperAdmin` sees controls.
- If the member is the last `superadmin`, disable demote/remove and add a title explaining why.
- Show a deleted GLPI user as “Usuário removido” without breaking the page.

- [ ] **Step 4: Verify**

Run: `php -l front/board.form.php` in a PHP environment.
Expected: no syntax errors.

Manual: add B as `member`, change to `admin`, try to demote the only `superadmin` (must fail), remove B, confirm redirect to `tab=members`.

- [ ] **Step 5: Commit**

```bash
git add front/board.form.php public/css/tralla.css
git commit -m "Adiciona gestão de membros ao quadro"
```

---

### Task 5: Card permissions, normal deletion, restore, and Deleted list

**Files:**
- Modify: `inc/card.class.php:72-160,219-297,513-553`
- Modify: `inc/card.class.php` (new methods after `deleteCard` or near it)

**Interfaces:**
- Consumes: `tralla_can_request_deletion`, `tralla_restore_*`, `PluginTrallaBoard::can*`.
- Produces:
  - `PluginTrallaCard::permissionsFor()` with `request_deletion`
  - `PluginTrallaCard::userIsApprover(int $userId = 0, int $boardId = 0): bool`
  - `PluginTrallaCard::requestDeletion(int $id, string $reason, int $userId = 0, bool $isAdmin = false): array`
  - `PluginTrallaCard::restoreDeleted(int $id, int $userId = 0, bool $isAdmin = false): array`
  - `PluginTrallaCard::getDeletedForBoard(int $boardId): array`

- [ ] **Step 1: Add failing pure assertions for the deletion rule**

Extend `tests/run.php` with:

```php
check('deleted cannot request', !tralla_can_request_deletion(
    ['stage' => 1, 'is_deleted' => 1, 'status' => 'deleted', 'is_locked' => 0], true, true, false
));
check('deletion_pending cannot request', !tralla_can_request_deletion(
    ['stage' => 1, 'is_deleted' => 0, 'status' => 'deletion_pending', 'is_locked' => 0], true, true, false
));
check('normal restore fallback status', tralla_restore_status(
    ['deletion_previous_status' => 'deleted'], 'normal'
) === 'normal');
check('maintenance restore fallback status', tralla_restore_status(
    ['deletion_previous_status' => 'deleted'], 'maintenance'
) === 'maintenance_pending');
```

Run: `php tests/run.php`
Expected: PASS once Task 1 is implemented.

- [ ] **Step 2: Implement `permissionsFor` and `userIsApprover`**

- Resolve `$boardId` from the card and `$role = PluginTrallaBoard::roleFor($boardId, $userId)`.
- Treat `$isAdmin` (`PURGE`) as effective `superadmin`.
- Add `request_deletion` via `tralla_can_request_deletion($card, $role !== '', Session::haveRight('plugin_tralla', DELETE), $isAdmin)`.
- Remove `delete`.
- Gate `take`, `edit_card`, `edit_machines`, `move`, `withdraw`, `approve`, `reject` on a non-empty role.
- `userIsApprover($userId, $boardId)`: require `plugin_tralla_approve` and (`PURGE` global or `PluginTrallaBoardUser::getRole(...) !== ''`).
- Update every `userIsApprover()` call (`permissionsFor`, `decide`, `confirmWithdrawal`) to pass the board ID; do not leave any one-argument call.

- [ ] **Step 3: Implement normal deletion and restore**

`requestDeletion`:

- Fetch card, `assertBoardAccess`, get board type, call `permissionsFor`, require `request_deletion`.
- Clean and validate reason; throw `Informe o motivo da exclusão.` for empty.
- Begin transaction.
- Re-read the card inside the transaction and repeat the rule.
- Store previous list/status/lock and `deletion_requested_at/by`.
- If board is normal: set `is_deleted=1`, `status='deleted'`, `is_locked=1`, `deletion_decided_at/by=now/user`, update, log `card_deletion_requested`, commit.
- If board is maintenance: delegate to Task 6 logic; if Task 6 is not yet implemented, throw a clear `RuntimeException` so the plan can be executed out of order.
- Return `['card' => detail|null, 'approval' => detail|null]`.

`restoreDeleted`:

- Require card `is_deleted=1`.
- Require `$isAdmin` or `PluginTrallaBoard::canAdminBoard($boardId)` plus `UPDATE`.
- Load active lists for the board.
- Use `tralla_restore_list_id(..., $boardType)`, `tralla_restore_status(..., $boardType)`, `tralla_restore_is_locked($card)`.
- Re-read the card and re-check `is_deleted=1` inside the transaction before updating.
- If list is `0`, throw `O quadro não possui uma lista ativa para restaurar o card.` before any update.
- Clear `is_deleted`, status/lock/list, `approval_cards_id`, deletion fields.
- Log `card_deletion_restored`; commit; return detail.

`getDeletedForBoard`:

- Query `plugin_tralla_boards_id`, `is_deleted=1`, order `deletion_decided_at DESC, id DESC`.
- Add `requester_name`, `decider_name`, `previous_list_name`, `deleted_at_formatted`.
- Return only fields needed by the modal; do not include deleted cards in `getCardsForBoard`.

Update `getCardsForBoard`: add `is_deleted=0` and skip rows with `status='deletion_pending'`.

- [ ] **Step 4: Verify**

Run: `php tests/run.php` and `php -l inc/card.class.php`.
Expected: `OK` and no syntax errors.

Manual: delete a normal card with an empty reason (rejected), with a valid reason (moves to Excluídos), restore it, and confirm machines/technician/history remain.

- [ ] **Step 5: Commit**

```bash
git add inc/card.class.php front/kanban.php tests/run.php
git commit -m "Adiciona exclusão direta e restauração de cards"
```

---

### Task 6: Maintenance deletion approval and finalization marker

**Files:**
- Modify: `inc/card.class.php:668-830` (`finalize`, `decide`, new `decideDeletion`)
- Modify: `inc/card.class.php` (maintenance branch of `requestDeletion`)

**Interfaces:**
- Consumes: `requestDeletion`, `permissionsFor`, `userIsApprover`, `PluginTrallaBoard::findListByCategory`.
- Produces: `PluginTrallaCard::decideDeletion(int $approvalId, bool $approve, string $reason, int $userId, bool $isAdmin): array`; `finalize` writes `approval_kind='maintenance'`.

- [ ] **Step 1: Write the failing approval-state checklist**

Add to task notes: a maintenance card in `deletion_pending` must have a `/2` child with `approval_kind='deletion'`, status `waiting_approval`, and be absent from `getCardsForBoard`; the child must appear in `maintenance_approval`.

- [ ] **Step 2: Implement the maintenance branch of `requestDeletion`**

- Find `maintenance_approval`; throw if missing.
- Insert `/2` with `approval_kind='deletion'`, `parent_cards_id=/1`, `stage=2`, `status=waiting_approval`, `users_id=solicitante`, `technician_id`, `source`, `machines_total`, name/description copied.
- Update `/1` with `status='deletion_pending'`, `is_locked=1`, `approval_cards_id=/2`, deletion fields and previous state.
- Log `card_deletion_requested` on both cards.
- Commit; return parent and approval detail.

- [ ] **Step 3: Implement `decideDeletion` and dispatch**

At the start of `decide`, after reading `$approval`:

```php
$boardId = (int) $approval['plugin_tralla_boards_id'];
if ((string) ($approval['approval_kind'] ?? 'maintenance') === 'deletion') {
    return self::decideDeletion($approvalId, $approve, $reason, $userId, $isAdmin);
}
```

Then replace the existing `self::userIsApprover($userId)` with `self::userIsApprover($userId, $boardId)`.

`decideDeletion`:

- Validate stage 2, status `waiting_approval`, parent status `deletion_pending`, board access.
- Require `userIsApprover($userId, $boardId)`.
- On approve: `/1` becomes `deleted` + `is_deleted=1` + `is_locked=1` + `deletion_decided_at/by`; `/2` becomes `approved` + `is_archived=1` + approver/date; log `card_deletion_approved` on both.
- On reject: `/1` returns to `deletion_previous_status`, list and lock; clear deletion fields and `approval_cards_id`; `/2` becomes `rejected` + `is_archived=1` + approver/reason; log `card_deletion_rejected` on both.
- Use a transaction and re-check parent status inside it.

In `finalize`, add `'approval_kind' => 'maintenance'` to the created `/2` row.

- [ ] **Step 4: Verify**

Run: `php -l inc/card.class.php`.
Expected: no syntax errors.

Manual: request deletion on a maintenance card, confirm `/2` appears in Aprovação and `/1` disappears from the board; reject and confirm `/1` returns; repeat and approve, then confirm `/1` is only in Excluídos.

- [ ] **Step 5: Commit**

```bash
git add inc/card.class.php
git commit -m "Aprova exclusão de cards de manutenção"
```

---

### Task 7: Board history query and action labels

**Files:**
- Modify: `inc/history.class.php:37-80`

**Interfaces:**
- Consumes: `tralla_parse_history_card_filter`, `tralla_validate_history_dates`.
- Produces: `PluginTrallaHistory::getForBoard(int $boardId, array $filters): array` with `rows`, `total`, `page`, `pages`.

- [ ] **Step 1: Add failing pure assertions for filter/date behavior**

`tests/run.php` already covers the parser and dates. Add:

```php
check('history filter trims', tralla_parse_history_card_filter('  #12/1  ')['ok'] === true);
check('history date empty is valid', tralla_validate_history_dates('', '')['ok'] === true);
```

Run: `php tests/run.php`
Expected: PASS.

- [ ] **Step 2: Implement `getForBoard`**

- Validate `card_id` with `tralla_parse_history_card_filter`; throw `RuntimeException` on invalid.
- Validate dates; throw on invalid.
- Build `WHERE` for `plugin_tralla_boards_id`.
- For `type='all'`: `(c.id = :id OR c.parent_cards_id = :id)`.
- For `type='stage'`: `c.id = :id` for stage 1; `c.parent_cards_id = :id AND c.stage = 2` for stage 2.
- Join `glpi_plugin_tralla_cards c` on history card id and board id.
- Left join `glpi_users u`; include deleted cards (`c.is_deleted=1`).
- Date range on `h.date_creation`; `date_to` uses the normalized `23:59:59`.
- `START = (page-1)*50`, `LIMIT = 50`; order `h.date_creation DESC, h.id DESC`. GLPI's query builder emits `LIMIT 50 OFFSET ...` from `START`/`LIMIT`.
- Return `rows`, `total`, `page`, `pages`; add `card_code` via `tralla_display_code`, `action_label`, `date_formatted`, `user_name`, `is_deleted`.
- If the query builder cannot express `OR`, use two queries and merge/sort, or `new QueryExpression`; never concatenate user input.

- [ ] **Step 3: Add the four action labels**

Add:

```php
'card_deletion_requested' => 'Exclusão solicitada',
'card_deletion_approved'  => 'Exclusão aprovada',
'card_deletion_rejected'  => 'Exclusão recusada',
'card_deletion_restored'  => 'Card restaurado',
```

- [ ] **Step 4: Verify**

Run: `php -l inc/history.class.php`.
Expected: no syntax errors.

Manual: filter by `123`, `#123`, `123/1`, `123/2`, dates, empty result, invalid date, and page 2.

- [ ] **Step 5: Commit**

```bash
git add inc/history.class.php tests/run.php
git commit -m "Adiciona histórico filtrável por quadro"
```

---

### Task 8: AJAX endpoints and approval group removal

**Files:**
- Modify: `front/ajax.php:192-217,260-270` and add new cases
- Modify: `front/config.form.php:29-35,181-195`
- Modify: `inc/config.class.php` (remove `approvalGroupId`)

**Interfaces:**
- Consumes: all Task 5-7 domain methods.
- Produces: AJAX actions `card_request_deletion`, `deleted_cards`, `restore_card`, `board_history`; `card_delete` removed.

- [ ] **Step 1: Write the failing endpoint checklist**

Add to task notes: `card_delete` must no longer exist in `front/ajax.php` or `public/js/tralla.js`; `card_request_deletion` must require a reason; `deleted_cards` and `restore_card` must reject a `member`.

- [ ] **Step 2: Implement endpoints**

Replace the `card_delete` case with:

```php
case 'card_request_deletion':
    $cardId = tralla_post_int('id');
    $result = PluginTrallaCard::requestDeletion(
        $cardId,
        tralla_post_string('reason'),
        $userId,
        $isAdmin
    );
    tralla_json(['ok' => true, 'data' => [
        'state' => tralla_ajax_state((int) ($result['card']['plugin_tralla_boards_id'] ?? 0)),
        'card' => $result['card'],
        'approval' => $result['approval'],
    ]]);
```

Add:

- `deleted_cards`: `boards_id`, require `PluginTrallaBoard::canAdminBoard`, return `cards`.
- `restore_card`: `id`, call `restoreDeleted`, return `state` and `card`.
- `board_history`: `boards_id`, `card_id`, `date_from`, `date_to`, `page`, require member, return the Task 7 result.
- `approve_card`/`reject_card`: unchanged; `decide()` dispatches by `approval_kind`.
- `take_card`: `take()` now enforces role/state; keep the endpoint.

`tralla_ajax_state` must exclude `deletion_pending` and `is_deleted=1` from cards and stats.

- [ ] **Step 3: Remove the global approval group**

- Delete `PluginTrallaConfig::set('approval_group', ...)` from `front/config.form.php`.
- Remove the “Aprovações” card/`Group::dropdown` block.
- Remove `PluginTrallaConfig::approvalGroupId()` and its cache usage.
- Leave the `approval_group` row in `glpi_plugin_tralla_configs` for compatibility; never read it.

- [ ] **Step 4: Verify**

Run: `rg -n "approval_group|approvalGroupId|card_delete" inc front public` (use `Select-String` on this Windows workspace).
Expected: no `approvalGroupId` or `card_delete` in `front/ajax.php`/JS; `approval_group` only in `hook.php`, docs/spec, or migration compatibility.

Run: `node --check public/js/tralla.js`.
Expected: no output.

Run: `php -l front/ajax.php` and `php -l front/config.form.php` in a PHP environment.
Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add front/ajax.php front/config.form.php inc/config.class.php
git commit -m "Expõe exclusão, histórico e restauração na API"
```

---

### Task 9: Kanban header, permissions, and minimized Take

**Files:**
- Modify: `public/js/tralla.js:327-340,539-676,810-854,2143-2191`
- Modify: `front/kanban.php` header (already prepared in Task 3)
- Modify: `public/css/tralla.css` (header/take button styles)

**Interfaces:**
- Consumes: `board.role`, `can.*`, `permissions.take`.
- Produces: header buttons, `data-board-history`, `data-board-deleted`, `data-card-take`, `handleTake()`.

- [ ] **Step 1: Write the failing UI checklist**

Add to task notes: as a `member`, the header shows **Histórico** only; as `admin`, **Excluídos** and **Editar quadro** appear; a minimized card in `maintenance_pending` shows **Pegar** for a user with `permissions.take`; clicking it does not open the modal.

- [ ] **Step 2: Implement the header and permissions**

- Add `data-can` or use the existing `can` object from `state.can || state.rights`; keep `rights` as fallback.
- Replace `can.create`/`can.update` gates for list creation and board editing with `can.manage_lists`/`can.manage_board`.
- Add **Histórico** always; **Excluídos** only if `can.view_deleted`; **Editar quadro** only if `can.manage_board`.
- **Adicionar card** uses `can.create_card`.
- List edit/delete uses `can.manage_lists`; drag uses `can.move_card`.

- [ ] **Step 3: Implement minimized Take**

In `renderCard`, add:

```js
var takeButton = card.permissions && card.permissions.take
    ? '<button type="button" class="tralla-card-take" data-card-take="' + cardId + '">'
        + '<i class="ti ti-hand-stop" aria-hidden="true"></i> Pegar</button>'
    : '';
```

Append it to the card footer. In the delegated click handler:

```js
var takeButton = event.target.closest('[data-card-take]');
if (takeButton) {
    event.preventDefault();
    event.stopPropagation();
    handleTake(Number(takeButton.dataset.cardTake), takeButton);
    return;
}
```

`handleTake` calls `api('take_card', {id})`, `applyState(data)`, and `toast('Card atribuído a você.', 'success')`; do not open the modal.

- [ ] **Step 4: Verify**

Run: `node --check public/js/tralla.js`.
Expected: no output.

Manual/CDP: as a technician, click **Pegar** on a pending card; confirm no modal opens and the card moves to Em Andamento. As a member without `UPDATE`, confirm the button is absent.

- [ ] **Step 5: Commit**

```bash
git add public/js/tralla.js public/css/tralla.css front/kanban.php
git commit -m "Adiciona ações de quadro e Pegar minimizado"
```

---

### Task 10: Deletion UX, Deleted modal, approval card, and history modal

**Files:**
- Modify: `public/js/tralla.js:810-854,1008-1044,1099-1124,2143-2191`
- Modify: `public/css/tralla.css`

**Interfaces:**
- Consumes: AJAX actions from Task 8.
- Produces: `openDeletedModal()`, `openHistoryModal()`, `renderDeletionPrompt()`, `handleDeleteCard()`.

- [ ] **Step 1: Write the failing UI checklist**

Add to task notes: **Excluir card** opens a required multiline prompt; normal board toast says the card went to Excluídos; maintenance board says it was sent for approval; `/2` shows **Aprovar exclusão**/**Recusar exclusão**; **Excluídos** restores; **Histórico** filters by ID/date.

- [ ] **Step 2: Replace the old delete action**

- In `renderSideActions`, change `permissions.delete` to `permissions.request_deletion`.
- Change the button label to **Excluir card**.
- In `handleCardAction('delete')`, call `promptDialog` with `required: true`, `multiline: true`, `maxlength: 2000`, and a message depending on `isMaintenance`.
- Call `api('card_request_deletion', {id, reason})`.
- On normal board: `applyState`, close modal, toast `Card movido para Excluídos.`
- On maintenance: `applyState`, close modal, toast `Exclusão enviada para aprovação.`
- On cancel, do nothing.

- [ ] **Step 3: Add the approval variant**

- Extend `renderApproval(card)` to detect `card.approval_kind === 'deletion'`.
- Show a banner with the reason (from `card.parent` or `card.deletion_reason`) and a **Ver origem** button (`data-open-card`).
- Change button labels to **Aprovar exclusão** and **Recusar exclusão**.
- On approve, toast `Exclusão aprovada. Card movido para Excluídos.`
- On reject, toast `Exclusão recusada. Card restaurado.` and reopen the parent card.

- [ ] **Step 4: Add Deleted and History modals**

`openDeletedModal()`:

- `api('deleted_cards', {boards_id})`.
- Render a wide modal with code, name, reason, requested/deleted dates, requester, approver, previous list, and **Restaurar**.
- Restore calls `api('restore_card', {id})`, `applyState`, removes the row, toasts.
- Empty state: `Nenhum card excluído neste quadro.`

`openHistoryModal()`:

- `api('board_history', {boards_id, card_id, date_from, date_to, page})`.
- Filters **Filtrar** and **Limpar**; 50 rows/page.
- Table columns: data/hora, card, ação, usuário, detalhes.
- Pagination previous/next.

Bind header buttons and modal events with `bindBackdropDismiss`; ensure Escape closes only the top modal.

- [ ] **Step 5: Verify and commit**

Run: `node --check public/js/tralla.js`.
Expected: no output.

Manual/CDP: normal delete, maintenance request, reject, approve, restore, history filters, empty states, Escape/backdrop.

```bash
git add public/js/tralla.js public/css/tralla.css
git commit -m "Adiciona lixeira, aprovação de exclusão e histórico"
```

---

### Task 11: Documentation, regression tests, and final verification

**Files:**
- Modify: `README.md` (roles, deletion, history, approval group removal)
- Modify: `docs/TESTING.md:58-63` and add v3 checklist
- Modify: `DESIGN.md` (new modals, tabs, destructive actions)
- Modify: `tests/run.php` and `tests/schema.php` with any missing assertions
- Modify: `C:\Users\Leonardo\AppData\Local\Temp\opencode\cdp-normal-card-test.js` and `cdp-create-card-test.js`

**Interfaces:**
- Consumes: everything above.
- Produces: updated operator documentation and regression coverage.

- [ ] **Step 1: Write the failing regression assertions**

Add to `tests/run.php`:

```php
check('member cannot manage board', !tralla_can_manage_members('member', true));
check('admin can restore with update', tralla_can_restore('admin', true));
check('admin cannot restore without update', !tralla_can_restore('admin', false));
check('superadmin cannot be removed by pure rule', tralla_role_rank('superadmin') === 3);
```

Add to `tests/schema.php`:

```php
check('card status comment includes deleted', str_contains($hook, 'deletion_pending'));
check('boards_users in uninstall', str_contains($hook, "glpi_plugin_tralla_boards_users"));
```

- [ ] **Step 2: Update docs**

- README: replace “Grupo aprovador opcional” with “Membros do quadro com `plugin_tralla_approve`”; document roles, visibility, deletion, restore, history, and minimized **Pegar**.
- TESTING: replace the global approval-group cases with per-board member cases; add normal/maintenance deletion, approval, restore, history filters, last-superadmin, and visibility checks.
- DESIGN: add the board settings tabs, Deleted/History modals, required reason prompt, and `data-*` hooks.

- [ ] **Step 3: Extend CDP regression**

- `cdp-normal-card-test.js`: delete with empty reason, valid reason, Excluídos, restore.
- `cdp-create-card-test.js`: minimized **Pegar**, no modal, list-flow add-card still disabled.
- New `cdp-deletion-approval-test.js`: maintenance request, `/2` banner, reject, approve.
- New `cdp-board-members-test.js`: tabs, add/update/remove, last-superadmin block.
- New `cdp-board-history-test.js`: ID/date filters and pagination.

Use the existing template and helper functions; do not introduce a test framework.

- [ ] **Step 4: Run final verification**

Run:

```powershell
node --check .\public\js\tralla.js
node C:\Users\Leonardo\AppData\Local\Temp\opencode\check-css.js
```

Expected: JS syntax clean; CSS check clean.

Run in a PHP/GLPI 12 environment:

```powershell
php tests/run.php
php tests/schema.php
php -l hook.php
php -l inc\boarduser.class.php
php -l inc\board.class.php
php -l inc\card.class.php
php -l inc\history.class.php
php -l front\ajax.php
php -l front\board.form.php
php -l front\kanban.php
php -l front\config.form.php
```

Expected: `OK`, `OK`, and no syntax errors.

Run the CDP suites against the GLPI 12 homologation instance. Expected: all pass; if the instance is unavailable, record the blocked check in the final report.

- [ ] **Step 5: Commit**

```bash
git add README.md DESIGN.md docs/TESTING.md tests/run.php tests/schema.php
git commit -m "Documenta e testa a versão 3 do Tralla"
```

---

## Execution Order

1. Tasks 1-2 (foundation).
2. Tasks 3-4 (board ACL and settings).
3. Tasks 5-6 (card deletion/restore and maintenance approval).
4. Task 7 (history).
5. Task 8 (API and config).
6. Tasks 9-10 (Kanban UI).
7. Task 11 (docs and regression).

Tasks 5-6 depend on Task 2. Tasks 7-8 depend on Task 5. Tasks 9-10 depend on Tasks 3, 5, 7, and 8. Do not start Task 9 before the API shapes in Task 8 are implemented.
