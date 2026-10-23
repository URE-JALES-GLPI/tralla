/**
 * [URE] Tralla — Kanban e modal de card.
 * Sem dependências externas; usa a API JSON do plugin.
 */
(function () {
    'use strict';

    var bootstrapEl = document.getElementById('tralla-bootstrap');
    var root = document.getElementById('tralla-kanban');
    var boardModal = document.getElementById('tralla-board-create');

    /**
     * Closes a modal only when the pointer press starts on its backdrop.
     * A text selection can start inside the dialog and end on the backdrop,
     * which makes the resulting click target the backdrop; that must not close it.
     * Every custom modal must bind this helper to its backdrop. showModal() already does.
     */
    function bindBackdropDismiss(backdrop, close) {
        if (!backdrop) {
            return;
        }

        var pressedOnBackdrop = false;

        backdrop.addEventListener('pointerdown', function (event) {
            pressedOnBackdrop = event.target === backdrop;
        });

        backdrop.addEventListener('click', function (event) {
            var shouldClose = pressedOnBackdrop && event.target === backdrop;
            pressedOnBackdrop = false;

            if (shouldClose) {
                close();
            }
        });
    }

    function closeBoardModal() {
        if (!boardModal) {
            return;
        }
        boardModal.hidden = true;
        boardModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('tralla-modal-open');
    }

    function openBoardModal() {
        if (!boardModal) {
            return;
        }
        boardModal.hidden = false;
        boardModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('tralla-modal-open');
        var target = boardModal.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
        if (target) {
            target.focus();
        }
    }

    if (boardModal) {
        bindBackdropDismiss(boardModal, closeBoardModal);

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-tralla-modal="tralla-board-create"]')) {
                event.preventDefault();
                openBoardModal();
                return;
            }
            if (event.target.closest('[data-tralla-close="tralla-board-create"]')) {
                event.preventDefault();
                closeBoardModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !boardModal.hidden) {
                closeBoardModal();
            }
        });
    }

    var boardColorInput = document.getElementById('tralla-board-color');
    var boardColorValue = document.getElementById('tralla-board-color-value');

    if (boardColorInput && boardColorValue) {
        var syncBoardColorValue = function () {
            boardColorValue.textContent = boardColorInput.value;
        };

        boardColorInput.addEventListener('input', syncBoardColorValue);
        boardColorInput.addEventListener('change', syncBoardColorValue);
        syncBoardColorValue();
    }

    /* ------------------------------------------------------- dialogs */

    var activePluginDialog = null;

    function dialogEscape(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openPluginDialog(config) {
        config = config || {};

        return new Promise(function (resolve) {
            if (activePluginDialog) {
                activePluginDialog.cancel();
            }

            var mode = config.mode === 'prompt' ? 'prompt' : 'confirm';
            var tone = config.tone === 'danger' ? 'danger' : 'primary';
            var icon = config.icon || (tone === 'danger' ? 'ti-alert-triangle' : 'ti-info-circle');
            var title = config.title || 'Confirmar';
            var message = config.message || '';
            var confirmLabel = config.confirmLabel || 'Confirmar';
            var cancelLabel = config.cancelLabel || 'Cancelar';
            var uid = 'tralla-dialog-' + Date.now();
            var previousFocus = document.activeElement;
            var fieldHtml = '';

            if (mode === 'prompt') {
                var label = config.label || '';
                var maxlength = Number(config.maxlength || 255);
                var value = config.value === null || config.value === undefined ? '' : String(config.value);
                var labelHtml = label
                    ? '<label for="' + uid + '-input">' + dialogEscape(label) + '</label>'
                    : '';
                if (config.multiline) {
                    fieldHtml = '<div class="tralla-dialog-field">' + labelHtml
                        + '<textarea id="' + uid + '-input" rows="3" maxlength="' + maxlength + '" placeholder="'
                        + dialogEscape(config.placeholder || '') + '">' + dialogEscape(value) + '</textarea></div>';
                } else {
                    fieldHtml = '<div class="tralla-dialog-field">' + labelHtml
                        + '<input id="' + uid + '-input" type="text" maxlength="' + maxlength + '" value="'
                        + dialogEscape(value) + '" placeholder="' + dialogEscape(config.placeholder || '') + '"></div>';
                }
            }

            var layer = document.createElement('div');
            layer.className = 'tralla-dialog-layer';
            layer.innerHTML = '<div class="tralla-dialog-backdrop" data-dialog-backdrop></div>'
                + '<div class="tralla-modal tralla-dialog tralla-dialog-' + tone + '" role="alertdialog" aria-modal="true"'
                + ' aria-labelledby="' + uid + '-title" aria-describedby="' + uid + '-message">'
                + '<div class="tralla-dialog-head">'
                + '<span class="tralla-dialog-icon" aria-hidden="true"><i class="ti ' + dialogEscape(icon) + '"></i></span>'
                + '<div class="tralla-dialog-copy">'
                + '<h2 class="tralla-dialog-title" id="' + uid + '-title">' + dialogEscape(title) + '</h2>'
                + '<p class="tralla-dialog-message" id="' + uid + '-message">' + dialogEscape(message) + '</p>'
                + '</div></div>'
                + fieldHtml
                + '<p class="tralla-dialog-error" hidden></p>'
                + '<div class="tralla-dialog-actions">'
                + '<button type="button" class="tralla-btn tralla-btn-ghost" data-dialog-cancel>' + dialogEscape(cancelLabel) + '</button>'
                + '<button type="button" class="tralla-btn ' + (tone === 'danger' ? 'tralla-btn-danger' : 'tralla-btn-primary') + '" data-dialog-confirm>'
                + dialogEscape(confirmLabel) + '</button>'
                + '</div></div>';

            document.body.appendChild(layer);
            document.body.classList.add('tralla-dialog-open');

            var inputEl = layer.querySelector('input, textarea');
            var errorEl = layer.querySelector('.tralla-dialog-error');
            var confirmEl = layer.querySelector('[data-dialog-confirm]');
            var closed = false;

            function focusables() {
                return Array.prototype.slice.call(layer.querySelectorAll('button, input, textarea, select, a[href]'))
                    .filter(function (element) {
                        return !element.disabled && element.offsetParent !== null;
                    });
            }

            function finish(result) {
                if (closed) {
                    return;
                }
                closed = true;
                document.removeEventListener('keydown', onKeydown, true);
                layer.remove();
                activePluginDialog = null;
                if (!document.querySelector('.tralla-dialog-layer')) {
                    document.body.classList.remove('tralla-dialog-open');
                }
                if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
                    previousFocus.focus();
                }
                resolve(result);
            }

            function cancel() {
                finish(mode === 'prompt' ? null : false);
            }

            bindBackdropDismiss(layer.querySelector('[data-dialog-backdrop]'), cancel);

            function submit() {
                if (mode === 'prompt') {
                    var text = inputEl ? inputEl.value.trim() : '';
                    if (config.required && !text) {
                        if (errorEl) {
                            errorEl.hidden = false;
                            errorEl.textContent = config.requiredMessage || 'Preencha este campo.';
                        }
                        if (inputEl) {
                            inputEl.focus();
                        }
                        return;
                    }
                    finish(text);
                    return;
                }
                finish(true);
            }

            function onKeydown(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    cancel();
                    return;
                }
                if (event.key === 'Tab') {
                    var items = focusables();
                    if (!items.length) {
                        return;
                    }
                    var first = items[0];
                    var last = items[items.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                    return;
                }
                if (event.key === 'Enter') {
                    if (inputEl && inputEl.tagName === 'TEXTAREA' && !event.ctrlKey && !event.metaKey) {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    submit();
                }
            }

            layer.addEventListener('click', function (event) {
                if (event.target.closest('[data-dialog-confirm]')) {
                    submit();
                    return;
                }
                if (event.target.closest('[data-dialog-cancel]')) {
                    cancel();
                }
            });

            activePluginDialog = { cancel: cancel };
            document.addEventListener('keydown', onKeydown, true);

            window.setTimeout(function () {
                if (inputEl) {
                    inputEl.focus();
                    if (inputEl.tagName === 'INPUT' && config.select !== false) {
                        inputEl.select();
                    }
                } else if (confirmEl) {
                    confirmEl.focus();
                }
            }, 30);
        });
    }

    function confirmDialog(options) {
        return openPluginDialog(Object.assign({ mode: 'confirm' }, options || {}))
            .then(function (confirmed) {
                return confirmed === true;
            });
    }

    function promptDialog(options) {
        return openPluginDialog(Object.assign({ mode: 'prompt' }, options || {}));
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-tralla-confirm]');
        if (!trigger) {
            return;
        }
        if (trigger.dataset.trallaConfirmBypass === '1') {
            delete trigger.dataset.trallaConfirmBypass;
            return;
        }
        event.preventDefault();
        confirmDialog({
            title: trigger.dataset.trallaConfirmTitle || 'Confirmar ação',
            message: trigger.dataset.trallaConfirm || '',
            tone: trigger.dataset.trallaConfirmTone === 'danger' ? 'danger' : 'primary',
            confirmLabel: trigger.dataset.trallaConfirmLabel || 'Confirmar',
            icon: trigger.dataset.trallaConfirmIcon || 'ti-alert-triangle'
        }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            if (trigger.form && typeof trigger.form.requestSubmit === 'function') {
                trigger.form.requestSubmit(trigger);
                return;
            }
            trigger.dataset.trallaConfirmBypass = '1';
            trigger.click();
        });
    }, true);

    if (!bootstrapEl || !root) {
        return;
    }

    var state;
    try {
        state = JSON.parse(bootstrapEl.textContent || '{}');
    } catch (error) {
        return;
    }

    if (!state.board || !state.urls) {
        return;
    }

    var listsEl = document.getElementById('tralla-lists');
    var modalRoot = document.getElementById('tralla-modal-root');
    var toastRoot = document.getElementById('tralla-toast-root');
    var searchInput = document.getElementById('tralla-board-search');
    var searchClear = document.querySelector('[data-search-clear]');
    var searchCount = document.querySelector('[data-search-count]');
    var isMaintenance = !!state.board.is_maintenance;
    var can = state.can || state.rights || {};
    var labels = state.labels || {};
    var activeCard = null;
    var dragCardId = null;
    var lastFocused = null;
    var shouldRefreshBoard = false;
    var saveTimers = {};
    var machineSaveQueue = Promise.resolve();
    var inlineComposerListId = null;
    var historyFilters = { card_id: '', date_from: '', date_to: '' };
    var historyPage = 1;
    var historyPages = 1;
    var searchQuery = '';
    var lastStateJson = JSON.stringify({
        lists: state.lists || [],
        cards: state.cards || []
    });
    var lastActivityId = Number(state.activity_cursor || 0);
    var lastVersion = String(state.version || '');
    var pollInterval = 3000;
    var pollTimer = null;
    var pollInFlight = false;
    var pendingPoll = false;
    var departingCards = {};
    var departingTimers = {};

    var palette = {
        '': '#97a0af',
        maintenance_pending: '#ff9f1a',
        maintenance_in_progress: '#0079bf',
        maintenance_approval: '#c377e0',
        maintenance_withdrawal: '#f2d600',
        maintenance_done: '#61bd4f'
    };

    var addCardBlockedCategories = {
        maintenance_in_progress: true,
        maintenance_approval: true,
        maintenance_withdrawal: true,
        maintenance_done: true
    };

    var statusClasses = {
        pending: 'pending',
        in_progress: 'is-in-progress',
        waiting_approval: 'approval',
        withdrawal: 'withdrawal',
        done: 'done',
        approved: 'done',
        rejected: 'danger'
    };

    var escapeMap = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (character) {
                return escapeMap[character];
            });
    }

    function attr(value) {
        return esc(value);
    }

    function safeColor(value, fallback) {
        var color = String(value || '');
        return /^#[0-9a-f]{6}$/i.test(color) ? color : (fallback || '#97a0af');
    }

    function formatDate(value) {
        if (!value) {
            return '—';
        }
        var normalized = String(value).replace(' ', 'T');
        var date = new Date(normalized);
        if (isNaN(date.getTime())) {
            return String(value);
        }
        try {
            return new Intl.DateTimeFormat('pt-BR', {
                dateStyle: 'short',
                timeStyle: 'short'
            }).format(date);
        } catch (error) {
            return date.toLocaleString();
        }
    }

    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/).slice(0, 2);
        return parts.map(function (part) {
            return part.charAt(0);
        }).join('').toUpperCase() || '?';
    }

    function getCard(id) {
        var numeric = Number(id);
        return state.cards.find(function (card) {
            return Number(card.id) === numeric;
        }) || null;
    }

    function approvalKind(card) {
        if (!card || Number(card.stage) !== 2) {
            return '';
        }
        var kind = String(card.approval_kind || 'maintenance');
        return kind === 'maintenance' ? 'final' : kind;
    }

    function approvalKindClass(card) {
        var kind = approvalKind(card);
        return kind ? 'approval-' + kind : '';
    }

    function approvalKindLabel(card) {
        if (card && card.approval_kind_label) {
            return String(card.approval_kind_label);
        }
        var kind = approvalKind(card);
        if (kind === 'deletion') {
            return 'Aprovação de exclusão';
        }
        if (kind === 'edit') {
            return 'Aprovação de edição';
        }
        return 'Aprovação de finalização';
    }

    function machineDisplayTitle(machine, sequence) {
        var model = String((machine && machine.model_name) || '').trim();
        if (model !== '') {
            return model + (sequence ? ' · unidade ' + sequence : '');
        }

        return 'Máquina #' + Number((machine && machine.id) || 0);
    }

    function scheduleDepartingCard(card, outcome) {
        if (!card || !card.id) {
            return;
        }
        var id = String(card.id);
        if (departingTimers[id]) {
            window.clearInterval(departingTimers[id]);
            delete departingTimers[id];
        }
        var ghost = {
            card: Object.assign({}, card),
            outcome: outcome === 'rejected' ? 'rejected' : 'approved',
            remaining: 10
        };
        departingCards[id] = ghost;
        departingTimers[id] = window.setInterval(function () {
            ghost.remaining -= 1;
            if (ghost.remaining <= 0) {
                window.clearInterval(departingTimers[id]);
                delete departingTimers[id];
                delete departingCards[id];
            }
            renderBoard();
        }, 1000);

        renderBoard();
    }

    function renderEditDiff(card) {
        var changes = Array.isArray(card && card.edit_changes) ? card.edit_changes : [];
        if (!changes.length) {
            return '';
        }

        return '<div class="tralla-edit-diff">'
            + '<h4><i class="ti ti-list-details"></i> Alterações solicitadas</h4>'
            + changes.map(function (change) {
                var label = String((change && change.label) || 'Alteração');
                var before = change && change.before !== null && change.before !== undefined && String(change.before) !== ''
                    ? String(change.before)
                    : '—';
                var after = change && change.after !== null && change.after !== undefined && String(change.after) !== ''
                    ? String(change.after)
                    : '—';
                return '<div class="tralla-edit-diff-row">'
                    + '<span class="tralla-edit-diff-label">' + esc(label) + '</span>'
                    + '<span class="tralla-edit-diff-before"><strong>Antes</strong>' + esc(before) + '</span>'
                    + '<i class="ti ti-arrow-right tralla-edit-diff-arrow" aria-hidden="true"></i>'
                    + '<span class="tralla-edit-diff-after"><strong>Depois</strong>' + esc(after) + '</span>'
                    + '</div>';
            }).join('')
            + '</div>';
    }

    function toast(message, type, duration) {
        if (!toastRoot) {
            return;
        }
        var element = document.createElement('div');
        var toastType = type === 'error'
            ? ' is-error'
            : (type === 'success' ? ' is-success' : (type === 'activity' ? ' is-activity' : ''));
        element.className = 'tralla-toast' + toastType;
        var icon = type === 'error'
            ? 'ti-alert-triangle'
            : (type === 'success'
                ? 'ti-circle-check'
                : (type === 'activity' ? 'ti-activity' : 'ti-info-circle'));
        element.innerHTML = '<i class="ti ' + icon + '"></i><span>' + esc(message) + '</span>';
        toastRoot.appendChild(element);
        window.setTimeout(function () {
            element.style.opacity = '0';
            element.style.transform = 'translateY(6px)';
            window.setTimeout(function () {
                element.remove();
            }, 220);
        }, duration || 3800);
    }

    async function api(action, payload) {
        var formData = new FormData();
        formData.set('action', action);
        formData.set('_glpi_csrf_token', state.csrf || '');

        Object.keys(payload || {}).forEach(function (key) {
            var value = payload[key];
            if (value === undefined || value === null) {
                return;
            }
            if (typeof value === 'object') {
                formData.set(key, JSON.stringify(value));
            } else {
                formData.set(key, String(value));
            }
        });

        var response = await fetch(state.urls.ajax, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        var text = await response.text();
        var json;

        try {
            json = JSON.parse(text);
        } catch (error) {
            throw new Error('O servidor retornou uma resposta inválida. Verifique o log do GLPI.');
        }

        if (!json || json.ok !== true) {
            throw new Error((json && json.error) || 'Não foi possível concluir a ação.');
        }

        return json.data;
    }

    function applyState(data) {
        if (!data) {
            return;
        }
        var next = data.state || data;
        if (!next || !Array.isArray(next.lists) || !Array.isArray(next.cards)) {
            return;
        }
        state.lists = next.lists;
        state.cards = next.cards;
        if (data.version) {
            lastVersion = String(data.version);
        }
        lastStateJson = JSON.stringify({
            lists: state.lists,
            cards: state.cards
        });
        renderBoard();
    }

    async function refreshState() {
        var data = await api('state', { boards_id: state.board.id });
        applyState(data);
    }

    function boardIsBusy() {
        if (document.hidden) {
            return true;
        }
        if (modalRoot && modalRoot.innerHTML) {
            return true;
        }
        if (document.querySelector('.tralla-dialog-layer')) {
            return true;
        }
        if (dragCardId !== null || inlineComposerListId !== null) {
            return true;
        }
        if (Object.keys(saveTimers).length > 0) {
            return true;
        }

        return false;
    }

    function schedulePoll(delay) {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }
        pollTimer = window.setTimeout(pollBoard, delay || pollInterval);
    }

    function activityText(event) {
        var who = String(event.user_name || 'Sistema');
        var action = String(event.action_label || event.action || 'Alteração');
        var code = String(event.card_code || ('#' + event.card_id));
        var detail = String(event.details || '').trim();
        if (detail.length > 90) {
            detail = detail.slice(0, 87) + '...';
        }

        return who + ': ' + action + ' · ' + code + (detail ? ' — ' + detail : '');
    }

    async function pollBoard() {
        if (pollInFlight) {
            pendingPoll = true;
            return;
        }
        if (document.hidden) {
            schedulePoll(pollInterval);
            return;
        }
        if (boardIsBusy()) {
            pendingPoll = true;
            schedulePoll(pollInterval);
            return;
        }

        pollInFlight = true;
        try {
            var ping = await api('board_ping', { boards_id: state.board.id });
            var hasChanges = String((ping && ping.version) || '') !== lastVersion
                || Number((ping && ping.cursor) || 0) !== lastActivityId;

            if (!hasChanges) {
                return;
            }

            var data = await api('board_poll', {
                boards_id: state.board.id,
                after_id: lastActivityId
            });

            if (data && data.state) {
                var nextJson = JSON.stringify({
                    lists: data.state.lists || [],
                    cards: data.state.cards || []
                });
                if (nextJson !== lastStateJson) {
                    applyState(data);
                }
            }

            if (data && data.version) {
                lastVersion = String(data.version);
            }

            if (data && data.cursor !== undefined) {
                lastActivityId = Number(data.cursor) || lastActivityId;
            }

            var ownId = Number(state.user && state.user.id || 0);
            var events = data && Array.isArray(data.activity) ? data.activity : [];
            var otherEvents = events.filter(function (event) {
                return Number(event.users_id) !== ownId;
            });

            otherEvents.slice(0, 3).forEach(function (event) {
                toast(activityText(event), 'activity', 5200);
            });
            if (otherEvents.length > 3) {
                toast('Mais ' + (otherEvents.length - 3) + ' alterações no quadro.', 'activity', 5200);
            }
        } catch (error) {
            /* silencioso: o quadro tenta novamente no próximo ciclo */
        } finally {
            pollInFlight = false;
            if (pendingPoll) {
                pendingPoll = false;
                schedulePoll(1200);
            } else {
                schedulePoll(pollInterval);
            }
        }
    }

    function statusPill(card) {
        if (!isMaintenance) {
            return '';
        }
        var label = card.status_label || labels.statuses[card.status] || card.status || '';
        if (!label) {
            return '';
        }
        var classes = [statusClasses[card.status] || '', approvalKindClass(card)].filter(function (value) {
            return value !== '';
        }).join(' ');
        return '<span class="tralla-pill ' + esc(classes) + '">'
            + esc(label) + '</span>';
    }

    function progressMarkup(progress, total, filled) {
        var safeTotal = Number(total) || 0;
        var safeFilled = Number(filled) || 0;
        var percent = progress && typeof progress.percent === 'number'
            ? progress.percent
            : (safeTotal > 0 ? Math.round((safeFilled / safeTotal) * 100) : 0);
        var complete = percent >= 100;
        return '<span class="tralla-card-badge tralla-card-progress" title="' + safeFilled + ' de ' + safeTotal + ' máquinas preenchidas">'
            + '<i class="ti ti-checkup-list" aria-hidden="true"></i>'
            + '<span>' + safeFilled + '/' + safeTotal + '</span>'
            + '<span class="tralla-progress' + (complete ? ' is-complete' : '') + '" aria-hidden="true">'
            + '<span style="width:' + Math.max(0, Math.min(100, percent)) + '%"></span></span>'
            + '</span>';
    }

    function cardMatchesSearch(card, query) {
        var terms = normalizeSearchText(query).split(/\s+/).filter(function (term) {
            return term !== '';
        });
        if (!terms.length) {
            return true;
        }

        var cardId = String(card.id || '');
        var code = String(card.code || ('#' + cardId));
        var familyRoot = Number(card.family_cards_id || 0);
        var familySeq = Number(card.family_seq || 0);
        var parts = [
            card.name,
            card.technician_name,
            code,
            cardId,
            '#' + cardId
        ];
        if (familyRoot > 0) {
            parts.push(String(familyRoot));
            parts.push(familyRoot + '/' + familySeq);
            parts.push('AP-' + familyRoot + '/' + familySeq);
        }
        if (Number(card.stage) === 2 && card.parent && card.parent.code) {
            parts.push(card.parent.code);
        }
        var haystack = normalizeSearchText(parts.join(' '));

        return terms.every(function (term) {
            return haystack.indexOf(term) !== -1;
        });
    }

    function updateSearchUi() {
        if (!searchInput) {
            return;
        }

        var hasQuery = searchQuery !== '';
        if (searchClear) {
            searchClear.hidden = !hasQuery;
        }
        if (!searchCount) {
            return;
        }
        if (!hasQuery) {
            searchCount.hidden = true;
            searchCount.textContent = '';
            return;
        }

        var total = (Array.isArray(state.cards) ? state.cards : []).filter(function (card) {
            return cardMatchesSearch(card, searchQuery);
        }).length;
        searchCount.hidden = false;
        searchCount.textContent = total + (total === 1 ? ' card' : ' cards');
    }

    function bindSearch() {
        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', function () {
            searchQuery = searchInput.value.trim();
            renderBoard();
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                searchInput.value = '';
                searchQuery = '';
                renderBoard();
            }
        });

        if (searchClear) {
            searchClear.addEventListener('click', function () {
                searchInput.value = '';
                searchQuery = '';
                renderBoard();
                searchInput.focus();
            });
        }
    }

    function renderBoard() {
        if (!listsEl) {
            return;
        }

        updateSearchUi();
        var scrollLeft = listsEl.scrollLeft;
        var lists = Array.isArray(state.lists) ? state.lists : [];
        var visibleCards = (Array.isArray(state.cards) ? state.cards : []).filter(function (card) {
            return cardMatchesSearch(card, searchQuery);
        });
        Object.keys(departingCards).forEach(function (id) {
            var ghost = departingCards[id];
            if (!ghost || !ghost.card) {
                return;
            }
            var exists = visibleCards.some(function (card) {
                return Number(card.id) === Number(ghost.card.id);
            });
            if (exists) {
                return;
            }
            var ghostCard = Object.assign({}, ghost.card, { _departing: ghost });
            if (cardMatchesSearch(ghostCard, searchQuery)) {
                visibleCards.push(ghostCard);
            }
        });

        if (!lists.length) {
            listsEl.innerHTML = '<div class="tralla-empty">'
                + '<i class="ti ti-layout-kanban"></i>'
                + '<h2>Este quadro ainda não tem listas</h2>'
                + '<p>Crie a primeira lista para começar a organizar os cards.</p>'
                + (can.manage_lists
                    ? '<button type="button" class="tralla-btn tralla-btn-primary" data-add-list>'
                        + '<i class="ti ti-plus"></i> Criar lista</button>'
                    : '')
                + '</div>';
            return;
        }

        var addListColumn = can.manage_lists
            ? '<button type="button" class="tralla-add-list-column" data-add-list>'
                + '<i class="ti ti-plus" aria-hidden="true"></i>'
                + '<span>Adicionar outra lista</span>'
                + '</button>'
            : '';

        listsEl.innerHTML = lists.map(function (list) {
            return renderList(list, visibleCards);
        }).join('') + addListColumn;
        listsEl.scrollLeft = scrollLeft;
    }

    function renderList(list, cards) {
        var listId = Number(list.id);
        var listCards = (Array.isArray(cards) ? cards : state.cards).filter(function (card) {
            return Number(card.plugin_tralla_lists_id) === listId;
        }).sort(function (left, right) {
            return Number(left.rank) - Number(right.rank) || Number(left.id) - Number(right.id);
        });

        var color = safeColor(list.color, palette[list.category] || palette['']);
        var actions = '';

        if (can.manage_lists) {
            actions += '<button type="button" class="tralla-icon-btn" data-edit-list="' + listId + '" title="Editar lista">'
                + '<i class="ti ti-pencil"></i></button>';
        }
        if (can.manage_lists && !list.category) {
            actions += '<button type="button" class="tralla-icon-btn" data-delete-list="' + listId + '" title="Excluir lista">'
                + '<i class="ti ti-trash"></i></button>';
        }

        var composerOpen = !isMaintenance && can.create_card && Number(inlineComposerListId) === listId;
        var addCardBlocked = !!addCardBlockedCategories[list.category];
        var addCardAttrs = addCardBlocked
            ? ' disabled aria-disabled="true" title="Novos cards de manutenção são criados em Manutenção Pendente."'
            : '';
        var addCard = can.create_card && !composerOpen
            ? '<button type="button" class="tralla-add-card" data-add-card="' + listId + '"' + addCardAttrs + '>'
                + '<i class="ti ti-plus"></i> Adicionar card</button>'
            : '';
        var composer = composerOpen ? renderInlineCardComposer(listId) : '';

        var categoryDot = list.category
            ? '<span class="tralla-list-dot" style="--tr-list-color:' + attr(color) + '" aria-hidden="true"></span>'
            : '';

        return '<section class="tralla-list" data-list-id="' + listId + '">'
            + '<header class="tralla-list-head" style="--tr-list-color:' + attr(color) + '">'
            + '<div class="tralla-list-title">' + categoryDot + '<h3>' + esc(list.name) + '</h3>'
            + '<span class="tralla-list-count">' + listCards.length + '</span></div>'
            + (actions ? '<div class="tralla-list-head-actions">' + actions + '</div>' : '')
            + '</header>'
            + '<div class="tralla-cards" data-list-id="' + listId + '">'
            + listCards.map(renderCard).join('')
            + '</div>'
            + composer
            + addCard
            + '</section>';
    }

    function renderCard(card) {
        var cardId = Number(card.id);
        var departing = card._departing || null;
        var departingClass = departing ? ' is-departing' : '';
        var canDrag = !isMaintenance && !!can.move_card && searchQuery === '' && !departing;
        var machineCount = Number(card.machine_count || 0);
        var progress = card.progress || { total: machineCount, filled: 0, percent: 0 };
        var showProgress = isMaintenance && (machineCount > 0 || Number(progress.total) > 0);
        var statusLabel = isMaintenance ? (card.status_label || labels.statuses[card.status] || '') : '';
        var statusClass = statusClasses[card.status] || '';
        var labelClass = approvalKindClass(card) || statusClass;
        var cardApprovalClass = Number(card.stage) === 2 ? approvalKindClass(card) : '';
        var label = statusLabel
            ? '<span class="tralla-card-label tralla-card-label-' + esc(labelClass) + '">' + esc(statusLabel) + '</span>'
            : '';
        var tech = '';

        if (card.technician_name) {
            tech = '<span class="tralla-card-avatar" title="Responsável: ' + attr(card.technician_name) + '">'
                + '<span class="tralla-avatar">' + esc(initials(card.technician_name)) + '</span></span>';
        }

        var source = '';
        if (card.source && card.source !== 'manual' && card.source_label) {
            source = '<span class="tralla-card-source" title="Origem: ' + attr(card.source_label) + '">'
                + esc(card.source_label) + '</span>';
        }

        var lock = card.is_locked
            ? '<span class="tralla-card-badge tralla-card-lock" title="Card bloqueado"><i class="ti ti-lock" aria-hidden="true"></i></span>'
            : '';

        var departingBadge = '';
        if (departing) {
            var departingOutcome = departing.outcome === 'rejected' ? 'rejected' : 'approved';
            departingBadge = '<span class="tralla-card-departing is-' + departingOutcome + '">'
                + '<i class="ti ' + (departingOutcome === 'rejected' ? 'ti-x' : 'ti-check') + '" aria-hidden="true"></i>'
                + '<span>' + (departingOutcome === 'rejected' ? 'Reprovado' : 'Aprovado')
                + ' · saindo em ' + Math.max(0, Number(departing.remaining) || 0) + 's</span></span>';
        }

        var machineSummaryText = String(card.machine_summary || '').trim();
        var machineBadgeText = machineSummaryText || (machineCount ? machineCount + ' máquina(s)' : '');
        var machineBadge = machineCount
            ? '<span class="tralla-card-badge tralla-card-machines" title="' + attr(machineBadgeText) + '">'
                + '<i class="ti ti-device-desktop" aria-hidden="true"></i><span>' + esc(machineBadgeText) + '</span></span>'
            : '';

        var badges = departingBadge + source;
        if (showProgress) {
            badges += progressMarkup(progress, Number(progress.total || machineCount), Number(progress.filled || 0));
        }
        badges += machineBadge + lock;

        var takeButton = !departing && card.permissions && card.permissions.take
            ? '<button type="button" class="tralla-card-take" data-card-take="' + cardId + '" title="Pegar para mim">'
                + '<i class="ti ti-hand-stop" aria-hidden="true"></i> Pegar</button>'
            : '';

        var cardCode = String(card.code || ('#' + cardId));
        var cardTop = '<div class="tralla-card-top">'
            + '<span class="tralla-card-id-chip" title="ID do card: ' + attr(cardCode) + '">'
            + esc(cardCode) + '</span>'
            + (label || '<span class="tralla-card-top-spacer" aria-hidden="true"></span>')
            + '</div>';

        return '<article class="tralla-card' + (card.is_locked ? ' is-locked' : '') + (cardApprovalClass ? ' ' + cardApprovalClass : '') + departingClass
            + '" data-card-id="' + cardId + '"'
            + (canDrag ? ' draggable="true"' : '')
            + ' tabindex="0" role="button" aria-label="Abrir card ' + attr(card.name) + '">'
            + '<span class="tralla-card-edit" aria-hidden="true"><i class="ti ti-pencil"></i></span>'
            + cardTop
            + '<h4 class="tralla-card-title">' + esc(card.name) + '</h4>'
            + ((badges || tech || takeButton)
                ? '<div class="tralla-card-footer">'
                    + '<div class="tralla-card-badges">' + badges + '</div>'
                    + tech
                    + takeButton
                    + '</div>'
                : '')
            + '</article>';
    }

    function computeDropRank(container, movingId, clientY) {
        var cards = Array.prototype.slice.call(container.querySelectorAll('.tralla-card'))
            .filter(function (element) {
                return Number(element.dataset.cardId) !== Number(movingId);
            });

        var previous = null;
        var next = null;

        cards.forEach(function (element) {
            var rect = element.getBoundingClientRect();
            if (clientY >= rect.top + (rect.height / 2)) {
                previous = element;
            } else if (!next) {
                next = element;
            }
        });

        var previousCard = previous ? getCard(previous.dataset.cardId) : null;
        var nextCard = next ? getCard(next.dataset.cardId) : null;
        var previousRank = previousCard ? Number(previousCard.rank) : null;
        var nextRank = nextCard ? Number(nextCard.rank) : null;

        if (previousRank !== null && nextRank !== null) {
            return (previousRank + nextRank) / 2;
        }
        if (previousRank !== null) {
            return previousRank + 1024;
        }
        if (nextRank !== null) {
            return Math.max(1, nextRank / 2);
        }
        return 1024;
    }

    /* ------------------------------------------------------------- modal */

    function showModal(html, wide) {
        if (!modalRoot) {
            return;
        }
        if (!modalRoot.innerHTML) {
            lastFocused = document.activeElement;
        }
        modalRoot.innerHTML = '<div class="tralla-modal-backdrop" data-tralla-modal-backdrop>'
            + '<div class="tralla-modal' + (wide ? ' is-wide' : '') + '" role="dialog" aria-modal="true">'
            + html
            + '</div></div>';
        bindBackdropDismiss(modalRoot.querySelector('[data-tralla-modal-backdrop]'), closeModal);
        document.body.classList.add('tralla-modal-open');

        window.setTimeout(function () {
            var target = modalRoot.querySelector('[autofocus]')
                || modalRoot.querySelector('input:not([disabled]), textarea:not([disabled]), button');
            if (target) {
                target.focus();
            }
        }, 20);
    }

    function closeModal() {
        if (!modalRoot) {
            return;
        }
        modalRoot.innerHTML = '';
        document.body.classList.remove('tralla-modal-open');
        activeCard = null;

        if (lastFocused && typeof lastFocused.focus === 'function' && document.contains(lastFocused)) {
            lastFocused.focus();
        }
        lastFocused = null;

        if (shouldRefreshBoard) {
            shouldRefreshBoard = false;
            refreshState().catch(function () {
                /* silencioso: o board será atualizado na próxima ação */
            });
        }
    }

    function modalCloseButton() {
        return '<button type="button" class="tralla-icon-btn" data-tralla-close aria-label="Fechar">'
            + '<i class="ti ti-x"></i></button>';
    }

    function detailHeader(card) {
        var sub = statusPill(card);
        if (card.source_label && card.source !== 'manual') {
            sub += '<span>' + esc(card.source_label) + '</span>';
        }
        if (card.technician_name) {
            sub += '<span class="tralla-card-tech"><span class="tralla-avatar">' + esc(initials(card.technician_name))
                + '</span>' + esc(card.technician_name) + '</span>';
        }

        return '<div class="tralla-modal-head">'
            + '<div>'
            + '<div class="tralla-detail-code">' + esc(card.code || ('#' + card.id))
            + ' · ' + esc(card.list_name || '') + '</div>'
            + '<h2 class="tralla-detail-title">' + esc(card.name) + '</h2>'
            + (sub ? '<div class="tralla-detail-sub">' + sub + '</div>' : '')
            + '</div>'
            + modalCloseButton()
            + '</div>';
    }

    function metaRow(label, value) {
        if (!value) {
            return '';
        }
        return '<div class="tralla-meta-row"><span>' + esc(label) + '</span><span>' + esc(value) + '</span></div>';
    }

    function renderSideMeta(card) {
        var rows = '';
        rows += metaRow('Código', card.code || ('#' + card.id));
        rows += metaRow('Lista', card.list_name);
        rows += metaRow('Tipo', isMaintenance ? 'Manutenção' : 'Normal');
        rows += metaRow('Responsável', card.technician_name || 'Não atribuído');
        rows += metaRow('Criado por', card.creator_name);
        rows += metaRow('Criado em', formatDate(card.date_creation));
        rows += metaRow('Atualizado em', formatDate(card.date_mod));
        rows += metaRow('Aprovador', card.approver_name);
        rows += metaRow('Finalizado em', formatDate(card.date_finalized));
        rows += metaRow('Aprovado em', formatDate(card.date_approved));
        rows += metaRow('Retirada em', formatDate(card.date_withdrawn));
        rows += metaRow('Motivo da reprovação', card.rejection_reason);

        return '<div class="tralla-card"><h3>Detalhes</h3><div class="tralla-meta-list">' + rows + '</div></div>';
    }

    function renderSideActions(card) {
        var permissions = card.permissions || {};
        var buttons = '';
        var note = '';

        if (permissions.take) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="take">'
                + '<i class="ti ti-hand-stop"></i> Pegar para mim</button>';
        }

        if (permissions.finalize) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="finalize">'
                + '<i class="ti ti-checkup-list"></i> Finalizar manutenção</button>';
        } else if (card.status === 'in_progress' && Number(card.stage) === 1) {
            note = 'Preencha o relatório, marque Serviço realizado e escolha o status de todas as máquinas. Ao menos uma deve estar Concluída ou Inservível.';
        }

        if (permissions.approve) {
            var kind = approvalKind(card);
            var approveLabel = kind === 'deletion'
                ? 'Aprovar exclusão'
                : (kind === 'edit' ? 'Aprovar edição' : 'Aprovar');
            var rejectLabel = kind === 'deletion'
                ? 'Recusar exclusão'
                : (kind === 'edit' ? 'Recusar edição' : 'Reprovar');
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="approve">'
                + '<i class="ti ti-shield-check"></i> ' + approveLabel + '</button>';
            buttons += '<button type="button" class="tralla-btn tralla-btn-danger" data-card-action="reject">'
                + '<i class="ti ti-shield-x"></i> ' + rejectLabel + '</button>';
        }

        if (permissions.split) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-ghost" data-card-action="split">'
                + '<i class="ti ti-git-branch"></i> Separar card</button>';
        }

        if (permissions.edit_request) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-ghost" data-card-action="edit">'
                + '<i class="ti ti-pencil"></i> Solicitar edição</button>';
        }

        if (permissions.withdraw) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="withdraw">'
                + '<i class="ti ti-package-export"></i> Confirmar retirada</button>';
        }

        if (permissions.request_deletion) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-danger" data-card-action="delete">'
                + '<i class="ti ti-trash"></i> Excluir card</button>';
        }

        if (!buttons && !note) {
            note = card.is_locked
                ? 'Este card está bloqueado enquanto aguarda uma ação de fluxo.'
                : 'Nenhuma ação disponível para o seu perfil neste estado.';
        }

        return '<div class="tralla-card"><h3>Ações</h3>'
            + '<div class="tralla-form">' + buttons + '</div>'
            + (note ? '<p class="tralla-muted" style="font-size:11px;margin-top:8px">' + esc(note) + '</p>' : '')
            + '</div>';
    }

    function refreshSideActions() {
        if (!activeCard) {
            return;
        }
        var aside = modalRoot.querySelector('.tralla-detail-side');
        if (aside) {
            aside.innerHTML = renderSideMeta(activeCard) + renderSideActions(activeCard);
        }
    }

    function renderHistory(card) {
        if (!Array.isArray(card.history) || !card.history.length) {
        return '<section class="tralla-detail-section"><h3><i class="ti ti-history"></i> Hist�rico</h3>'
            + '<p class="tralla-empty-mini">Sem hist�rico registrado.</p></section>';
        }

        return '<section class="tralla-detail-section"><h3><i class="ti ti-history"></i> Hist�rico</h3>'
            + '<div class="tralla-history">'
            + card.history.map(function (entry) {
                return '<div class="tralla-history-item">'
                    + '<div class="tralla-history-dot"><span class="tralla-avatar">' + esc(initials(entry.user_name || 'Sistema')) + '</span></div>'
                    + '<div class="tralla-history-body">'
                    + '<strong>' + esc(entry.action_label || entry.action) + '</strong>'
                    + '<small>' + esc(entry.user_name || 'Sistema') + ' � ' + esc(entry.date_formatted || formatDate(entry.date_creation)) + '</small>'
                    + (entry.details ? '<p>' + esc(entry.details) + '</p>' : '')
                    + '</div></div>';
            }).join('')
            + '</div></section>';
    }

    function machineStatusOptions(selected) {
        var options = '<option value="">Não preenchido</option>';
        Object.keys(labels.machine_choices || {}).forEach(function (value) {
            options += '<option value="' + attr(value) + '"' + (value === selected ? ' selected' : '') + '>'
                + esc(labels.machine_choices[value]) + '</option>';
        });
        return options;
    }

    function machineProgress(machines) {
        var total = machines.length;
        var filled = machines.filter(function (machine) {
            var report = String(machine.report || '').trim();
            return machine.is_filled
                || (report !== '' && !!machine.service_done && (machine.status === 'done' || machine.status === 'not_repairable' || machine.status === 'pending'));
        }).length;
        return {
            total: total,
            filled: filled,
            percent: total ? Math.round((filled / total) * 100) : 0
        };
    }

    function renderMachine(machine, canEdit, permissions, sequence) {
        var id = Number(machine.id);
        var title = machineDisplayTitle(machine, sequence);
        var disabled = canEdit ? '' : ' disabled';

        return '<div class="tralla-machine" data-machine-id="' + id + '">'
            + '<div class="tralla-machine-head">'
            + '<div class="tralla-machine-title">'
            + '<span data-machine-title-text>' + esc(title) + '</span>'
            + '<small>' + esc(machine.model_name || 'Modelo não informado') + '</small>'
            + '</div>'
            + '<span class="tralla-pill ' + (machine.is_filled ? 'done' : 'pending') + '">'
            + (machine.is_filled ? 'Preenchida' : 'Pendente') + '</span>'
            + '</div>'
            + '<div class="tralla-machine-grid is-single">'
            + '<div class="tralla-field"><label>Status</label>'
            + '<select data-machine-field="status"' + disabled + '>' + machineStatusOptions(machine.status || '') + '</select></div>'
            + '</div>'
            + '<div class="tralla-field" style="margin-top:9px"><label>Relatório técnico</label>'
            + '<textarea class="tralla-machine-report" data-machine-field="report" maxlength="200000"'
            + disabled + ' placeholder="Descreva o diagnóstico e o serviço executado...">' + esc(machine.report || '') + '</textarea></div>'
            + '<div class="tralla-machine-flags">'
            + '<label class="tralla-check"><input type="checkbox" data-machine-field="service_done" value="1"'
            + (Number(machine.service_done) ? ' checked' : '') + disabled + '> Serviço realizado</label>'
            + '<label class="tralla-check"><input type="checkbox" data-machine-field="needs_inventory" value="1"'
            + (Number(machine.needs_inventory) ? ' checked' : '') + disabled + '> Necessita inventariar</label>'
            + '</div></div>';
    }

    function renderMachines(card) {
        var isApproval = Number(card.stage) === 2;
        var machines = isApproval && card.parent
            ? (card.parent.machines || [])
            : (card.machines || []);
        var progress = machineProgress(machines);
        var permissions = card.permissions || {};
        var canEdit = !isApproval && !!permissions.edit_machines;

        return '<section class="tralla-detail-section">'
            + '<h3><i class="ti ti-device-desktop"></i> Máquinas</h3>'
            + '<div class="tralla-progress-summary" data-progress-summary>'
            + '<div class="tralla-progress' + (progress.percent >= 100 ? ' is-complete' : '') + '">'
            + '<span data-progress-bar style="width:' + progress.percent + '%"></span></div>'
            + '<span data-progress-label>' + progress.filled + '/' + progress.total + '</span>'
            + '</div>'
            + (machines.length
                ? machines.map(function (machine, index) {
                    return renderMachine(machine, canEdit, permissions, index + 1);
                }).join('')
                : '<p class="tralla-empty-mini">Nenhuma máquina cadastrada.</p>')
            + '</section>';
    }

    function renderApproval(card) {
        if (Number(card.stage) !== 2) {
            return '';
        }

        var kind = approvalKind(card);
        var openParent = card.parent
            ? '<button type="button" class="tralla-btn tralla-btn-ghost" data-open-card="' + Number(card.parent.id) + '">'
                + '<i class="ti ti-arrow-up-right"></i> Ver origem</button>'
            : '';
        var parentLine = card.parent
            ? '<small>Card de origem: ' + esc(card.parent.code) + ' — ' + esc(card.parent.name) + '</small>'
            : '';

        if (kind === 'deletion') {
            var reason = card.deletion_reason
                || (card.parent && card.parent.deletion_reason)
                || 'Motivo não informado.';

            return '<div class="tralla-approval-banner is-deletion">'
                + '<i class="ti ti-trash"></i>'
                + '<div class="tralla-approval-content"><strong>Exclusão aguardando aprovação</strong>'
                + parentLine
                + '<small class="tralla-deletion-reason">Motivo: ' + esc(reason) + '</small></div>'
                + openParent
                + '</div>';
        }

        if (kind === 'edit') {
            return '<div class="tralla-approval-banner is-edit">'
                + '<i class="ti ti-pencil"></i>'
                + '<div class="tralla-approval-content"><strong>Edição aguardando aprovação</strong>'
                + parentLine
                + '<small>As alterações só serão aplicadas depois da aprovação.</small>'
                + renderEditDiff(card)
                + '</div>'
                + openParent
                + '</div>';
        }

        return '<div class="tralla-approval-banner is-finalization">'
            + '<i class="ti ti-shield-check"></i>'
            + '<div class="tralla-approval-content"><strong>Aguardando aprovação de finalização</strong>'
            + (parentLine || '<small>Aguardando a decisão de um aprovador.</small>')
            + '</div>'
            + openParent
            + '</div>';
    }

    function renderPendingApproval(card) {
        var approvalId = Number(card.pending_approval_id || 0);
        if (!approvalId) {
            return '';
        }

        var kind = String(card.pending_approval_kind || 'maintenance');
        var kindClass = kind === 'deletion' ? 'deletion' : (kind === 'edit' ? 'edit' : 'finalization');
        var label = kind === 'deletion'
            ? 'Exclusão pendente de aprovação'
            : (kind === 'edit' ? 'Edição pendente de aprovação' : 'Finalização pendente de aprovação');
        var icon = kind === 'deletion' ? 'ti-trash' : (kind === 'edit' ? 'ti-pencil' : 'ti-shield-check');

        return '<div class="tralla-approval-banner is-' + kindClass + '">'
            + '<i class="ti ' + icon + '"></i>'
            + '<div class="tralla-approval-content"><strong>' + label + '</strong>'
            + '<small>Este card está bloqueado até a decisão.</small></div>'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-open-card="' + approvalId + '">'
            + '<i class="ti ti-arrow-up-right"></i> Ver aprovação</button>'
            + '</div>';
    }

    function renderFamily(card) {
        var members = Array.isArray(card.family_members) ? card.family_members : [];
        var origin = card.split_from || null;
        if (members.length <= 1 && !origin) {
            return '';
        }

        var chips = members.map(function (member) {
            return '<button type="button" class="tralla-family-chip' + (member.is_current ? ' is-current' : '')
                + '" data-open-card="' + Number(member.id) + '">'
                + '<span class="tralla-family-code">' + esc(member.code) + '</span>'
                + '<span class="tralla-family-name">' + esc(member.name) + '</span>'
                + '<small>' + esc(member.status_label || member.status || '') + '</small>'
                + '</button>';
        }).join('');

        var originBlock = origin
            ? '<p class="tralla-split-origin"><i class="ti ti-git-branch"></i> Separado de '
                + '<button type="button" class="tralla-link-btn" data-open-card="' + Number(origin.id) + '">'
                + esc(origin.code) + ' — ' + esc(origin.name) + '</button></p>'
            : '';

        return '<section class="tralla-detail-section tralla-family-section">'
            + '<h3><i class="ti ti-git-branch"></i> Família do card</h3>'
            + originBlock
            + '<div class="tralla-family-chips">' + chips + '</div>'
            + '</section>';
    }

    function renderAssignature(card) {
        if (!card.assignature_url) {
            return '';
        }
        return '<a class="tralla-assignature-link" href="' + attr(card.assignature_url) + '" target="_blank" rel="noopener noreferrer">'
            + '<i class="ti ti-external-link"></i><span>Retirada registrada no assignat-ure. Abrir comprovante.</span></a>';
    }

    function renderCardModal(card) {
        var permissions = card.permissions || {};
        var body = '';

        if (permissions.edit_card) {
            body += '<section class="tralla-detail-section">'
                + '<h3><i class="ti ti-pencil"></i> Conteúdo do card</h3>'
                + '<div class="tralla-field"><label for="tralla-detail-name">Nome</label>'
                + '<input id="tralla-detail-name" type="text" maxlength="255" value="' + attr(card.name) + '"></div>'
                + '<div class="tralla-field" style="margin-top:9px"><label for="tralla-detail-description">Descrição</label>'
                + '<textarea id="tralla-detail-description" rows="4" maxlength="2000">' + esc(card.description || '') + '</textarea></div>'
                + '<div class="tralla-detail-edit-actions">'
                + '<button type="button" class="tralla-btn tralla-btn-primary" data-save-card>'
                + '<i class="ti ti-device-floppy"></i> Salvar card</button></div>'
                + '</section>';
        } else {
            body += '<section class="tralla-detail-section">'
                + '<h3><i class="ti ti-file-text"></i> Descrição</h3>'
                + '<div class="tralla-detail-description">' + (card.description ? esc(card.description) : '<span class="tralla-empty-mini">Sem descrição.</span>') + '</div>'
                + '</section>';
        }

        body += renderPendingApproval(card);
        body += renderFamily(card);
        body += renderApproval(card);

        if (isMaintenance) {
            body += renderMachines(card);
        }

        body += renderAssignature(card);
        body += renderHistory(card);

        showModal(detailHeader(card)
            + '<div class="tralla-modal-body"><div class="tralla-detail-grid">'
            + '<div class="tralla-detail-main">' + body + '</div>'
            + '<aside class="tralla-detail-side">' + renderSideMeta(card) + renderSideActions(card) + '</aside>'
            + '</div></div>', true);
    }

    async function openCard(id) {
        try {
            var card = await api('card_get', { id: id });
            activeCard = card;
            renderCardModal(card);
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    function setBusy(button, busy) {
        if (!button) {
            return;
        }
        if (busy) {
            button.dataset.previousDisabled = button.disabled ? '1' : '0';
            button.disabled = true;
            button.classList.add('is-busy');
        } else {
            button.disabled = button.dataset.previousDisabled === '1';
            button.classList.remove('is-busy');
        }
    }

    async function saveCard(button) {
        if (!activeCard) {
            return;
        }
        var nameEl = document.getElementById('tralla-detail-name');
        var descriptionEl = document.getElementById('tralla-detail-description');
        var name = nameEl ? nameEl.value.trim() : activeCard.name;
        if (!name) {
            toast('Informe o nome do card.', 'error');
            return;
        }

        setBusy(button, true);
        try {
            var data = await api('card_update', {
                id: activeCard.id,
                name: name,
                description: descriptionEl ? descriptionEl.value : activeCard.description
            });
            applyState(data);
            toast('Card atualizado.', 'success');
            await openCard(activeCard.id);
            shouldRefreshBoard = false;
        } catch (error) {
            toast(error.message, 'error');
            setBusy(button, false);
        }
    }

    async function handleTake(cardId, button) {
        try {
            setBusy(button, true);
            var data = await api('take_card', { id: cardId });
            applyState(data);
            toast('Card atribuído a você.', 'success');
        } catch (error) {
            toast(error.message, 'error');
            setBusy(button, false);
        }
    }

    var TRALLA_CHALLENGE_WORDS = ['MASSON','PAIVA','FERRARI','SAWATA','NETO','MASSON','PAIVA','FERRARI','SAWATA','NETO','MASSON','PAIVA','FERRARI','SAWATA','NETO','MASSON','PAIVA','FERRARI','SAWATA','NETO','ALFA','BRAVO','CHARLIE','DELTA','ECHO','FOXTROT','GOLF','HOTEL','INDIA','JULIET','KILO','LIMA','MIKE','NOVEMBER','OSCAR','PAPA','QUEBEC','ROMEO','SIERRA','TANGO','UNIFORM','VICTOR','WHISKEY','XRAY','YANKEE','ZULU','AMARELO','AZUL','VERDE','VERMELHO','PRETO','BRANCO','CINZA','ROSA','ROXO','LARANJA','MARROM','PRATA','DOURADO','BRONZE','COBRE','FERRO','ACO','MADEIRA','PEDRA','VIDRO','PLASTICO','BORRACHA','TECIDO','PAPEL','COURO','LINHO','SEDA','LA','ALGODAO','NYLON','POLIESTER','VINIL','ACRILICO','CERAMICA','PORCELANA','FAIANCA','LOICA','BARRO','ARGILA','AREIA','GRAVA','SEIXO','PEDREGULHO','CAL','CIMENTO','CONCRETO','ASFALTO','BETUME','TAR','PITCH','RESINA'];

    function pickChallengeWord() {
        var idx = Math.floor(Math.random() * TRALLA_CHALLENGE_WORDS.length);
        return TRALLA_CHALLENGE_WORDS[idx];
    }

    function wordChallenge() {
        var word = pickChallengeWord();
        return new Promise(function (resolve) {
            if (activePluginDialog) {
                activePluginDialog.cancel();
            }
            var uid = 'tralla-challenge-' + Date.now();
            var layer = document.createElement('div');
            layer.className = 'tralla-dialog-layer';
            layer.innerHTML = '<div class="tralla-dialog-backdrop" data-dialog-backdrop></div>'
                + '<div class="tralla-modal tralla-dialog tralla-dialog-primary" role="alertdialog" aria-modal="true">'
                + '<div class="tralla-dialog-head">'
                + '<span class="tralla-dialog-icon" aria-hidden="true"><i class="ti ti-shield-check"></i></span>'
                + '<div class="tralla-dialog-copy">'
                + '<h2 class="tralla-dialog-title" id="' + uid + '-title">Verificacao</h2>'
                + '<p class="tralla-dialog-message">Digite a palavra exibida abaixo para confirmar:</p>'
                + '</div></div>'
                + '<div class="tralla-challenge-word">' + word + '</div>'
                + '<div class="tralla-dialog-field"><label for="' + uid + '-input">Palavra</label>'
                + '<input id="' + uid + '-input" class="tralla-challenge-input" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Digite aqui"></div>'
                + '<p class="tralla-dialog-error" hidden></p>'
                + '<div class="tralla-dialog-actions">'
                + '<button type="button" class="tralla-btn tralla-btn-ghost" data-dialog-cancel>Cancelar</button>'
                + '<button type="button" class="tralla-btn tralla-btn-primary" data-dialog-confirm>Confirmar</button>'
                + '</div></div>';
            document.body.appendChild(layer);
            document.body.classList.add('tralla-dialog-open');
            var inputEl = layer.querySelector('input');
            var errorEl = layer.querySelector('.tralla-dialog-error');
            var finished = false;
            function finish(ok) {
                if (finished) return;
                finished = true;
                document.body.classList.remove('tralla-dialog-open');
                layer.remove();
                resolve(ok);
            }
            function submit() {
                var value = inputEl ? inputEl.value.trim() : '';
                if (!value) {
                    if (errorEl) { errorEl.hidden = false; errorEl.textContent = 'Digite a palavra exibida.'; }
                    if (inputEl) inputEl.focus();
                    return;
                }
                if (value.toUpperCase() !== word.toUpperCase()) {
                    if (errorEl) { errorEl.hidden = false; errorEl.textContent = 'Palavra incorreta. Tente novamente.'; }
                    if (inputEl) { inputEl.value = ''; inputEl.focus(); }
                    return;
                }
                finish(true);
            }
            layer.querySelector('[data-dialog-confirm]').addEventListener('click', submit);
            layer.querySelector('[data-dialog-backdrop]').addEventListener('click', function () { finish(false); });
            layer.querySelector('[data-dialog-cancel]').addEventListener('click', function () { finish(false); });
            function uppercaseInput() {
                var start = inputEl.selectionStart;
                var end = inputEl.selectionEnd;
                var upper = inputEl.value.toUpperCase();
                if (inputEl.value !== upper) {
                    inputEl.value = upper;
                    inputEl.setSelectionRange(start, end);
                }
            }
            inputEl.addEventListener('input', function (e) {
                if (!e.isComposing) uppercaseInput();
            });
            inputEl.addEventListener('compositionend', uppercaseInput);
            inputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); submit(); }
                if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            });
            window.setTimeout(function () { if (inputEl) inputEl.focus(); }, 30);
        });
    }


    async function handleCardAction(action, button) {
        if (!activeCard) {
            return;
        }

        var cardId = activeCard.id;
        var kind = approvalKind(activeCard);
        var isDeletionApproval = kind === 'deletion';
        var isEditApproval = kind === 'edit';
        var departingCard = Object.assign({}, activeCard);

        if (action === 'split') {
            openSplitModal(activeCard);
            return;
        }
        if (action === 'edit') {
            openEditModal(activeCard);
            return;
        }

        try {
            if (action === 'delete') {
                var reason = await promptDialog({
                    title: 'Excluir card',
                    message: isMaintenance
                        ? 'A exclusão será enviada para aprovação. Informe o motivo.'
                        : 'O card será movido para Excluídos. Informe o motivo.',
                    label: 'Motivo da exclusão',
                    multiline: true,
                    required: true,
                    requiredMessage: 'Informe o motivo da exclusão.',
                    maxlength: 2000,
                    placeholder: 'Descreva o motivo...',
                    icon: 'ti-trash',
                    tone: 'danger',
                    confirmLabel: 'Solicitar exclusão'
                });
                if (reason === null) {
                    return;
                }
                setBusy(button, true);
                var deleted = await api('card_request_deletion', { id: cardId, reason: reason });
                applyState(deleted);
                closeModal();
                toast(isMaintenance ? 'Exclusão enviada para aprovação.' : 'Card movido para Excluídos.', 'success');
                return;
            }

            if (action === 'reject') {
                var rejectConfig;
                if (isDeletionApproval) {
                    rejectConfig = {
                        title: 'Recusar exclusão',
                        message: 'O card será restaurado ao quadro.',
                        label: 'Motivo (opcional)',
                        confirmLabel: 'Recusar exclusão'
                    };
                } else if (isEditApproval) {
                    rejectConfig = {
                        title: 'Recusar edição',
                        message: 'As alterações solicitadas serão descartadas.',
                        label: 'Motivo (opcional)',
                        confirmLabel: 'Recusar edição'
                    };
                } else {
                    rejectConfig = {
                        title: 'Reprovar manutenção',
                        message: 'O card voltará para Em Andamento com este registro no histórico.',
                        label: 'Motivo (opcional)',
                        confirmLabel: 'Reprovar'
                    };
                }
                var rejectReason = await promptDialog(Object.assign({
                    multiline: true,
                    placeholder: isDeletionApproval
                        ? 'Descreva o motivo da recusa...'
                        : (isEditApproval
                            ? 'Descreva o motivo da recusa da edição...'
                            : 'Descreva o motivo da reprovação...'),
                    icon: 'ti-shield-x',
                    tone: 'danger'
                }, rejectConfig));
                if (rejectReason === null) {
                    return;
                }
                setBusy(button, true);
                var rejected = await api('reject_card', { id: cardId, reason: rejectReason });
                applyState(rejected);
                scheduleDepartingCard(departingCard, 'rejected');
                toast(
                    isDeletionApproval
                        ? 'Exclusão recusada. Card restaurado.'
                        : (isEditApproval
                            ? 'Edição recusada. Alterações descartadas.'
                            : 'Manutenção reprovada. O card voltou para Em Andamento.'),
                    'success'
                );
                await openCard(rejected.card.id);
                return;
            }

            if (action === 'withdraw') {
                var withdrawConfirmed = await confirmDialog({
                    title: 'Confirmar retirada',
                    message: 'Confirmar a retirada de todas as máquinas deste card?',
                    icon: 'ti-package-export',
                    confirmLabel: 'Confirmar retirada'
                });
                if (!withdrawConfirmed) {
                    return;
                }
            }

            if (action === 'finalize') {
                var finalizeChallenge = await wordChallenge();
                if (!finalizeChallenge) {
                    toast('Verificação incorreta. Ação cancelada.', 'error');
                    return;
                }
                var pendingCount = (activeCard.machines || []).filter(function (machine) {
                    return machine.status === 'pending';
                }).length;
                var finalizeConfirmed = await confirmDialog({
                    title: 'Finalizar manutenção',
                    message: pendingCount > 0
                        ? 'Enviar as máquinas concluídas ou inservíveis para aprovação e reunir ' + pendingCount + ' pendente(s) em um novo card?'
                        : 'Finalizar a manutenção e enviar para aprovação?',
                    icon: 'ti-checkup-list',
                    confirmLabel: 'Finalizar'
                });
                if (!finalizeConfirmed) {
                    return;
                }
            }

            if (action === 'approve') {
                var approveConfig;
                if (isDeletionApproval) {
                    approveConfig = {
                        title: 'Aprovar exclusão',
                        message: 'Aprovar a exclusão deste card? Ele será movido para Excluídos.',
                        icon: 'ti-shield-check',
                        confirmLabel: 'Aprovar exclusão'
                    };
                } else if (isEditApproval) {
                    approveConfig = {
                        title: 'Aprovar edição',
                        message: 'Aplicar as alterações solicitadas a este card?',
                        icon: 'ti-shield-check',
                        confirmLabel: 'Aprovar edição'
                    };
                } else {
                    approveConfig = {
                        title: 'Aprovar manutenção',
                        message: 'Aprovar esta manutenção e liberar a retirada?',
                        icon: 'ti-shield-check',
                        confirmLabel: 'Aprovar'
                    };
                }
                var approveConfirmed = await confirmDialog(approveConfig);
                if (!approveConfirmed) {
                    return;
                }
            }

            setBusy(button, true);
            var data;

            if (action === 'take') {
                var takeChallenge = await wordChallenge();
                if (!takeChallenge) {
                    toast('Verificação incorreta. Ação cancelada.', 'error');
                    return;
                }
                data = await api('take_card', { id: cardId });
                applyState(data);
                toast('Card atribuído a você.', 'success');
                await openCard(cardId);
            } else if (action === 'finalize') {
                data = await api('finalize_card', { id: cardId });
                applyState(data);
                toast(data.pending_card
                    ? 'Manutenção enviada para aprovação. Máquinas pendentes no card ' + (data.pending_card.code || ('#' + data.pending_card.id)) + '.'
                    : 'Manutenção finalizada e enviada para aprovação.', 'success');
                await openCard(data.approval ? data.approval.id : cardId);
            } else if (action === 'approve') {
                data = await api('approve_card', { id: cardId });
                applyState(data);
                scheduleDepartingCard(departingCard, 'approved');
                if (isDeletionApproval) {
                    toast('Exclusão aprovada. Card movido para Excluídos.', 'success');
                    closeModal();
                } else if (isEditApproval) {
                    toast('Edição aprovada e aplicada.', 'success');
                    await openCard(data.card.id);
                } else {
                    toast('Manutenção aprovada. Card liberado para retirada.', 'success');
                    await openCard(data.card.id);
                }
            } else if (action === 'withdraw') {
                data = await api('confirm_withdrawal', { id: cardId });
                applyState(data);
                toast('Retirada confirmada. Manutenção concluída.', 'success');
                await openCard(data.card.id);
            }
        } catch (error) {
            toast(error.message, 'error');
            setBusy(button, false);
        }
    }

    function openSplitModal(card) {
        var machines = Array.isArray(card.machines) ? card.machines : [];
        if (machines.length < 2) {
            toast('O card precisa ter ao menos duas máquinas para ser separado.', 'error');
            return;
        }

        var rows = machines.map(function (machine, index) {
            return '<label class="tralla-split-row">'
                + '<input type="checkbox" data-split-machine value="' + Number(machine.id) + '">'
                + '<span class="tralla-split-machine">'
                + '<strong>' + esc(machineDisplayTitle(machine, index + 1)) + '</strong>'
                + '<small>' + esc(machine.status_label || '') + '</small>'
                + '</span></label>';
        }).join('');

        showModal('<form id="tralla-split-form" class="tralla-form" novalidate>'
            + '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-git-branch"></i> Separar card</h2>'
            + '<p>' + esc(card.code || ('#' + card.id)) + ' · ' + esc(card.name) + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">'
            + '<p class="tralla-muted">Selecione as máquinas que serão movidas para um novo card. O card atual mantém as demais.</p>'
            + '<div class="tralla-split-list">' + rows + '</div>'
            + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-git-branch"></i> Separar</button>'
            + '</div></form>');
    }

    async function submitSplitCard(form) {
        if (!activeCard) {
            return;
        }
        var ids = Array.prototype.slice.call(form.querySelectorAll('[data-split-machine]:checked')).map(function (input) {
            return Number(input.value);
        });
        var total = Number(activeCard.machine_count || (activeCard.machines || []).length || 0);
        if (!ids.length) {
            toast('Selecione ao menos uma máquina.', 'error');
            return;
        }
        if (ids.length >= total) {
            toast('Selecione menos máquinas do que o total do card.', 'error');
            return;
        }

        var submit = form.querySelector('button[type="submit"]');
        setBusy(submit, true);
        try {
            var data = await api('split_card', { id: activeCard.id, machine_ids: ids });
            var child = data.child;
            applyState(data);
            closeModal();
            toast('Card separado. Novo card ' + (child.code || ('#' + child.id)) + '.', 'success');
            await openCard(child.id);
        } catch (error) {
            toast(error.message, 'error');
            setBusy(submit, false);
        }
    }

    function editMachineRow(machine, sequence) {
        var id = Number(machine.id);
        return '<div class="tralla-edit-machine" data-edit-machine data-machine-id="' + id + '">'
            + '<div class="tralla-edit-machine-head">'
            + '<strong>' + esc(machineDisplayTitle(machine, sequence)) + '</strong>'
            + '<button type="button" class="tralla-icon-btn" data-remove-edit-machine title="Remover do card">'
            + '<i class="ti ti-trash"></i></button></div>'
            + '<div class="tralla-machine-grid is-single">'
            + '<div class="tralla-field"><label>Status</label>'
            + '<select data-edit-field="status">' + machineStatusOptions(machine.status || '') + '</select></div>'
            + '</div>'
            + '<div class="tralla-field" style="margin-top:9px"><label>Relatório técnico</label>'
            + '<textarea data-edit-field="report" maxlength="200000" placeholder="Descreva o diagnóstico e o serviço executado...">'
            + esc(machine.report || '') + '</textarea></div>'
            + '<div class="tralla-machine-flags">'
            + '<label class="tralla-check"><input type="checkbox" data-edit-field="service_done" value="1"'
            + (Number(machine.service_done) ? ' checked' : '') + '> Serviço realizado</label>'
            + '<label class="tralla-check"><input type="checkbox" data-edit-field="needs_inventory" value="1"'
            + (Number(machine.needs_inventory) ? ' checked' : '') + '> Necessita inventariar</label>'
            + '</div></div>';
    }

    function editNewMachineRow() {
        return '<div class="tralla-edit-new-machine" data-edit-new-machine>'
            + '<div class="tralla-add-machine-grid">'
            + '<div class="tralla-field"><label>Quantidade</label>'
            + '<input type="number" data-edit-new-quantity min="1" max="' + quantityMax() + '" step="1" value="1" inputmode="numeric"></div>'
            + '<div class="tralla-field"><label>Modelo</label><select data-edit-new-model>'
            + modelOptions('') + '</select></div>'
            + '</div>'
            + '<button type="button" class="tralla-icon-btn" data-remove-edit-new title="Remover modelo">'
            + '<i class="ti ti-x"></i></button>'
            + '</div>';
    }

    function openEditModal(card) {
        var machines = Array.isArray(card.machines) ? card.machines : [];
        var entities = Array.isArray(state.entities) ? state.entities : [];
        var hasEntities = entities.length > 0;

        var nameField = '<div class="tralla-field tralla-combobox-field">'
            + '<label for="tralla-edit-name">Nome do card *</label>'
            + '<div class="tralla-combobox">'
            + '<input id="tralla-edit-name" type="text" maxlength="255" value="' + attr(card.name) + '" required autocomplete="off"'
            + (hasEntities
                ? ' role="combobox" aria-autocomplete="list" aria-expanded="false"'
                    + ' aria-controls="tralla-edit-name-list" aria-haspopup="listbox"'
                : '')
            + '>'
            + (hasEntities
                ? '<div id="tralla-edit-name-list" class="tralla-combobox-list" role="listbox" hidden></div>'
                : '')
            + '</div></div>';

        var machineRows = machines.map(function (machine, index) {
            return editMachineRow(machine, index + 1);
        }).join('');

        showModal('<form id="tralla-edit-form" class="tralla-form" novalidate>'
            + '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-pencil"></i> Solicitar edição</h2>'
            + '<p>' + esc(card.code || ('#' + card.id)) + ' · ' + esc(card.name) + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">'
            + nameField
            + '<section class="tralla-detail-section">'
            + '<h3><i class="ti ti-device-desktop"></i> Máquinas</h3>'
            + '<div id="tralla-edit-machines">'
            + (machineRows || '<p class="tralla-empty-mini">Nenhuma máquina cadastrada.</p>')
            + '</div>'
            + '<button type="button" class="tralla-add-card" data-add-edit-machine>'
            + '<i class="ti ti-plus"></i> Adicionar modelo</button>'
            + '</section></div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-send"></i> Solicitar aprovação</button>'
            + '</div></form>', true);

        if (hasEntities) {
            setupEntityCombobox('tralla-edit-name', 'tralla-edit-name-list');
        }
    }

    async function submitEditRequest(form) {
        if (!activeCard) {
            return;
        }
        var nameEl = form.querySelector('#tralla-edit-name');
        var name = nameEl ? nameEl.value.trim() : '';
        if (!name) {
            toast('Informe o nome do card.', 'error');
            if (nameEl) {
                nameEl.focus();
            }
            return;
        }

        var machines = [];
        Array.prototype.forEach.call(form.querySelectorAll('[data-edit-machine]'), function (row) {
            var id = Number(row.dataset.machineId || 0);
            if (!id) {
                return;
            }
            var status = row.querySelector('[data-edit-field="status"]');
            var report = row.querySelector('[data-edit-field="report"]');
            var service = row.querySelector('[data-edit-field="service_done"]');
            var inventory = row.querySelector('[data-edit-field="needs_inventory"]');
            machines.push({
                id: id,
                status: status ? status.value : '',
                report: report ? report.value : '',
                service_done: service && service.checked ? 1 : 0,
                needs_inventory: inventory && inventory.checked ? 1 : 0
            });
        });

        var newMachines = [];
        var invalidQuantity = false;
        Array.prototype.forEach.call(form.querySelectorAll('[data-edit-new-machine]'), function (row) {
            var model = row.querySelector('[data-edit-new-model]');
            var quantityEl = row.querySelector('[data-edit-new-quantity]');
            var modelId = model ? Number(model.value) : 0;
            if (modelId <= 0) {
                return;
            }
            var quantity = quantityEl ? Number(quantityEl.value) : 1;
            if (!Number.isInteger(quantity) || quantity < 1 || quantity > quantityMax()) {
                invalidQuantity = true;
                return;
            }
            newMachines.push({ model_id: modelId, quantity: quantity });
        });
        if (invalidQuantity) {
            toast('Informe uma quantidade entre 1 e ' + quantityMax() + '.', 'error');
            return;
        }

        var submit = form.querySelector('button[type="submit"]');
        setBusy(submit, true);
        try {
            var data = await api('edit_request', {
                id: activeCard.id,
                name: name,
                machines: machines,
                new_machines: newMachines
            });
            applyState(data);
            closeModal();
            toast('Edição solicitada. Aguardando aprovação.', 'success');
            await openCard(data.card.id);
        } catch (error) {
            toast(error.message, 'error');
            setBusy(submit, false);
        }
    }

    function renderDeletedCardsModal(data) {
        var cards = Array.isArray(data.cards) ? data.cards : [];
        var body;

        if (!cards.length) {
            body = '<p class="tralla-empty-mini tralla-table-empty">Nenhum card excluído neste quadro.</p>';
        } else {
            body = '<div class="tralla-table-scroll">'
                + '<table class="tralla-data-table tralla-deleted-table">'
                + '<thead><tr>'
                + '<th>Código</th><th>Nome</th><th>Motivo</th>'
                + '<th>Solicitado em</th><th>Excluído em</th>'
                + '<th>Solicitante</th><th>Aprovador</th><th>Lista anterior</th><th></th>'
                + '</tr></thead><tbody>'
                + cards.map(function (card) {
                    var cardId = Number(card.id);
                    return '<tr>'
                        + '<td><span class="tralla-cell-title">' + esc(card.code || ('#' + cardId)) + '</span></td>'
                        + '<td>' + esc(card.name || '—') + '</td>'
                        + '<td><span class="tralla-cell-details">' + esc(card.deletion_reason || '—') + '</span></td>'
                        + '<td>' + esc(card.requested_at_formatted || '—') + '</td>'
                        + '<td>' + esc(card.deleted_at_formatted || '—') + '</td>'
                        + '<td>' + esc(card.requester_name || '—') + '</td>'
                        + '<td>' + esc(card.decider_name || '—') + '</td>'
                        + '<td>' + esc(card.previous_list_name || '—') + '</td>'
                        + '<td class="tralla-table-actions">'
                        + '<button type="button" class="tralla-btn tralla-btn-ghost" data-restore-card="' + cardId + '">'
                        + '<i class="ti ti-arrow-back-up"></i> Restaurar</button></td>'
                        + '</tr>';
                }).join('')
                + '</tbody></table></div>';
        }

        return '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-trash"></i> Cards excluídos</h2>'
            + '<p>' + esc(state.board.name) + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">' + body + '</div>';
    }

    async function openDeletedModal() {
        try {
            var data = await api('deleted_cards', { boards_id: state.board.id });
            showModal(renderDeletedCardsModal(data), true);
            return true;
        } catch (error) {
            toast(error.message, 'error');
            return false;
        }
    }

    async function restoreDeletedCard(cardId, button) {
        var restoreConfirmed = await confirmDialog({
            title: 'Restaurar card',
            message: 'Restaurar este card ao quadro?',
            icon: 'ti-arrow-back-up',
            confirmLabel: 'Restaurar'
        });
        if (!restoreConfirmed) {
            return;
        }

        setBusy(button, true);
        try {
            var data = await api('restore_card', { id: cardId });
            applyState(data);
            toast('Card restaurado.', 'success');
            var refreshed = await openDeletedModal();
            if (!refreshed) {
                closeModal();
            }
        } catch (error) {
            toast(error.message, 'error');
            setBusy(button, false);
        }
    }

    function renderHistoryModal(data) {
        var rows = Array.isArray(data.rows) ? data.rows : [];
        var page = Math.max(1, Number(data.page) || 1);
        var pages = Math.max(1, Number(data.pages) || 0);
        var table;

        if (!rows.length) {
            table = '<p class="tralla-empty-mini tralla-table-empty">Nenhum evento encontrado.</p>';
        } else {
            table = '<div class="tralla-table-scroll">'
                + '<table class="tralla-data-table tralla-history-table">'
                + '<thead><tr>'
                + '<th>Data/hora</th><th>Card</th><th>Ação</th><th>Usuário</th><th>Detalhes</th>'
                + '</tr></thead><tbody>'
                + rows.map(function (row) {
                    return '<tr>'
                        + '<td>' + esc(row.date_formatted || '—') + '</td>'
                        + '<td><span class="tralla-cell-title">' + esc(row.card_code || ('#' + row.card_id)) + '</span>'
                        + (row.card_name ? '<span class="tralla-cell-sub">' + esc(row.card_name) + '</span>' : '')
                        + '</td>'
                        + '<td>' + esc(row.action_label || row.action || '—') + '</td>'
                        + '<td>' + esc(row.user_name || '—') + '</td>'
                        + '<td><span class="tralla-cell-details">' + esc(row.details || '—') + '</span></td>'
                        + '</tr>';
                }).join('')
                + '</tbody></table></div>';
        }

        var filters = '<form id="tralla-history-form" class="tralla-history-filters" novalidate>'
            + '<div class="tralla-field"><label for="tralla-history-card">Card</label>'
            + '<input id="tralla-history-card" type="text" name="card_id" value="' + attr(historyFilters.card_id) + '"'
            + ' placeholder="ID, #ID ou ID/etapa"></div>'
            + '<div class="tralla-field"><label for="tralla-history-date-from">Data inicial</label>'
            + '<input id="tralla-history-date-from" type="date" name="date_from" value="' + attr(historyFilters.date_from) + '"></div>'
            + '<div class="tralla-field"><label for="tralla-history-date-to">Data final</label>'
            + '<input id="tralla-history-date-to" type="date" name="date_to" value="' + attr(historyFilters.date_to) + '"></div>'
            + '<div class="tralla-history-filter-actions">'
            + '<button type="submit" class="tralla-btn tralla-btn-primary"><i class="ti ti-filter"></i> Filtrar</button>'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-history-clear>Limpar</button>'
            + '</div></form>';

        var pagination = '<div class="tralla-history-pagination">'
            + '<span class="tralla-history-page-label">Página ' + page + ' de ' + pages + '</span>'
            + '<div class="tralla-history-pagination-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-history-page="prev"'
            + (page <= 1 ? ' disabled' : '') + '>'
            + '<i class="ti ti-chevron-left"></i> Anterior</button>'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-history-page="next"'
            + (page >= pages ? ' disabled' : '') + '>'
            + 'Próxima <i class="ti ti-chevron-right"></i></button>'
            + '</div></div>';

        return '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-history"></i> Histórico do quadro</h2>'
            + '<p>' + esc(state.board.name) + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">' + filters + table + pagination + '</div>';
    }

    async function openHistoryModal() {
        try {
            var data = await api('board_history', {
                boards_id: state.board.id,
                card_id: historyFilters.card_id,
                date_from: historyFilters.date_from,
                date_to: historyFilters.date_to,
                page: historyPage
            });
            historyPages = Math.max(1, Number(data.pages) || 0);
            showModal(renderHistoryModal(data), true);
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    function setSaveState(machineEl, statusText) {
        var stateEl = machineEl ? machineEl.querySelector('[data-machine-state]') : null;
        if (!stateEl) {
            return;
        }
        stateEl.className = 'tralla-save-state is-' + statusText;
        stateEl.textContent = statusText === 'saving' ? 'Salvando...' : (statusText === 'saved' ? 'Salvo' : 'Erro ao salvar');
    }

    function collectMachineFields(machineEl) {
        var payload = { machine_id: Number(machineEl.dataset.machineId) };
        Array.prototype.forEach.call(machineEl.querySelectorAll('[data-machine-field]'), function (field) {
            var name = field.dataset.machineField;
            if (!name) {
                return;
            }
            if (field.type === 'checkbox') {
                payload[name] = field.checked ? 1 : 0;
            } else {
                payload[name] = field.value;
            }
        });
        return payload;
    }

    function updateProgressDom(progress) {
        var summary = modalRoot.querySelector('[data-progress-summary]');
        if (!summary) {
            return;
        }
        var bar = summary.querySelector('[data-progress-bar]');
        var label = summary.querySelector('[data-progress-label]');
        var track = summary.querySelector('.tralla-progress');
        if (bar) {
            bar.style.width = progress.percent + '%';
        }
        if (track) {
            track.classList.toggle('is-complete', progress.percent >= 100);
        }
        if (label) {
            label.textContent = progress.filled + '/' + progress.total;
        }
        var finalize = modalRoot.querySelector('[data-card-action="finalize"]');
        if (finalize) {
            finalize.disabled = progress.percent < 100;
        }
    }

    function applyMachineResponse(machineEl, data) {
        var machineId = Number(machineEl.dataset.machineId);
        var machines = activeCard
            ? (Number(activeCard.stage) === 2 && activeCard.parent ? activeCard.parent.machines : activeCard.machines)
            : null;
        if (Array.isArray(machines)) {
            var local = machines.find(function (machine) {
                return Number(machine.id) === machineId;
            });
            if (local) {
                if (data.machine) {
                    Object.keys(data.machine).forEach(function (key) {
                        local[key] = data.machine[key];
                    });
                }
                local.is_filled = String(local.report || '').trim() !== ''
                    && !!local.service_done && (local.status === 'done' || local.status === 'not_repairable' || local.status === 'pending');
            }
            var pill = machineEl.querySelector('.tralla-pill');
            if (pill) {
                pill.className = 'tralla-pill ' + (local && local.is_filled ? 'done' : 'pending');
                pill.textContent = (local && local.is_filled) ? 'Preenchida' : 'Pendente';
            }
            var progress = data.progress || machineProgress(machines);
            updateProgressDom(progress);
        }
    }

    function saveMachine(machineEl) {
        if (!machineEl || !machineEl.isConnected) {
            return Promise.resolve();
        }
        var machineId = Number(machineEl.dataset.machineId);
        if (!machineId) {
            return Promise.resolve();
        }
        var cardId = activeCard && Number(activeCard.id);
        setSaveState(machineEl, 'saving');
        machineSaveQueue = machineSaveQueue.then(async function () {
            if (!machineEl.isConnected || !activeCard || Number(activeCard.id) !== cardId) {
                return;
            }
            var data = await api('machine_update', collectMachineFields(machineEl));
            if (!machineEl.isConnected || !activeCard || Number(activeCard.id) !== cardId) {
                return;
            }
            applyMachineResponse(machineEl, data);
            if (activeCard && activeCard.permissions) {
                activeCard.permissions.finalize = !!data.can_finalize && !!activeCard.permissions.edit_machines;
            }
            refreshSideActions();
            setSaveState(machineEl, 'saved');
            shouldRefreshBoard = true;
        }).catch(function (error) {
            setSaveState(machineEl, 'error');
            toast(error.message, 'error');
        });
        return machineSaveQueue;
    }

    function scheduleMachineSave(machineEl) {
        var machineId = Number(machineEl.dataset.machineId);
        if (!machineId) {
            return;
        }
        setSaveState(machineEl, 'saving');
        if (saveTimers[machineId]) {
            window.clearTimeout(saveTimers[machineId]);
        }
        saveTimers[machineId] = window.setTimeout(function () {
            delete saveTimers[machineId];
            saveMachine(machineEl);
        }, 700);
    }

    function flushMachineSave(machineEl) {
        var machineId = Number(machineEl.dataset.machineId);
        if (saveTimers[machineId]) {
            window.clearTimeout(saveTimers[machineId]);
            delete saveTimers[machineId];
            saveMachine(machineEl);
        }
    }

    /* ------------------------------------------------------------- models */

    function modelOptions(selected) {
        var options = '<option value="">Selecione um modelo...</option>';
        (state.models || []).forEach(function (model) {
            options += '<option value="' + Number(model.id) + '"'
                + (String(model.id) === String(selected) ? ' selected' : '') + '>'
                + esc(model.name) + '</option>';
        });
        if (can.create_card) {
            options += '<option value="__new">+ Cadastrar novo modelo...</option>';
        }
        return options;
    }

    async function createModel(name) {
        var data = await api('model_add', {
            name: name,
            entities_id: state.board.entities_id
        });
        state.models = state.models || [];
        state.models.push(data.model);
        return data.model;
    }

    function normalizeSearchText(value) {
        return String(value === null || value === undefined ? '' : value)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function entityMatches(entity, query) {
        var terms = normalizeSearchText(query).split(/\s+/).filter(function (term) {
            return term !== '';
        });
        if (!terms.length) {
            return true;
        }
        var haystack = normalizeSearchText((entity.name || '') + ' ' + (entity.completename || ''));
        return terms.every(function (term) {
            return haystack.indexOf(term) !== -1;
        });
    }

    function renderEntityOptions(query, list) {
        if (!list) {
            return;
        }
        var entities = Array.isArray(state.entities) ? state.entities : [];
        var matches = entities.filter(function (entity) {
            return entityMatches(entity, query);
        }).slice(0, 60);

        if (!matches.length) {
            list.innerHTML = '<div class="tralla-combobox-empty">Nenhuma entidade encontrada.</div>';
            list.hidden = false;
            return;
        }

        list.innerHTML = matches.map(function (entity, index) {
            var name = String(entity.name || '');
            var complete = String(entity.completename || '');
            var path = complete !== '' && complete !== name
                ? '<small>' + esc(complete) + '</small>'
                : '';
            return '<button type="button" class="tralla-combobox-option" role="option"'
                + ' id="' + attr(list.id) + '-entity-' + index + '" aria-selected="false"'
                + ' data-entity-name="' + attr(name) + '">'
                + '<span>' + esc(name) + '</span>' + path + '</button>';
        }).join('');
        list.hidden = false;
    }

    function closeEntityOptions(input) {
        if (!input) {
            return;
        }
        var listId = input.getAttribute('aria-controls');
        var list = listId ? document.getElementById(listId) : null;
        if (list) {
            list.hidden = true;
        }
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function selectEntityOption(input, option) {
        if (!input || !option) {
            return;
        }
        input.value = option.dataset.entityName || '';
        closeEntityOptions(input);
    }

    function setupEntityCombobox(inputId, listId) {
        inputId = inputId || 'tralla-create-name';
        listId = listId || 'tralla-create-name-list';
        var input = document.getElementById(inputId);
        var list = document.getElementById(listId);
        if (!input || !list) {
            return;
        }

        function openList() {
            renderEntityOptions(input.value, list);
            input.setAttribute('aria-expanded', 'true');
        }

        input.addEventListener('click', openList);
        input.addEventListener('input', openList);
        input.addEventListener('keydown', function (event) {
            var options = Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
            var active = list.querySelector('[role="option"].is-active');
            var index = options.indexOf(active);

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (list.hidden) {
                    openList();
                    return;
                }
                if (!options.length) {
                    return;
                }
                event.preventDefault();
                if (event.key === 'ArrowDown') {
                    index = index < 0 ? 0 : (index + 1) % options.length;
                } else {
                    index = index <= 0 ? options.length - 1 : index - 1;
                }
                options.forEach(function (option) {
                    option.classList.remove('is-active');
                    option.setAttribute('aria-selected', 'false');
                });
                options[index].classList.add('is-active');
                options[index].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', options[index].id);
                return;
            }

            if (event.key === 'Enter' && !list.hidden && active) {
                event.preventDefault();
                selectEntityOption(input, active);
                return;
            }

            if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                event.stopPropagation();
                closeEntityOptions(input);
            }
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                closeEntityOptions(input);
            }, 120);
        });
        list.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        list.addEventListener('click', function (event) {
            var option = event.target.closest('[role="option"]');
            if (option) {
                event.preventDefault();
                selectEntityOption(input, option);
            }
        });
    }

    function quantityMax() {
        return Math.max(1, Number(state.machine_quantity_max || 999));
    }

    function updateCreateMachineSummary(row) {
        var model = row.querySelector('[data-create-model]');
        var quantity = row.querySelector('[data-create-quantity]');
        var summary = row.querySelector('[data-create-summary]');
        if (!model || !quantity || !summary) {
            return;
        }
        var option = model.options[model.selectedIndex];
        var modelName = option && option.value && option.value !== '__new'
            ? option.textContent.trim()
            : '';
        var count = Number(quantity.value || 0);
        if (modelName && count > 0) {
            summary.textContent = count + 'x ' + modelName;
            summary.hidden = false;
        } else {
            summary.textContent = '';
            summary.hidden = true;
        }
    }

    function addCreateMachineRow() {
        var container = document.getElementById('tralla-create-machines');
        if (!container) {
            return;
        }
        var row = document.createElement('div');
        row.className = 'tralla-create-machine';
        row.setAttribute('data-create-machine', '');
        row.innerHTML = '<div class="tralla-add-machine-grid tralla-create-machine-grid">'
            + '<div class="tralla-field"><label>Quantidade *</label>'
            + '<input type="number" data-create-quantity min="1" max="' + quantityMax()
            + '" step="1" value="1" inputmode="numeric"></div>'
            + '<div class="tralla-field"><label>Modelo *</label><select data-create-model>'
            + modelOptions('') + '</select></div>'
            + '</div>'
            + '<div class="tralla-create-machine-summary" data-create-summary aria-live="polite" hidden></div>'
            + '<button type="button" class="tralla-icon-btn" data-remove-create-machine title="Remover modelo">'
            + '<i class="ti ti-x"></i></button>';
        container.appendChild(row);
        updateCreateMachineSummary(row);
    }

    /* ------------------------------------------------------- create card */

    function inlineComposerInput(form) {
        return form ? form.querySelector('textarea[name="name"]') : null;
    }

    function resizeInlineComposer(input) {
        if (!input) {
            return;
        }
        input.style.height = 'auto';
        input.style.height = Math.max(56, Math.min(160, input.scrollHeight)) + 'px';
    }

    function syncInlineComposerSubmit(form) {
        if (!form) {
            return;
        }
        var input = inlineComposerInput(form);
        var submit = form.querySelector('button[type="submit"]');
        if (input && submit) {
            submit.disabled = !input.value.trim();
        }
    }

    function focusInlineComposer() {
        var form = document.querySelector('[data-inline-card-form]');
        if (!form) {
            return;
        }
        var input = inlineComposerInput(form);
        if (!input) {
            return;
        }
        input.focus();
        resizeInlineComposer(input);
        input.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    function openInlineCardComposer(listId) {
        if (isMaintenance || !can.create_card) {
            return;
        }
        inlineComposerListId = Number(listId);
        renderBoard();
        focusInlineComposer();
    }

    function closeInlineCardComposer() {
        var listId = inlineComposerListId;
        inlineComposerListId = null;
        renderBoard();
        if (listId) {
            var button = document.querySelector('[data-add-card="' + listId + '"]');
            if (button) {
                button.focus();
            }
        }
    }

    function renderInlineCardComposer(listId) {
        var inputId = 'tralla-inline-card-' + listId;
        return '<form class="tralla-inline-card-form" data-inline-card-form data-list-id="' + listId + '" novalidate>'
            + '<label class="tralla-visually-hidden" for="' + inputId + '">Título do card</label>'
            + '<textarea id="' + inputId + '" name="name" rows="1" maxlength="255"'
            + ' placeholder="Insira um título ou cole um link" autocomplete="off"></textarea>'
            + '<div class="tralla-inline-card-actions">'
            + '<button type="submit" class="tralla-btn tralla-btn-primary" disabled>Adicionar card</button>'
            + '<button type="button" class="tralla-icon-btn tralla-inline-card-cancel" data-inline-card-cancel'
            + ' aria-label="Cancelar" title="Cancelar"><i class="ti ti-x" aria-hidden="true"></i></button>'
            + '</div></form>';
    }

    function openCreateCard(listId) {
        var list = state.lists.find(function (item) {
            return Number(item.id) === Number(listId);
        });
        if (!list) {
            return;
        }

        if (!isMaintenance) {
            openInlineCardComposer(listId);
            return;
        }

        if (addCardBlockedCategories[list.category]) {
            return;
        }

        var machinesSection = isMaintenance
            ? '<section class="tralla-detail-section">'
                + '<h3><i class="ti ti-device-desktop"></i> Máquinas *</h3>'
                + '<div id="tralla-create-machines"></div>'
                + '<button type="button" class="tralla-add-card" data-add-create-machine style="margin:0;padding-left:0">'
                + '<i class="ti ti-plus"></i> Adicionar outro modelo</button>'
                + '</section>'
            : '';

        var entities = Array.isArray(state.entities) ? state.entities : [];
        var hasEntities = entities.length > 0;
        var nameField = '<div class="tralla-field tralla-combobox-field">'
            + '<label for="tralla-create-name">Nome do card *</label>'
            + '<div class="tralla-combobox">'
            + '<input id="tralla-create-name" type="text" name="name" maxlength="255" required autocomplete="off"'
            + (hasEntities
                ? ' role="combobox" aria-autocomplete="list" aria-expanded="false"'
                    + ' aria-controls="tralla-create-name-list" aria-haspopup="listbox"'
                : '')
            + '>'
            + (hasEntities
                ? '<div id="tralla-create-name-list" class="tralla-combobox-list" role="listbox" hidden></div>'
                : '')
            + '</div></div>';

        showModal('<form id="tralla-create-card-form" class="tralla-form" data-list-id="' + Number(listId) + '" novalidate>'
            + '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-plus"></i> Novo card</h2>'
            + '<p>' + esc(list.name) + ' · ' + (isMaintenance ? 'Fluxo de manutenção' : 'Quadro normal') + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">'
            + nameField
            + machinesSection
            + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-check"></i> Criar card</button>'
            + '</div></form>');

        if (isMaintenance) {
            addCreateMachineRow();
        }
        if (hasEntities) {
            setupEntityCombobox('tralla-create-name', 'tralla-create-name-list');
        }
    }

    async function submitCreateCard(form) {
        var inline = form.hasAttribute('data-inline-card-form');
        var nameEl = form.querySelector('[name="name"]');
        var name = nameEl ? nameEl.value.trim() : '';

        if (!name) {
            toast('Informe o nome do card.', 'error');
            if (nameEl) {
                nameEl.focus();
            }
            return;
        }

        var machines = [];
        var invalidQuantity = false;
        Array.prototype.forEach.call(form.querySelectorAll('[data-create-machine]'), function (row) {
            var model = row.querySelector('[data-create-model]');
            var quantityEl = row.querySelector('[data-create-quantity]');
            var modelId = model ? Number(model.value) : 0;
            if (modelId > 0) {
                var quantity = quantityEl ? Number(quantityEl.value) : 1;
                if (!Number.isInteger(quantity) || quantity < 1 || quantity > quantityMax()) {
                    invalidQuantity = true;
                    return;
                }
                machines.push({
                    model_id: modelId,
                    quantity: quantity
                });
            }
        });

        if (invalidQuantity) {
            toast('Informe uma quantidade entre 1 e ' + quantityMax() + '.', 'error');
            return;
        }

        if (!inline && isMaintenance && !machines.length) {
            toast('Adicione ao menos uma máquina com modelo selecionado.', 'error');
            return;
        }

        var submit = form.querySelector('button[type="submit"]');
        setBusy(submit, true);

        try {
            var data = await api('card_add', {
                boards_id: state.board.id,
                list_id: Number(form.dataset.listId || 0),
                name: name,
                source: 'manual',
                machines: machines
            });

            if (inline) {
                inlineComposerListId = Number(form.dataset.listId || 0);
                applyState(data);
                toast('Card criado.', 'success');
                focusInlineComposer();
                return;
            }

            closeModal();
            applyState(data);
            toast('Card criado.', 'success');
            await openCard(data.card.id);
        } catch (error) {
            toast(error.message, 'error');
            setBusy(submit, false);
            if (inline) {
                focusInlineComposer();
            }
        }
    }

    /* -------------------------------------------------------------- lists */

    function openListModal(existing) {
        var used = {};
        state.lists.forEach(function (list) {
            if (!existing || Number(list.id) !== Number(existing.id)) {
                if (list.category) {
                    used[list.category] = true;
                }
            }
        });

        var categoryOptions = '';
        Object.keys(labels.categories || {}).forEach(function (value) {
            if (value === '' || !used[value] || (existing && existing.category === value)) {
                categoryOptions += '<option value="' + attr(value) + '"'
                    + (existing && existing.category === value ? ' selected' : '') + '>'
                    + esc(labels.categories[value]) + '</option>';
            }
        });

        var isFlowList = existing && existing.category;
        var categoryField = isMaintenance
            ? '<div class="tralla-field"><label for="tralla-list-category">Categoria do fluxo</label>'
                + (isFlowList
                    ? '<input type="text" value="' + attr(existing.category_label || existing.category) + '" disabled>'
                        + '<input type="hidden" name="category" value="' + attr(existing.category) + '">'
                        + '<small>A categoria de uma lista do fluxo não pode ser alterada.</small>'
                    : '<select id="tralla-list-category" name="category">' + categoryOptions + '</select>'
                        + '<small>Deixe em branco para uma lista normal dentro do quadro de manutenção.</small>')
                + '</div>'
            : '';

        var color = safeColor(existing && existing.color, palette[existing && existing.category] || '#97a0af');

        showModal('<form id="tralla-list-form" class="tralla-form" data-list-id="'
            + (existing ? Number(existing.id) : '') + '" novalidate>'
            + '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-list"></i> ' + (existing ? 'Editar lista' : 'Nova lista') + '</h2>'
            + '<p>' + esc(state.board.name) + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">'
            + '<div class="tralla-field"><label for="tralla-list-name">Nome da lista *</label>'
            + '<input id="tralla-list-name" type="text" name="name" maxlength="255" required autofocus value="'
            + attr(existing ? existing.name : '') + '"></div>'
            + categoryField
            + '<div class="tralla-field"><label for="tralla-list-color">Cor</label>'
            + '<input id="tralla-list-color" type="color" name="color" value="' + attr(color) + '"></div>'
            + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-check"></i> ' + (existing ? 'Salvar' : 'Criar lista') + '</button>'
            + '</div></form>');
    }

    async function submitList(form, existing) {
        var nameEl = form.querySelector('[name="name"]');
        var categoryEl = form.querySelector('[name="category"]');
        var colorEl = form.querySelector('[name="color"]');
        var name = nameEl ? nameEl.value.trim() : '';

        if (!name) {
            toast('Informe o nome da lista.', 'error');
            return;
        }

        var payload = {
            name: name,
            color: colorEl ? colorEl.value : '#97a0bf',
            category: categoryEl ? categoryEl.value : ''
        };
        var submit = form.querySelector('button[type="submit"]');
        setBusy(submit, true);

        try {
            var data;
            if (existing) {
                payload.id = existing.id;
                data = await api('list_update', payload);
                toast('Lista atualizada.', 'success');
            } else {
                payload.boards_id = state.board.id;
                data = await api('list_add', payload);
                toast('Lista criada.', 'success');
            }
            closeModal();
            applyState(data);
        } catch (error) {
            toast(error.message, 'error');
            setBusy(submit, false);
        }
    }

    async function deleteList(listId) {
        var list = state.lists.find(function (item) {
            return Number(item.id) === Number(listId);
        });
        if (!list) {
            return;
        }

        var listConfirmed = await confirmDialog({
            title: 'Excluir lista',
            message: 'Excluir a lista "' + list.name + '"? Os cards precisam ter sido movidos antes.',
            icon: 'ti-trash',
            tone: 'danger',
            confirmLabel: 'Excluir lista'
        });
        if (!listConfirmed) {
            return;
        }
        try {
            var data = await api('list_delete', { id: list.id });
            applyState(data);
            toast('Lista excluída.', 'success');
        } catch (error) {
            toast(error.message, 'error');
        }
}


    /* ------------------------------------------------------------ events */

    root.addEventListener('click', function (event) {
        var historyButton = event.target.closest('[data-board-history]');
        if (historyButton) {
            event.preventDefault();
            openHistoryModal();
            return;
        }

        var deletedButton = event.target.closest('[data-board-deleted]');
        if (deletedButton) {
            event.preventDefault();
            openDeletedModal();
            return;
        }

        var addList = event.target.closest('[data-add-list], [data-tralla-add-list]');
        if (addList) {
            event.preventDefault();
            openListModal(null);
            return;
        }

        var addCard = event.target.closest('[data-add-card]');
        if (addCard) {
            event.preventDefault();
            if (addCard.disabled) {
                return;
            }
            openCreateCard(Number(addCard.dataset.addCard));
            return;
        }

        var cancelInline = event.target.closest('[data-inline-card-cancel]');
        if (cancelInline) {
            event.preventDefault();
            closeInlineCardComposer();
            return;
        }

        var editList = event.target.closest('[data-edit-list]');
        if (editList) {
            event.preventDefault();
            var list = state.lists.find(function (item) {
                return Number(item.id) === Number(editList.dataset.editList);
            });
            if (list) {
                openListModal(list);
            }
            return;
        }

        var deleteListButton = event.target.closest('[data-delete-list]');
        if (deleteListButton) {
            event.preventDefault();
            deleteList(Number(deleteListButton.dataset.deleteList));
            return;
        }

        var takeButton = event.target.closest('[data-card-take]');
        if (takeButton) {
            event.preventDefault();
            event.stopPropagation();
            handleTake(Number(takeButton.dataset.cardTake), takeButton);
            return;
        }

        var cardElement = event.target.closest('.tralla-card');
        if (cardElement) {
            openCard(Number(cardElement.dataset.cardId));
        }
    });

    root.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        if (event.target.closest('[data-card-take]')) {
            return;
        }
        var cardElement = event.target.closest('.tralla-card');
        if (cardElement) {
            event.preventDefault();
            openCard(Number(cardElement.dataset.cardId));
        }
    });

    listsEl.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-inline-card-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        submitCreateCard(form);
    });

    listsEl.addEventListener('input', function (event) {
        var input = event.target.closest('[data-inline-card-form] textarea[name="name"]');
        if (!input) {
            return;
        }
        syncInlineComposerSubmit(input.closest('[data-inline-card-form]'));
        resizeInlineComposer(input);
    });

    listsEl.addEventListener('keydown', function (event) {
        var form = event.target.closest('[data-inline-card-form]');
        if (!form) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeInlineCardComposer();
            return;
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            var input = inlineComposerInput(form);
            if (!input || !input.value.trim()) {
                return;
            }
            event.preventDefault();
            form.requestSubmit();
        }
    });

    listsEl.addEventListener('dragstart', function (event) {
        var cardElement = event.target.closest('.tralla-card');
        if (!cardElement || isMaintenance || !can.move_card) {
            event.preventDefault();
            return;
        }
        dragCardId = Number(cardElement.dataset.cardId);
        cardElement.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(dragCardId));
    });

    listsEl.addEventListener('dragend', function (event) {
        var cardElement = event.target.closest('.tralla-card');
        if (cardElement) {
            cardElement.classList.remove('is-dragging');
        }
        dragCardId = null;
        Array.prototype.forEach.call(listsEl.querySelectorAll('.is-drag-over, .is-drop-target'), function (element) {
            element.classList.remove('is-drag-over', 'is-drop-target');
        });
    });

    listsEl.addEventListener('dragover', function (event) {
        var container = event.target.closest('.tralla-cards');
        if (!container || dragCardId === null) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        container.classList.add('is-drag-over');
        var list = container.closest('.tralla-list');
        if (list) {
            list.classList.add('is-drop-target');
        }
    });

    listsEl.addEventListener('dragleave', function (event) {
        var container = event.target.closest('.tralla-cards');
        if (container && !container.contains(event.relatedTarget)) {
            container.classList.remove('is-drag-over');
            var list = container.closest('.tralla-list');
            if (list) {
                list.classList.remove('is-drop-target');
            }
        }
    });

    listsEl.addEventListener('drop', async function (event) {
        var container = event.target.closest('.tralla-cards');
        if (!container || dragCardId === null) {
            return;
        }
        event.preventDefault();
        var movingId = dragCardId;
        var listId = Number(container.dataset.listId);
        var rank = computeDropRank(container, movingId, event.clientY);
        dragCardId = null;

        try {
            var data = await api('card_move', {
                id: movingId,
                list_id: listId,
                rank: rank
            });
            applyState(data);
        } catch (error) {
            toast(error.message, 'error');
        }
    });

    modalRoot.addEventListener('click', function (event) {
        if (event.target.closest('[data-tralla-close]')) {
            closeModal();
            return;
        }

        var openCardButton = event.target.closest('[data-open-card]');
        if (openCardButton) {
            openCard(Number(openCardButton.dataset.openCard));
            return;
        }

        var saveButton = event.target.closest('[data-save-card]');
        if (saveButton) {
            saveCard(saveButton);
            return;
        }

        var actionButton = event.target.closest('[data-card-action]');
        if (actionButton) {
            handleCardAction(actionButton.dataset.cardAction, actionButton);
            return;
        }

        var addEditMachineButton = event.target.closest('[data-add-edit-machine]');
        if (addEditMachineButton) {
            var editMachines = modalRoot.querySelector('#tralla-edit-machines');
            if (editMachines) {
                var emptyEdit = editMachines.querySelector('.tralla-empty-mini');
                if (emptyEdit) {
                    emptyEdit.remove();
                }
                editMachines.insertAdjacentHTML('beforeend', editNewMachineRow());
            }
            return;
        }

        var removeEditMachineButton = event.target.closest('[data-remove-edit-machine]');
        if (removeEditMachineButton) {
            var editMachineRowEl = removeEditMachineButton.closest('[data-edit-machine]');
            if (editMachineRowEl) {
                editMachineRowEl.remove();
            }
            return;
        }

        var removeEditNewButton = event.target.closest('[data-remove-edit-new]');
        if (removeEditNewButton) {
            var editNewMachineEl = removeEditNewButton.closest('[data-edit-new-machine]');
            if (editNewMachineEl) {
                editNewMachineEl.remove();
            }
            return;
        }

        var restoreButton = event.target.closest('[data-restore-card]');
        if (restoreButton) {
            restoreDeletedCard(Number(restoreButton.dataset.restoreCard), restoreButton);
            return;
        }

        var historyPageButton = event.target.closest('[data-history-page]');
        if (historyPageButton) {
            var pageValue = historyPageButton.dataset.historyPage;
            var targetPage;
            if (pageValue === 'prev') {
                targetPage = Math.max(1, historyPage - 1);
            } else if (pageValue === 'next') {
                targetPage = Math.min(historyPages, historyPage + 1);
            } else {
                targetPage = Number(pageValue);
            }
            if (!targetPage || targetPage < 1 || targetPage > historyPages || targetPage === historyPage) {
                return;
            }
            historyPage = targetPage;
            openHistoryModal();
            return;
        }

        if (event.target.closest('[data-history-clear]')) {
            historyFilters = { card_id: '', date_from: '', date_to: '' };
            historyPage = 1;
            openHistoryModal();
            return;
        }

        if (event.target.closest('[data-add-create-machine]')) {
            addCreateMachineRow();
            return;
        }

        var removeRowButton = event.target.closest('[data-remove-create-machine]');
        if (removeRowButton) {
            var row = removeRowButton.closest('[data-create-machine]');
            if (row && row.parentElement) {
                row.remove();
            }
        }
    });

    modalRoot.addEventListener('submit', function (event) {
        var form = event.target;

        if (form.id === 'tralla-split-form') {
            event.preventDefault();
            submitSplitCard(form);
            return;
        }

        if (form.id === 'tralla-edit-form') {
            event.preventDefault();
            submitEditRequest(form);
            return;
        }

        if (form.id === 'tralla-create-card-form') {
            event.preventDefault();
            submitCreateCard(form);
            return;
        }

        if (form.id === 'tralla-list-form') {
            event.preventDefault();
            var existing = form.dataset.listId
                ? state.lists.find(function (list) {
                    return Number(list.id) === Number(form.dataset.listId);
                })
                : null;
            submitList(form, existing);
            return;
        }

        if (form.id === 'tralla-history-form') {
            event.preventDefault();
            var cardInput = form.querySelector('[name="card_id"]');
            var dateFromInput = form.querySelector('[name="date_from"]');
            var dateToInput = form.querySelector('[name="date_to"]');
            historyFilters = {
                card_id: cardInput ? cardInput.value.trim() : '',
                date_from: dateFromInput ? dateFromInput.value : '',
                date_to: dateToInput ? dateToInput.value : ''
            };
            historyPage = 1;
            openHistoryModal();
        }
    });

    modalRoot.addEventListener('input', function (event) {
        var createRow = event.target.closest('[data-create-machine]');
        if (createRow) {
            updateCreateMachineSummary(createRow);
        }

        var field = event.target.closest('[data-machine-field]');
        if (!field) {
            return;
        }
        if (field.dataset.machineField === 'report'
            || field.dataset.machineField === 'label'
            || field.dataset.machineField === 'serial') {
            var machine = field.closest('[data-machine-id]');
            if (machine) {
                scheduleMachineSave(machine);
            }
        }
    });

    modalRoot.addEventListener('change', async function (event) {
        var createRow = event.target.closest('[data-create-machine]');
        if (createRow) {
            updateCreateMachineSummary(createRow);
        }

        var field = event.target.closest('[data-machine-field]');
        if (field) {
            var name = field.dataset.machineField;
            if (name === 'status' || field.type === 'checkbox') {
                var machine = field.closest('[data-machine-id]');
                if (machine) {
                    saveMachine(machine);
                }
            }
            return;
        }

        var modelSelect = event.target.closest('[data-create-model]');
        if (modelSelect && modelSelect.value === '__new') {
            var modelName = await promptDialog({
                title: 'Novo modelo de máquina',
                message: 'Cadastre um modelo para usar nas máquinas deste card.',
                label: 'Nome do modelo',
                placeholder: 'Ex.: Notebook Dell Latitude',
                icon: 'ti-device-desktop',
                required: true,
                requiredMessage: 'Informe o nome do modelo.',
                confirmLabel: 'Cadastrar modelo'
            });
            if (modelName === null) {
                modelSelect.value = '';
                return;
            }
            try {
                var model = await createModel(modelName);
                Array.prototype.forEach.call(modalRoot.querySelectorAll('[data-create-model]'), function (select) {
                    var option = document.createElement('option');
                    option.value = String(model.id);
                    option.textContent = model.name;
                    select.appendChild(option);
                });
                modelSelect.value = String(model.id);
                if (createRow) {
                    updateCreateMachineSummary(createRow);
                }
            } catch (error) {
                toast(error.message, 'error');
                modelSelect.value = '';
                if (createRow) {
                    updateCreateMachineSummary(createRow);
                }
            }
        }

        var editModelSelect = event.target.closest('[data-edit-new-model]');
        if (editModelSelect && editModelSelect.value === '__new') {
            var editModelName = await promptDialog({
                title: 'Novo modelo de máquina',
                message: 'Cadastre um modelo para usar nas máquinas deste card.',
                label: 'Nome do modelo',
                placeholder: 'Ex.: Notebook Dell Latitude',
                icon: 'ti-device-desktop',
                required: true,
                requiredMessage: 'Informe o nome do modelo.',
                confirmLabel: 'Cadastrar modelo'
            });
            if (editModelName === null) {
                editModelSelect.value = '';
                return;
            }
            try {
                var editModel = await createModel(editModelName);
                Array.prototype.forEach.call(modalRoot.querySelectorAll('[data-edit-new-model]'), function (select) {
                    var option = document.createElement('option');
                    option.value = String(editModel.id);
                    option.textContent = editModel.name;
                    select.appendChild(option);
                });
                editModelSelect.value = String(editModel.id);
            } catch (error) {
                toast(error.message, 'error');
                editModelSelect.value = '';
            }
        }
    });

    modalRoot.addEventListener('focusout', function (event) {
        var field = event.target.closest('[data-machine-field]');
        if (!field) {
            return;
        }
        var name = field.dataset.machineField;
        if (name === 'report' || name === 'label' || name === 'serial') {
            var machine = field.closest('[data-machine-id]');
            if (machine) {
                flushMachineSave(machine);
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modalRoot && modalRoot.innerHTML) {
            closeModal();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            schedulePoll(600);
        }
    });

    bindSearch();
    schedulePoll(pollInterval);
    renderBoard();
})();
