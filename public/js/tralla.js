/**
 * [URE] Tralla — Kanban e modal de card.
 * Sem dependências externas; usa a API JSON do plugin.
 */
(function () {
    'use strict';

    var bootstrapEl = document.getElementById('tralla-bootstrap');
    var root = document.getElementById('tralla-kanban');
    var boardModal = document.getElementById('tralla-board-create');

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
        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-tralla-modal="tralla-board-create"]')) {
                event.preventDefault();
                openBoardModal();
                return;
            }
            if (event.target.closest('[data-tralla-close="tralla-board-create"]')) {
                event.preventDefault();
                closeBoardModal();
                return;
            }
            if (event.target === boardModal) {
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
            layer.innerHTML = '<div class="tralla-dialog-backdrop" data-dialog-cancel></div>'
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
    var isMaintenance = !!state.board.is_maintenance;
    var can = state.rights || {};
    var labels = state.labels || {};
    var activeCard = null;
    var dragCardId = null;
    var lastFocused = null;
    var shouldRefreshBoard = false;
    var saveTimers = {};

    var palette = {
        '': '#97a0af',
        maintenance_pending: '#ff9f1a',
        maintenance_in_progress: '#0079bf',
        maintenance_approval: '#c377e0',
        maintenance_withdrawal: '#f2d600',
        maintenance_done: '#61bd4f'
    };

    var statusClasses = {
        pending: 'pending',
        in_progress: 'progress',
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

    function toast(message, type) {
        if (!toastRoot) {
            return;
        }
        var element = document.createElement('div');
        element.className = 'tralla-toast' + (type === 'error' ? ' is-error' : (type === 'success' ? ' is-success' : ''));
        var icon = type === 'error' ? 'ti-alert-triangle' : (type === 'success' ? 'ti-circle-check' : 'ti-info-circle');
        element.innerHTML = '<i class="ti ' + icon + '"></i><span>' + esc(message) + '</span>';
        toastRoot.appendChild(element);
        window.setTimeout(function () {
            element.style.opacity = '0';
            element.style.transform = 'translateY(6px)';
            window.setTimeout(function () {
                element.remove();
            }, 220);
        }, 3800);
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
        renderBoard();
    }

    async function refreshState() {
        var data = await api('state', { boards_id: state.board.id });
        state.lists = data.lists || [];
        state.cards = data.cards || [];
        renderBoard();
    }

    function statusPill(card) {
        if (!isMaintenance) {
            return '';
        }
        var label = card.status_label || labels.statuses[card.status] || card.status || '';
        if (!label) {
            return '';
        }
        return '<span class="tralla-pill ' + esc(statusClasses[card.status] || '') + '">'
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

    function renderBoard() {
        if (!listsEl) {
            return;
        }

        var scrollLeft = listsEl.scrollLeft;
        var lists = Array.isArray(state.lists) ? state.lists : [];

        if (!lists.length) {
            listsEl.innerHTML = '<div class="tralla-empty">'
                + '<i class="ti ti-layout-kanban"></i>'
                + '<h2>Este quadro ainda não tem listas</h2>'
                + '<p>Crie a primeira lista para começar a organizar os cards.</p>'
                + (can.create
                    ? '<button type="button" class="tralla-btn tralla-btn-primary" data-add-list>'
                        + '<i class="ti ti-plus"></i> Criar lista</button>'
                    : '')
                + '</div>';
            return;
        }

        var addListColumn = can.create
            ? '<button type="button" class="tralla-add-list-column" data-add-list>'
                + '<i class="ti ti-plus" aria-hidden="true"></i>'
                + '<span>Adicionar outra lista</span>'
                + '</button>'
            : '';

        listsEl.innerHTML = lists.map(renderList).join('') + addListColumn;
        listsEl.scrollLeft = scrollLeft;
    }

    function renderList(list) {
        var listId = Number(list.id);
        var listCards = state.cards.filter(function (card) {
            return Number(card.plugin_tralla_lists_id) === listId;
        }).sort(function (left, right) {
            return Number(left.rank) - Number(right.rank) || Number(left.id) - Number(right.id);
        });

        var color = safeColor(list.color, palette[list.category] || palette['']);
        var actions = '';

        if (can.update) {
            actions += '<button type="button" class="tralla-icon-btn" data-edit-list="' + listId + '" title="Editar lista">'
                + '<i class="ti ti-pencil"></i></button>';
        }
        if (can['delete'] && !list.category) {
            actions += '<button type="button" class="tralla-icon-btn" data-delete-list="' + listId + '" title="Excluir lista">'
                + '<i class="ti ti-trash"></i></button>';
        }

        var addCard = can.create
            ? '<button type="button" class="tralla-add-card" data-add-card="' + listId + '">'
                + '<i class="ti ti-plus"></i> Adicionar card</button>'
            : '';

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
            + addCard
            + '</section>';
    }

    function renderCard(card) {
        var cardId = Number(card.id);
        var canDrag = !isMaintenance && !!can.update;
        var machineCount = Number(card.machine_count || 0);
        var progress = card.progress || { total: machineCount, filled: 0, percent: 0 };
        var showProgress = isMaintenance && (machineCount > 0 || Number(progress.total) > 0);
        var statusLabel = isMaintenance ? (card.status_label || labels.statuses[card.status] || '') : '';
        var statusClass = statusClasses[card.status] || '';
        var label = statusLabel
            ? '<span class="tralla-card-label tralla-card-label-' + esc(statusClass) + '">' + esc(statusLabel) + '</span>'
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

        var machineSummaryText = String(card.machine_summary || '').trim();
        var machineBadgeText = machineSummaryText || (machineCount ? machineCount + ' máquina(s)' : '');
        var machineBadge = machineCount
            ? '<span class="tralla-card-badge tralla-card-machines" title="' + attr(machineBadgeText) + '">'
                + '<i class="ti ti-device-desktop" aria-hidden="true"></i><span>' + esc(machineBadgeText) + '</span></span>'
            : '';

        var badges = '';
        if (showProgress) {
            badges += progressMarkup(progress, Number(progress.total || machineCount), Number(progress.filled || 0));
        }
        badges += machineBadge + lock;

        return '<article class="tralla-card' + (card.is_locked ? ' is-locked' : '')
            + '" data-card-id="' + cardId + '"'
            + (canDrag ? ' draggable="true"' : '')
            + ' tabindex="0" role="button" aria-label="Abrir card ' + attr(card.name) + '">'
            + '<span class="tralla-card-edit" aria-hidden="true"><i class="ti ti-pencil"></i></span>'
            + label
            + '<div class="tralla-card-top">'
            + '<span class="tralla-card-code">' + esc(card.code || ('#' + cardId)) + '</span>'
            + source
            + '</div>'
            + '<h4>' + esc(card.name) + '</h4>'
            + (card.description ? '<p class="tralla-card-desc">' + esc(card.description) + '</p>' : '')
            + ((badges || tech)
                ? '<div class="tralla-card-footer">'
                    + '<div class="tralla-card-badges">' + badges + '</div>'
                    + tech
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
            note = 'Preencha o relatório e o status de todas as máquinas para liberar a finalização.';
        }

        if (permissions.approve) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="approve">'
                + '<i class="ti ti-shield-check"></i> Aprovar</button>';
            buttons += '<button type="button" class="tralla-btn tralla-btn-danger" data-card-action="reject">'
                + '<i class="ti ti-shield-x"></i> Reprovar</button>';
        }

        if (permissions.withdraw) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="withdraw">'
                + '<i class="ti ti-package-export"></i> Confirmar retirada</button>';
        }

        if (permissions['delete']) {
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

    function renderHistory(card) {
        var history = Array.isArray(card.history) ? card.history : [];
        if (!history.length) {
            return '<section class="tralla-detail-section"><h3><i class="ti ti-history"></i> Histórico</h3>'
                + '<p class="tralla-empty-mini">Sem histórico registrado.</p></section>';
        }

        return '<section class="tralla-detail-section"><h3><i class="ti ti-history"></i> Histórico</h3>'
            + '<div class="tralla-history">'
            + history.map(function (entry) {
                return '<div class="tralla-history-item">'
                    + '<div class="tralla-history-dot"><span class="tralla-avatar">' + esc(initials(entry.user_name || 'Sistema')) + '</span></div>'
                    + '<div class="tralla-history-body">'
                    + '<strong>' + esc(entry.action_label || entry.action) + '</strong>'
                    + '<small>' + esc(entry.user_name || 'Sistema') + ' · ' + esc(entry.date_formatted || formatDate(entry.date_creation)) + '</small>'
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
                || (report !== '' && (machine.status === 'done' || machine.status === 'not_repairable'));
        }).length;
        return {
            total: total,
            filled: filled,
            percent: total ? Math.round((filled / total) * 100) : 0
        };
    }

    function renderMachine(machine, canEdit, permissions, sequence) {
        var id = Number(machine.id);
        var modelName = String(machine.model_name || '');
        var title = machine.label
            || (modelName ? modelName + (sequence ? ' · unidade ' + sequence : '') : ('Máquina #' + id));
        var disabled = canEdit ? '' : ' disabled';
        var deleteButton = (canEdit && permissions.delete_machine)
            ? '<button type="button" class="tralla-icon-btn" data-delete-machine="' + id + '" title="Remover máquina">'
                + '<i class="ti ti-trash"></i></button>'
            : '';

        return '<div class="tralla-machine" data-machine-id="' + id + '">'
            + '<div class="tralla-machine-head">'
            + '<div class="tralla-machine-title">'
            + '<span data-machine-title-text>' + esc(title) + '</span>'
            + '<small>' + esc(machine.model_name || 'Modelo não informado')
            + (machine.serial ? ' · Série ' + esc(machine.serial) : '') + '</small>'
            + '</div>'
            + '<div style="align-items:center;display:flex;gap:6px">'
            + '<span class="tralla-save-state" data-machine-state></span>'
            + deleteButton
            + '</div></div>'
            + '<div class="tralla-machine-grid">'
            + '<div class="tralla-field"><label>Status</label>'
            + '<select data-machine-field="status"' + disabled + '>' + machineStatusOptions(machine.status || '') + '</select></div>'
            + '<div class="tralla-field"><label>Etiqueta / patrimônio</label>'
            + '<input type="text" data-machine-field="label" maxlength="255" value="' + attr(machine.label || '') + '"' + disabled + '></div>'
            + '<div class="tralla-field"><label>Número de série</label>'
            + '<input type="text" data-machine-field="serial" maxlength="255" value="' + attr(machine.serial || '') + '"' + disabled + '></div>'
            + '</div>'
            + '<div class="tralla-field" style="margin-top:9px"><label>Relatório técnico</label>'
            + '<textarea class="tralla-machine-report" data-machine-field="report" maxlength="200000"'
            + disabled + ' placeholder="Descreva o diagnóstico e o serviço executado...">' + esc(machine.report || '') + '</textarea></div>'
            + '<div class="tralla-machine-flags">'
            + '<label class="tralla-check"><input type="checkbox" data-machine-field="service_done" value="1"'
            + (Number(machine.service_done) ? ' checked' : '') + disabled + '> Serviço realizado</label>'
            + '<label class="tralla-check"><input type="checkbox" data-machine-field="needs_inventory" value="1"'
            + (Number(machine.needs_inventory) ? ' checked' : '') + disabled + '> Necessita inventariar</label>'
            + '<span class="tralla-pill ' + (machine.is_filled ? 'done' : 'pending') + '">'
            + (machine.is_filled ? 'Preenchida' : 'Pendente') + '</span>'
            + '</div></div>';
    }

    function renderMachines(card) {
        var permissions = card.permissions || {};
        var isApproval = Number(card.stage) === 2;
        var machines = isApproval && card.parent
            ? (card.parent.machines || [])
            : (card.machines || []);
        var progress = machineProgress(machines);
        var canEdit = !isApproval && !!permissions.edit_machines;
        var addMachine = (!isApproval && permissions.add_machine)
            ? '<details class="tralla-add-machine"><summary><i class="ti ti-plus"></i> Adicionar máquina</summary>'
                + '<div class="tralla-add-machine-grid">'
                + '<div class="tralla-field"><label>Modelo</label><select id="tralla-machine-model">'
                + modelOptions('') + '</select></div>'
                + '<div class="tralla-field"><label>Etiqueta / patrimônio</label>'
                + '<input type="text" id="tralla-machine-label" maxlength="255"></div>'
                + '<div class="tralla-field"><label>Número de série</label>'
                + '<input type="text" id="tralla-machine-serial" maxlength="255"></div>'
                + '</div>'
                + '<button type="button" class="tralla-btn tralla-btn-primary" data-add-machine style="margin-top:8px">'
                + '<i class="ti ti-plus"></i> Adicionar</button></details>'
            : '';

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
            + addMachine
            + '</section>';
    }

    function renderApproval(card) {
        if (Number(card.stage) !== 2) {
            return '';
        }
        var parent = card.parent
            ? 'Card de origem: ' + card.parent.code + ' — ' + card.parent.name
            : 'Aguardando a decisão de um aprovador.';
        var openParent = card.parent
            ? '<button type="button" class="tralla-btn tralla-btn-ghost" data-open-card="' + Number(card.parent.id) + '">'
                + '<i class="ti ti-arrow-up-right"></i> Ver origem</button>'
            : '';

        return '<div class="tralla-approval-banner">'
            + '<i class="ti ti-shield-check"></i>'
            + '<div style="flex:1"><strong>Aguardando aprovação</strong><small>' + esc(parent) + '</small></div>'
            + openParent
            + '</div>';
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

    async function handleCardAction(action, button) {
        if (!activeCard) {
            return;
        }

        var cardId = activeCard.id;

        try {
            if (action === 'delete') {
                var deleteConfirmed = await confirmDialog({
                    title: 'Excluir card',
                    message: 'Excluir este card definitivamente? Esta ação não pode ser desfeita.',
                    icon: 'ti-trash',
                    tone: 'danger',
                    confirmLabel: 'Excluir card'
                });
                if (!deleteConfirmed) {
                    return;
                }
                setBusy(button, true);
                var deleted = await api('card_delete', { id: cardId });
                applyState(deleted);
                closeModal();
                toast('Card excluído.', 'success');
                return;
            }

            if (action === 'reject') {
                var reason = await promptDialog({
                    title: 'Reprovar manutenção',
                    message: 'O card voltará para Em Andamento com este registro no histórico.',
                    label: 'Motivo (opcional)',
                    multiline: true,
                    placeholder: 'Descreva o motivo da reprovação...',
                    icon: 'ti-shield-x',
                    tone: 'danger',
                    confirmLabel: 'Reprovar'
                });
                if (reason === null) {
                    return;
                }
                setBusy(button, true);
                var rejected = await api('reject_card', { id: cardId, reason: reason });
                applyState(rejected);
                toast('Manutenção reprovada. O card voltou para Em Andamento.', 'success');
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
                var finalizeConfirmed = await confirmDialog({
                    title: 'Finalizar manutenção',
                    message: 'Finalizar a manutenção e enviar para aprovação?',
                    icon: 'ti-checkup-list',
                    confirmLabel: 'Finalizar'
                });
                if (!finalizeConfirmed) {
                    return;
                }
            }

            if (action === 'approve') {
                var approveConfirmed = await confirmDialog({
                    title: 'Aprovar manutenção',
                    message: 'Aprovar esta manutenção e liberar a retirada?',
                    icon: 'ti-shield-check',
                    confirmLabel: 'Aprovar'
                });
                if (!approveConfirmed) {
                    return;
                }
            }

            setBusy(button, true);
            var data;

            if (action === 'take') {
                data = await api('take_card', { id: cardId });
                applyState(data);
                toast('Card atribuído a você.', 'success');
                await openCard(cardId);
            } else if (action === 'finalize') {
                data = await api('finalize_card', { id: cardId });
                applyState(data);
                toast('Manutenção finalizada e enviada para aprovação.', 'success');
                await openCard(data.approval ? data.approval.id : cardId);
            } else if (action === 'approve') {
                data = await api('approve_card', { id: cardId });
                applyState(data);
                toast('Manutenção aprovada. Card liberado para retirada.', 'success');
                await openCard(data.card.id);
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
                    && (local.status === 'done' || local.status === 'not_repairable');
            }
            var progress = data.progress || machineProgress(machines);
            updateProgressDom(progress);
        }
    }

    async function saveMachine(machineEl) {
        if (!machineEl || !machineEl.isConnected) {
            return;
        }
        var machineId = Number(machineEl.dataset.machineId);
        if (!machineId) {
            return;
        }
        setSaveState(machineEl, 'saving');
        try {
            var data = await api('machine_update', collectMachineFields(machineEl));
            applyMachineResponse(machineEl, data);
            setSaveState(machineEl, 'saved');
            shouldRefreshBoard = true;
        } catch (error) {
            setSaveState(machineEl, 'error');
            toast(error.message, 'error');
        }
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
        if (can.create) {
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

    function renderEntityOptions(query) {
        var list = document.getElementById('tralla-create-name-list');
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
                + ' id="tralla-create-entity-' + index + '" aria-selected="false"'
                + ' data-entity-name="' + attr(name) + '">'
                + '<span>' + esc(name) + '</span>' + path + '</button>';
        }).join('');
        list.hidden = false;
    }

    function closeEntityOptions() {
        var input = document.getElementById('tralla-create-name');
        var list = document.getElementById('tralla-create-name-list');
        if (list) {
            list.hidden = true;
        }
        if (input) {
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }
    }

    function selectEntityOption(input, option) {
        if (!input || !option) {
            return;
        }
        input.value = option.dataset.entityName || '';
        closeEntityOptions();
    }

    function setupEntityCombobox() {
        var input = document.getElementById('tralla-create-name');
        var list = document.getElementById('tralla-create-name-list');
        if (!input || !list) {
            return;
        }

        function openList() {
            renderEntityOptions(input.value);
            input.setAttribute('aria-expanded', 'true');
        }

        input.addEventListener('focus', openList);
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
                closeEntityOptions();
            }
        });
        input.addEventListener('blur', function () {
            window.setTimeout(closeEntityOptions, 120);
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
            + '<div class="tralla-field"><label>Modelo *</label><select data-create-model>'
            + modelOptions('') + '</select></div>'
            + '<div class="tralla-field"><label>Quantidade *</label>'
            + '<input type="number" data-create-quantity min="1" max="' + quantityMax()
            + '" step="1" value="1" inputmode="numeric"></div>'
            + '</div>'
            + '<div class="tralla-create-machine-summary" data-create-summary aria-live="polite" hidden></div>'
            + '<button type="button" class="tralla-icon-btn" data-remove-create-machine title="Remover modelo">'
            + '<i class="ti ti-x"></i></button>';
        container.appendChild(row);
        updateCreateMachineSummary(row);
    }

    /* ------------------------------------------------------- create card */

    function openCreateCard(listId) {
        var list = state.lists.find(function (item) {
            return Number(item.id) === Number(listId);
        });
        if (!list) {
            return;
        }

        var sources = labels.source_choices || {};
        var sourceOptions = Object.keys(sources).map(function (value) {
            return '<option value="' + attr(value) + '"' + (value === 'manual' ? ' selected' : '') + '>'
                + esc(sources[value]) + '</option>';
        }).join('');

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
            + '<input id="tralla-create-name" type="text" name="name" maxlength="255" required autofocus autocomplete="off"'
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
            + '<div class="tralla-field"><label for="tralla-create-source">Origem</label>'
            + '<select id="tralla-create-source" name="source">' + sourceOptions + '</select></div>'
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
            setupEntityCombobox();
        }
    }

    async function submitCreateCard(form) {
        var nameEl = form.querySelector('[name="name"]');
        var sourceEl = form.querySelector('[name="source"]');
        var name = nameEl ? nameEl.value.trim() : '';

        if (!name) {
            toast('Informe o nome do card.', 'error');
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

        if (isMaintenance && !machines.length) {
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
                source: sourceEl ? sourceEl.value : 'manual',
                machines: machines
            });
            closeModal();
            applyState(data);
            toast('Card criado.', 'success');
            await openCard(data.card.id);
        } catch (error) {
            toast(error.message, 'error');
            setBusy(submit, false);
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

    async function addMachine(button) {
        if (!activeCard) {
            return;
        }

        var modelSelect = document.getElementById('tralla-machine-model');
        var labelEl = document.getElementById('tralla-machine-label');
        var serialEl = document.getElementById('tralla-machine-serial');

        if (!modelSelect) {
            return;
        }

        var modelId = Number(modelSelect.value);
        if (modelSelect.value === '__new') {
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
                modelSelect.innerHTML = modelOptions(String(model.id));
                modelSelect.value = String(model.id);
                modelId = Number(model.id);
            } catch (error) {
                toast(error.message, 'error');
                modelSelect.value = '';
                return;
            }
        }

        if (!modelId) {
            toast('Selecione um modelo de máquina.', 'error');
            return;
        }

        var cardId = activeCard.id;
        setBusy(button, true);

        try {
            var data = await api('machine_add', {
                card_id: cardId,
                model_id: modelId,
                label: labelEl ? labelEl.value : '',
                serial: serialEl ? serialEl.value : ''
            });
            activeCard = data.card;
            renderCardModal(activeCard);
            refreshState().catch(function () { return null; });
            toast('Máquina adicionada.', 'success');
        } catch (error) {
            toast(error.message, 'error');
            setBusy(button, false);
        }
    }

    async function deleteMachine(machineId) {
        if (!activeCard || !machineId) {
            return;
        }
        var machineConfirmed = await confirmDialog({
            title: 'Remover máquina',
            message: 'Remover esta máquina do card? O relatório registrado será perdido.',
            icon: 'ti-trash',
            tone: 'danger',
            confirmLabel: 'Remover máquina'
        });
        if (!machineConfirmed) {
            return;
        }

        var cardId = activeCard.id;
        try {
            var data = await api('machine_delete', { machine_id: machineId });
            activeCard = data.card;
            renderCardModal(activeCard);
            refreshState().catch(function () { return null; });
            toast('Máquina removida.', 'success');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /* ------------------------------------------------------------ events */

    root.addEventListener('click', function (event) {
        var addList = event.target.closest('[data-add-list], [data-tralla-add-list]');
        if (addList) {
            event.preventDefault();
            openListModal(null);
            return;
        }

        var addCard = event.target.closest('[data-add-card]');
        if (addCard) {
            event.preventDefault();
            openCreateCard(Number(addCard.dataset.addCard));
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

        var cardElement = event.target.closest('.tralla-card');
        if (cardElement) {
            openCard(Number(cardElement.dataset.cardId));
        }
    });

    root.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        var cardElement = event.target.closest('.tralla-card');
        if (cardElement) {
            event.preventDefault();
            openCard(Number(cardElement.dataset.cardId));
        }
    });

    listsEl.addEventListener('dragstart', function (event) {
        var cardElement = event.target.closest('.tralla-card');
        if (!cardElement || isMaintenance || !can.update) {
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
        if (event.target.matches('[data-tralla-modal-backdrop]')) {
            closeModal();
            return;
        }

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

        var addMachineButton = event.target.closest('[data-add-machine]');
        if (addMachineButton) {
            addMachine(addMachineButton);
            return;
        }

        var deleteMachineButton = event.target.closest('[data-delete-machine]');
        if (deleteMachineButton) {
            deleteMachine(Number(deleteMachineButton.dataset.deleteMachine));
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

    renderBoard();
})();
