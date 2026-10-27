/**
 * [URE] Tralla — Kanban e modal de card.
 * Sem dependências externas; usa a API JSON do plugin.
 */
(function () {
    'use strict';

    var bootstrapEl = document.getElementById('tralla-bootstrap');
    var root = document.getElementById('tralla-kanban');
    var boardModal = document.getElementById('tralla-board-create');
    var organizer = document.querySelector('[data-board-organizer]');

    if (organizer) {
        var draggedBoard = null;
        var placeholder = document.createElement('div');
        placeholder.className = 'tralla-board-drop-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');

        function dropzoneFor(target) {
            var group = target.closest('[data-group-id]');
            return group ? group.querySelector('[data-board-dropzone]') : null;
        }

        async function organize(action, data) {
            var body = new FormData();
            body.set('action', 'board_group_' + action);
            body.set('_glpi_csrf_token', organizer.dataset.csrfToken || '');
            Object.keys(data || {}).forEach(function (key) { body.set(key, String(data[key])); });
            var response = await fetch(organizer.dataset.ajaxUrl, {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var result;
            try { result = await response.json(); } catch (error) { throw new Error('Resposta inválida do servidor.'); }
            if (!response.ok || !result.ok) { throw new Error(result.error || 'Não foi possível salvar a organização.'); }
            window.location.reload();
        }

        var createGroup = organizer.querySelector('[data-board-group-create]');
        var groupFocus = document.querySelector('[data-group-create-focus]');
        if (groupFocus) {
            groupFocus.addEventListener('click', function () {
                createGroup.hidden = !createGroup.hidden;
                groupFocus.setAttribute('aria-expanded', createGroup.hidden ? 'false' : 'true');
                if (!createGroup.hidden) {
                    createGroup.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'end' });
                    createGroup.querySelector('[name="name"]').focus();
                } else {
                    groupFocus.focus();
                }
            });
        }
        createGroup.addEventListener('submit', function (event) {
            event.preventDefault();
            var name = createGroup.querySelector('[name="name"]').value.trim();
            if (!name) { return; }
            organize('create', { name: name }).catch(function (error) { alert(error.message); });
        });

        organizer.addEventListener('click', function (event) {
            var button = event.target.closest('[data-group-action]');
            if (!button) { return; }
            var action = button.dataset.groupAction;
            var data = { group_id: button.dataset.groupId };
            if (action === 'rename') {
                var name = window.prompt('Novo nome do grupo:', button.dataset.groupName);
                if (name === null) { return; }
                data.name = name.trim();
                if (!data.name) { return; }
            } else if (action === 'delete') {
                if (!window.confirm('Excluir o grupo "' + button.dataset.groupName + '"? Os quadros voltarão para Sem grupo.')) { return; }
            } else if (action === 'shift') {
                data.direction = button.dataset.direction;
            }
            organize(action, data).catch(function (error) { alert(error.message); });
        });

        organizer.addEventListener('dragstart', function (event) {
            var tile = event.target.closest('[data-board-id]');
            if (!tile) { return; }
            draggedBoard = tile;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', tile.dataset.boardId);
            event.dataTransfer.setDragImage(tile, 24, 24);
            requestAnimationFrame(function () { tile.classList.add('is-dragging'); });
        });

        organizer.addEventListener('dragover', function (event) {
            if (!draggedBoard) { return; }
            var zone = dropzoneFor(event.target);
            if (!zone) { return; }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            organizer.querySelectorAll('.is-drop-target').forEach(function (group) { group.classList.remove('is-drop-target'); });
            zone.closest('[data-group-id]').classList.add('is-drop-target');
            var next = event.target.closest('[data-board-id]');
            if (next && next.parentElement === zone) {
                if (event.clientY < next.getBoundingClientRect().top + next.offsetHeight / 2) {
                    zone.insertBefore(placeholder, next);
                } else {
                    next.after(placeholder);
                }
            } else if (event.target !== placeholder) {
                zone.append(placeholder);
            }
        });

        organizer.addEventListener('drop', function (event) {
            if (!draggedBoard) { return; }
            var zone = dropzoneFor(event.target);
            if (!zone) { return; }
            event.preventDefault();
            var next = placeholder.nextElementSibling;
            while (next && !next.matches('[data-board-id]')) { next = next.nextElementSibling; }
            var data = {
                board_id: draggedBoard.dataset.boardId,
                group_id: zone.closest('[data-group-id]').dataset.groupId,
                before_board_id: next ? next.dataset.boardId : 0
            };
            placeholder.remove();
            draggedBoard.classList.remove('is-dragging');
            draggedBoard = null;
            organize('move', data).catch(function (error) { alert(error.message); });
        });

        organizer.addEventListener('dragend', function () {
            placeholder.remove();
            organizer.querySelectorAll('.is-drop-target').forEach(function (group) { group.classList.remove('is-drop-target'); });
            if (draggedBoard) { draggedBoard.classList.remove('is-dragging'); }
            draggedBoard = null;
        });
    }
    var themeSelect = document.querySelector('[data-tralla-theme-select]');
    var themePreview = document.querySelector('[data-tralla-theme-preview]');

    if (themeSelect && themePreview) {
        themeSelect.addEventListener('change', function () {
            themePreview.className = 'tralla-board-create-theme-swatch tralla-edit-theme-preview tr-bg-' + themeSelect.value;
        });
    }

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

            var mode = config.mode === 'prompt' ? 'prompt' : (config.mode === 'info' ? 'info' : 'confirm');
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
                + (mode === 'info' ? (config.detailsHtml || '') : '')
                + '<p class="tralla-dialog-error" hidden></p>'
                + '<div class="tralla-dialog-actions">'
                + (mode === 'info' ? '' : '<button type="button" class="tralla-btn tralla-btn-ghost" data-dialog-cancel>' + dialogEscape(cancelLabel) + '</button>')
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

    /* Perfil do responsável, disponível na página inicial e no detalhe do card. */
    var profileLayer = null;
    var profilePreviousFocus = null;
    var profileRequest = 0;

    function closeUserProfile() {
        if (!profileLayer) { return; }
        profileLayer.remove();
        profileLayer = null;
        if ((!boardModal || boardModal.hidden)
            && !(document.getElementById('tralla-modal-root') || {}).innerHTML) {
            document.body.classList.remove('tralla-modal-open');
        }
        if (profilePreviousFocus && document.contains(profilePreviousFocus)) {
            profilePreviousFocus.focus();
        }
        profilePreviousFocus = null;
    }

    async function openUserProfile(trigger) {
        var usersId = Number(trigger.dataset.userProfile || 0);
        if (!usersId) { return; }
        var dash = document.querySelector('[data-user-profile-ajax]');
        var bootstrap = document.getElementById('tralla-bootstrap');
        var config = bootstrap ? JSON.parse(bootstrap.textContent || '{}') : {};
        var ajaxUrl = dash ? dash.dataset.userProfileAjax : (config.urls || {}).ajax;
        var csrf = dash ? dash.dataset.userProfileCsrf : config.csrf;
        if (!ajaxUrl) { return; }
        var requestId = ++profileRequest;
        profilePreviousFocus = trigger;
        var body = new FormData();
        body.set('action', 'user_profile');
        body.set('users_id', String(usersId));
        body.set('_glpi_csrf_token', csrf || '');
        try {
            var response = await fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
            var result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Não foi possível abrir o perfil.');
            }
            if (requestId !== profileRequest) { return; }
            closeUserProfile();
            profilePreviousFocus = trigger;
            var user = result.user;
            var stats = user.stats || {};
            var stage = trigger.dataset.userStage || '';
            var photo = user.picture_url
                ? '<img src="' + dialogEscape(user.picture_url) + '" alt="Foto de ' + dialogEscape(user.name) + '">'
                : '<span>' + dialogEscape(String(user.name || '?').charAt(0).toUpperCase()) + '</span>';
            var steps = [
                ['pending', 'Pendente', 'is-pending'],
                ['in_progress', 'Em andamento', 'is-progress'],
                ['withdrawal', 'Retirada', 'is-withdrawal'],
                ['done', 'Concluído', 'is-done']
            ];
            profileLayer = document.createElement('div');
            profileLayer.className = 'tralla-user-profile-layer';
            profileLayer.innerHTML = '<div class="tralla-modal-backdrop" data-user-profile-backdrop></div>'
                + '<section class="tralla-user-profile tralla-modal" role="dialog" aria-modal="true" aria-label="Informações de '
                + dialogEscape(user.name) + '">'
                + '<button type="button" class="tralla-icon-btn tralla-user-profile-close" data-user-profile-close aria-label="Fechar"><i class="ti ti-x"></i></button>'
                + '<div class="tralla-user-profile-head"><button type="button" class="tralla-user-profile-photo" data-user-photo-expand aria-label="Ampliar foto">'
                + photo + '</button><div><h2>' + dialogEscape(user.name) + '</h2>'
                + (user.email ? '<p>' + dialogEscape(user.email) + '</p>' : '')
                + '<small>Usuário GLPI #' + Number(user.id) + '</small></div></div>'
                + '<h3>Manutenções</h3><p class="tralla-user-profile-caption">Quadros de manutenção aos quais você tem acesso</p>'
                + '<div class="tralla-user-profile-stats">' + steps.map(function (step) {
                    return '<div class="' + step[2] + (stage === step[0] ? ' is-selected' : '') + '"><span>'
                        + step[1] + '</span><strong>' + Number(stats[step[0]] || 0) + '</strong></div>';
                }).join('') + '</div></section>';
            document.body.appendChild(profileLayer);
            document.body.classList.add('tralla-modal-open');
            bindBackdropDismiss(profileLayer.querySelector('[data-user-profile-backdrop]'), closeUserProfile);
            profileLayer.querySelector('[data-user-profile-close]').focus();
        } catch (error) {
            window.alert(error.message || 'Não foi possível abrir o perfil.');
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-user-profile]');
        if (trigger) {
            event.preventDefault();
            openUserProfile(trigger);
            return;
        }
        if (!profileLayer) { return; }
        if (event.target.closest('[data-user-profile-close]')) { closeUserProfile(); return; }
        var photo = event.target.closest('[data-user-photo-expand]');
        if (photo) {
            profileLayer.classList.toggle('is-photo-expanded');
            photo.setAttribute('aria-label', profileLayer.classList.contains('is-photo-expanded') ? 'Reduzir foto' : 'Ampliar foto');
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && profileLayer) {
            event.preventDefault();
            event.stopImmediatePropagation();
            closeUserProfile();
        }
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
    var isUpdates = !!state.board.is_updates;
    var can = state.can || state.rights || {};
    var labels = state.labels || {};
    var activeCard = null;
    var openCardRequest = 0;
    var dragCardId = null;
    var dragSource = null;
    var dragPreview = null;
    var moveInFlight = false;
    var lastFocused = null;
    var shouldRefreshBoard = false;
    var saveTimers = {};
    var machineSaveQueue = Promise.resolve();
    var inlineComposerListId = null;
    var historyFilters = {
        card_id: '', date_from: '', date_to: '', users_id: '', machine_query: '', model_id: '', history_action: ''
    };
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
        maintenance_done: '#61bd4f',
        update_future: '#7c8ba7',
        update_pending: '#f59e0b',
        update_in_progress: '#0284c7',
        update_done: '#22a35a'
    };

    var addCardBlockedCategories = {
        maintenance_in_progress: true,
        maintenance_approval: true,
        maintenance_withdrawal: true,
        maintenance_done: true,
        update_in_progress: true,
        update_done: true
    };

    var statusClasses = {
        pending: 'pending',
        in_progress: 'is-in-progress',
        waiting_approval: 'approval',
        withdrawal: 'withdrawal',
        done: 'done',
        approved: 'done',
        rejected: 'danger',
        update_future: 'pending',
        update_pending: 'pending',
        update_in_progress: 'is-in-progress',
        update_done: 'done'
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

    function avatar(name, pictureUrl) {
        return '<span class="tralla-avatar">' + esc(initials(name))
            + (pictureUrl
                ? '<img class="tralla-avatar-photo" src="' + attr(pictureUrl) + '" alt="" loading="lazy">'
                : '')
            + '</span>';
    }

    document.addEventListener('error', function (event) {
        if (event.target.classList && event.target.classList.contains('tralla-avatar-photo')) {
            event.target.remove();
        }
    }, true);

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
        if (kind === 'split') {
            return 'Aprovação de separação';
        }
        if (kind === 'call_release') {
            return 'Liberação de chamado';
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
            if (value instanceof Blob) {
                formData.set(key, value, 'chunk.bin');
            } else if (typeof value === 'object') {
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
        if (dragCardId !== null || moveInFlight || inlineComposerListId !== null) {
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
        if (!isMaintenance && !isUpdates) {
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
        var familyRoot = Number(card.code_root_cards_id || card.family_cards_id || 0);
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

    var slaSortedCategories = {
        maintenance_pending: true,
        maintenance_in_progress: true,
        maintenance_approval: true
    };

    function cardSlaForSort(card) {
        if (card && card.sla && card.sla.priority) {
            return card.sla;
        }
        if (card && Number(card.stage) === 2 && Number(card.parent_cards_id) > 0) {
            var parent = (state.cards || []).find(function (item) {
                return Number(item.id) === Number(card.parent_cards_id);
            });
            if (parent && parent.sla && parent.sla.priority) {
                return parent.sla;
            }
        }
        return null;
    }

    function slaSortKey(card) {
        var sla = cardSlaForSort(card);
        if (!sla) {
            return { group: 2, deadline: Infinity, priority: 9 };
        }
        var priority = Number(String(sla.priority).replace(/\D/g, '')) || 9;
        if (!sla.next || !sla.next.deadline) {
            return { group: 1, deadline: Infinity, priority: priority };
        }
        var time = new Date(String(sla.next.deadline).replace(' ', 'T')).getTime();
        return { group: 0, deadline: isNaN(time) ? Infinity : time, priority: priority };
    }

    function compareBySla(left, right) {
        var a = slaSortKey(left);
        var b = slaSortKey(right);
        if (a.group !== b.group) { return a.group - b.group; }
        if (a.deadline !== b.deadline) { return a.deadline < b.deadline ? -1 : 1; }
        if (a.priority !== b.priority) { return a.priority - b.priority; }
        return Number(left.rank) - Number(right.rank) || Number(left.id) - Number(right.id);
    }

    function renderList(list, cards) {
        var listId = Number(list.id);
        var sortBySla = isMaintenance && !!slaSortedCategories[list.category];
        var listCards = (Array.isArray(cards) ? cards : state.cards).filter(function (card) {
            return Number(card.plugin_tralla_lists_id) === listId;
        }).sort(sortBySla ? compareBySla : function (left, right) {
            return Number(left.rank) - Number(right.rank) || Number(left.id) - Number(right.id);
        });

        var color = safeColor(list.color, palette[list.category] || palette['']);
        var actions = '';

        if (can.manage_lists || (isUpdates && state.board.can_rename_lists)) {
            actions += '<button type="button" class="tralla-icon-btn" data-edit-list="' + listId + '" title="Editar lista">'
                + '<i class="ti ti-pencil"></i></button>';
        }
        if (can.manage_lists && !list.category) {
            actions += '<button type="button" class="tralla-icon-btn" data-delete-list="' + listId + '" title="Excluir lista">'
                + '<i class="ti ti-trash"></i></button>';
        }

        var composerOpen = !isMaintenance && !isUpdates && can.create_card && Number(inlineComposerListId) === listId;
        var addCardBlocked = !!addCardBlockedCategories[list.category];
        var addCard = can.create_card && !composerOpen && !addCardBlocked
            ? '<button type="button" class="tralla-add-card" data-add-card="' + listId + '">'
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
        var canDrag = !isMaintenance && !isUpdates && !!can.move_card && searchQuery === '' && !departing;
        var machineCount = Number(card.machine_count || 0);
        var progress = card.progress || { total: machineCount, filled: 0, percent: 0 };
        var showProgress = isMaintenance && (machineCount > 0 || Number(progress.total) > 0);
        var statusLabel = (isMaintenance || isUpdates) ? (card.status_label || labels.statuses[card.status] || '') : '';
        var statusClass = statusClasses[card.status] || '';
        var labelClass = approvalKindClass(card) || statusClass;
        var cardApprovalClass = Number(card.stage) === 2 ? approvalKindClass(card) : '';
        var label = statusLabel
            ? '<span class="tralla-card-label tralla-card-label-' + esc(labelClass) + '">' + esc(statusLabel) + '</span>'
            : '';
        var tech = '';

        if (card.technician_name) {
            tech = '<span class="tralla-card-avatar" title="Responsável: ' + attr(card.technician_name) + '">'
                + avatar(card.technician_name, card.technician_picture_url) + '</span>';
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
        badges += machineBadge + lock + slaBadges(card);

        var takeButton = !departing && card.permissions && card.permissions.update_take
            ? '<button type="button" class="tralla-card-take" data-update-take="' + cardId + '" title="Fazer esta atualização">'
                + '<i class="ti ti-hand-stop" aria-hidden="true"></i> Fazer</button>'
            : (!departing && card.permissions && card.permissions.take
            ? '<button type="button" class="tralla-card-take" data-card-take="' + cardId + '" title="Pegar para mim">'
                + '<i class="ti ti-hand-stop" aria-hidden="true"></i> Pegar</button>'
            : '');
        // Botão "Assinar" removido dos cards do quadro; a assinatura continua no detalhe do card.
        var signButton = '';
        var termButton = card.assignature_term_url
            ? '<a class="tralla-card-term" href="' + attr(card.assignature_term_url) + '" target="_blank" rel="noopener noreferrer" title="Abrir termo assinado">'
                + '<i class="ti ti-file-check" aria-hidden="true"></i> Termo assinado</a>'
            : '';

        var cardCode = String(card.code || ('#' + cardId));
        var cardTop = '<div class="tralla-card-top">'
            + '<span class="tralla-card-id-chip" title="ID do card: ' + attr(cardCode) + '">'
            + esc(cardCode) + '</span>'
            + (card.workflow_type === 'call' ? '<span class="tralla-card-call-badge">Chamado</span>' : '')
            + (isUpdates ? '<span class="tralla-card-update-badge">'
                + (card.update_mode === 'checklist' ? 'Checklist' : 'Descrição') + '</span>' : '')
            + (label || '<span class="tralla-card-top-spacer" aria-hidden="true"></span>')
            + '</div>';

        return '<article class="tralla-card' + (card.workflow_type === 'call' ? ' is-call' : '')
            + (isUpdates ? ' is-update' : '')
            + (card.is_locked ? ' is-locked' : '') + (cardApprovalClass ? ' ' + cardApprovalClass : '') + departingClass
            + '" data-card-id="' + cardId + '"'
            + (canDrag ? ' draggable="true"' : '')
            + ' tabindex="0" role="button" aria-label="Abrir card ' + attr(card.name) + '">'
            + '<span class="tralla-card-edit" aria-hidden="true"><i class="ti ti-pencil"></i></span>'
            + cardTop
            + '<h4 class="tralla-card-title">' + esc(card.name) + '</h4>'
            + (card.workflow_type === 'call' && (card.call_category || card.call_subcategory)
                ? '<div class="tralla-card-call-category">'
                    + (card.call_category ? '<span>' + esc(card.call_category) + '</span>' : '')
                    + (card.call_subcategory ? '<small>' + esc(card.call_subcategory) + '</small>' : '')
                    + '</div>' : '')
            + (isUpdates && card.update_mode === 'checklist' && card.update_progress
                ? '<div class="tralla-update-card-progress">'
                    + Number(card.update_progress.done || 0) + '/' + Number(card.update_progress.total || 0)
                    + ' itens</div>' : '')
            + ((badges || tech || takeButton || signButton)
                ? '<div class="tralla-card-footer">'
                    + '<div class="tralla-card-badges">' + badges + '</div>'
                    + tech
                    + takeButton
                    + signButton
                    + '</div>'
                : '')
            + termButton
            + '</article>';
    }

    function dropContainer(target) {
        var list = target.closest('.tralla-list');
        return list && listsEl.contains(list) ? list.querySelector('.tralla-cards') : null;
    }

    function clearDropPreview(keepSource) {
        if (dragPreview) {
            dragPreview.remove();
            dragPreview = null;
        }
        if (dragSource && !keepSource) {
            dragSource.classList.remove('is-dragging');
            dragSource = null;
        }
        Array.prototype.forEach.call(listsEl.querySelectorAll('.is-drag-over, .is-drop-target'), function (element) {
            element.classList.remove('is-drag-over', 'is-drop-target');
        });
    }

    function positionDropPreview(container, clientY) {
        if (!dragSource) {
            return;
        }
        if (!dragPreview) {
            dragPreview = dragSource.cloneNode(true);
            dragPreview.classList.remove('is-dragging');
            dragPreview.classList.add('tralla-drop-preview');
            dragPreview.removeAttribute('data-card-id');
            dragPreview.removeAttribute('draggable');
            dragPreview.removeAttribute('tabindex');
            dragPreview.removeAttribute('role');
            dragPreview.setAttribute('aria-hidden', 'true');
        }

        var cards = Array.prototype.slice.call(container.querySelectorAll('.tralla-card:not(.tralla-drop-preview)'))
            .filter(function (element) {
                return Number(element.dataset.cardId) !== dragCardId;
            });
        var next = cards.find(function (element) {
            var rect = element.getBoundingClientRect();
            return clientY < rect.top + rect.height / 2;
        });
        if (dragPreview.parentNode !== container || dragPreview.nextElementSibling !== (next || null)) {
            container.insertBefore(dragPreview, next || null);
        }

        Array.prototype.forEach.call(listsEl.querySelectorAll('.is-drag-over, .is-drop-target'), function (element) {
            element.classList.remove('is-drag-over', 'is-drop-target');
        });
        container.classList.add('is-drag-over');
        container.closest('.tralla-list').classList.add('is-drop-target');
    }

    function computeDropRank(container, movingId) {
        var cards = Array.prototype.slice.call(container.querySelectorAll('.tralla-card:not(.tralla-drop-preview)'))
            .filter(function (element) {
                return Number(element.dataset.cardId) !== Number(movingId);
            });
        var previewIndex = Array.prototype.indexOf.call(container.children, dragPreview);
        var previous = null;
        var next = null;

        cards.forEach(function (element) {
            if (Array.prototype.indexOf.call(container.children, element) < previewIndex) {
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
            return nextRank > 0 ? nextRank / 2 : 1;
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
        openCardRequest++;
        if (isUpdates && activeCard && modalRoot.querySelector('[data-update-text]')) {
            flushUpdateText().catch(function (error) { toast(error.message, 'error'); });
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
            sub += '<button type="button" class="tralla-card-tech tralla-user-profile-trigger" data-user-profile="'
                + Number(card.technician_id) + '" aria-label="Ver informações de ' + attr(card.technician_name) + '">'
                + avatar(card.technician_name, card.technician_picture_url)
                + esc(card.technician_name) + '</button>';
        }

        return '<div class="tralla-modal-head">'
            + '<div>'
            + '<div class="tralla-detail-code">' + esc(card.code || ('#' + card.id))
            + ' · ' + esc(card.list_name || '') + '</div>'
            + (card.workflow_type === 'call' ? '<span class="tralla-card-call-badge">Chamado</span>' : '')
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
        rows += metaRow('Tipo', isUpdates ? (card.update_mode === 'checklist' ? 'Checklist' : 'Descrição')
            : (isMaintenance ? (card.workflow_type === 'call' ? 'Chamado' : 'Manutenção') : 'Normal'));
        rows += metaRow('Categoria', card.call_category);
        rows += metaRow('Subcategoria', card.call_subcategory);
        rows += metaRow('Responsável', card.technician_name || 'Não atribuído');
        rows += metaRow('Criado por', card.creator_name);
        rows += metaRow('Criado em', formatDate(card.date_creation));
        rows += metaRow('Atualizado em', formatDate(card.date_mod));
        rows += metaRow('Aprovador', card.approver_name);
        rows += metaRow('Finalizado em', formatDate(card.date_finalized));
        rows += metaRow('Aprovado em', formatDate(card.date_approved));
        rows += metaRow('Retirada em', formatDate(card.date_withdrawn));
        rows += metaRow('Motivo da reprovação', card.rejection_reason);

        return renderSlaBlock(card)
            + '<div class="tralla-card"><h3>Detalhes</h3><div class="tralla-meta-list">' + rows + '</div></div>';
    }

    /* ---------------------------------------------------------- prioridade / SLA */

    function priorityInfo(key) {
        var list = Array.isArray(labels.priorities) ? labels.priorities : [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].key === key) {
                return list[i];
            }
        }
        return {};
    }

    function slaStateLabel(state) {
        return {
            waiting: 'Aguardando liberação',
            running: 'No prazo',
            overdue: 'Atrasado',
            met: 'Cumprido',
            late_met: 'Cumprido com atraso'
        }[state] || '';
    }

    function slaBadges(card) {
        var sla = card && card.sla;
        if (!sla || !sla.priority) {
            return '';
        }
        var html = '<span class="tralla-card-badge tralla-priority-badge is-' + attr(String(sla.priority).toLowerCase()) + '"'
            + ' title="Prioridade ' + attr(sla.label) + '">' + esc(sla.priority) + '</span>';
        if (sla.next) {
            html += '<span class="tralla-card-badge tralla-sla-badge is-' + attr(sla.next.state) + '"'
                + ' title="' + attr(sla.next.title + ': até ' + formatDate(sla.next.deadline) + ' (' + slaStateLabel(sla.next.state) + ')') + '">'
                + '<i class="ti ' + (sla.next.state === 'overdue' ? 'ti-alarm' : 'ti-clock') + '" aria-hidden="true"></i>'
                + '<span>' + esc(sla.next.state === 'overdue' ? 'Atrasado' : 'até ' + formatDate(sla.next.deadline)) + '</span></span>';
        }
        return html;
    }

    function renderSlaBlock(card) {
        var sla = card && card.sla;
        if (!sla || !sla.priority) {
            return '';
        }
        var rows = (sla.steps || []).map(function (step) {
            var when = step.met_at
                ? 'Em ' + formatDate(step.met_at)
                : (step.deadline ? 'Até ' + formatDate(step.deadline) : step.span);
            return '<div class="tralla-sla-row is-' + attr(step.state) + '">'
                + '<div><strong>' + esc(step.title) + '</strong><small>Prazo: ' + esc(step.span) + '</small></div>'
                + '<div class="tralla-sla-when"><span>' + esc(when) + '</span>'
                + '<em>' + esc(slaStateLabel(step.state)) + '</em></div></div>';
        }).join('');
        return '<div class="tralla-card tralla-sla-card"><h3>Prioridade e SLA</h3>'
            + '<div class="tralla-sla-priority is-' + attr(String(sla.priority).toLowerCase()) + '">' + esc(sla.label) + '</div>'
            + (sla.start ? '<small class="tralla-sla-start">Contando desde ' + esc(formatDate(sla.start)) + '</small>' : '')
            + '<div class="tralla-sla-rows">' + rows + '</div></div>';
    }

    function priorityDialog(card) {
        var list = Array.isArray(labels.priorities) ? labels.priorities : [];
        return new Promise(function (resolve) {
            var uid = 'tralla-priority-' + Date.now();
            var previousFocus = document.activeElement;
            var layer = document.createElement('div');
            layer.className = 'tralla-dialog-layer';
            layer.innerHTML = '<div class="tralla-dialog-backdrop" data-dialog-backdrop></div>'
                + '<div class="tralla-modal tralla-dialog tralla-dialog-primary tralla-confirm-dialog tralla-priority-dialog" role="dialog" aria-modal="true"'
                + ' aria-labelledby="' + uid + '-title">'
                + '<div class="tralla-dialog-head">'
                + '<span class="tralla-dialog-icon" aria-hidden="true"><i class="ti ti-flag"></i></span>'
                + '<div class="tralla-dialog-copy">'
                + '<span class="tralla-confirm-eyebrow">Aprovação</span>'
                + '<h2 class="tralla-dialog-title" id="' + uid + '-title">Definir prioridade e liberar</h2>'
                + '<p class="tralla-dialog-message">Escolha a prioridade. Ao liberar, o card fica disponível para Pegar e os prazos começam a contar.</p>'
                + '</div></div>'
                + (card ? '<div class="tralla-confirm-card"><span>Card</span><strong><span class="tralla-confirm-code">'
                    + esc(card.code || ('#' + card.id)) + '</span> ' + esc(card.name) + '</strong></div>' : '')
                + '<div class="tralla-priority-options" role="radiogroup" aria-label="Prioridade">'
                + list.map(function (item) {
                    return '<label class="tralla-priority-option is-' + attr(String(item.key).toLowerCase()) + '">'
                        + '<input type="radio" name="' + uid + '-priority" value="' + attr(item.key) + '">'
                        + '<span class="tralla-priority-tag">' + esc(item.key) + '</span>'
                        + '<span class="tralla-priority-text"><strong>' + esc(item.name) + '</strong>'
                        + '<small>1º atendimento: ' + esc(item.first) + ' · Paliativa: ' + esc(item.palliative)
                        + ' · Definitiva: ' + esc(item.definitive) + '</small></span></label>';
                }).join('')
                + '</div>'
                + '<div class="tralla-dialog-actions">'
                + '<button type="button" class="tralla-btn tralla-btn-ghost" data-dialog-cancel>Cancelar</button>'
                + '<button type="button" class="tralla-btn tralla-btn-primary" data-dialog-confirm disabled>'
                + '<i class="ti ti-lock-open" aria-hidden="true"></i> Liberar com esta prioridade</button>'
                + '</div></div>';
            document.body.appendChild(layer);
            document.body.classList.add('tralla-dialog-open');
            var confirmEl = layer.querySelector('[data-dialog-confirm]');
            var finished = false;
            function selected() {
                var checked = layer.querySelector('input[type="radio"]:checked');
                return checked ? checked.value : '';
            }
            function finish(value) {
                if (finished) { return; }
                finished = true;
                document.removeEventListener('keydown', onKeydown, true);
                layer.remove();
                if (!document.querySelector('.tralla-dialog-layer')) {
                    document.body.classList.remove('tralla-dialog-open');
                }
                if (previousFocus && document.contains(previousFocus)) { previousFocus.focus(); }
                resolve(value);
            }
            function onKeydown(event) {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); finish(''); }
            }
            layer.querySelectorAll('input[type="radio"]').forEach(function (radio) {
                radio.addEventListener('change', function () { confirmEl.disabled = selected() === ''; });
            });
            confirmEl.addEventListener('click', function () { if (selected()) { finish(selected()); } });
            layer.querySelector('[data-dialog-backdrop]').addEventListener('click', function () { finish(''); });
            layer.querySelector('[data-dialog-cancel]').addEventListener('click', function () { finish(''); });
            document.addEventListener('keydown', onKeydown, true);
            window.setTimeout(function () {
                var first = layer.querySelector('input[type="radio"]');
                if (first) { first.focus(); }
            }, 30);
        });
    }

    function finalizeRequirements(card) {
        var requirements = { general: [], machines: [] };
        var machines = Array.isArray(card.machines) ? card.machines : [];
        if (card.is_locked) {
            requirements.general.push('Aguarde o desbloqueio deste card.');
        }
        if (card.workflow_type === 'call' && machines.length === 0) {
            if (!String(card.service_report || '').trim()) {
                requirements.general.push('Registre e salve o relatório do atendimento.');
            }
        } else if (machines.length === 0) {
            requirements.general.push('Adicione ao menos uma máquina ao card.');
        } else {
            machines.forEach(function (machine, index) {
                var missing = [];
                if (['done', 'not_repairable', 'pending'].indexOf(machine.status) < 0) {
                    missing.push('Escolher o status');
                } else if (card.workflow_type === 'call' && machine.status === 'pending') {
                    missing.push('Escolher Concluído ou Inservível; Chamados não aceitam Pendente');
                }
                if (!String(machine.report || '').trim()) { missing.push('Preencher o relatório técnico'); }
                if (!Number(machine.service_done)) { missing.push('Marcar Serviço realizado'); }
                if (missing.length) {
                    requirements.machines.push({ title: machineDisplayTitle(machine, index + 1), missing: missing });
                }
            });
            if (!machines.some(function (machine) {
                return machine.status === 'done' || machine.status === 'not_repairable';
            })) {
                requirements.general.push('Deixe ao menos uma máquina com status Concluído ou Inservível.');
            }
        }
        if (!(card.permissions || {}).edit_machines && (requirements.general.length || requirements.machines.length)) {
            requirements.general.push(card.workflow_type === 'call' && machines.length === 0
                ? 'Somente o técnico responsável pode registrar o relatório do atendimento.'
                : 'Somente o técnico responsável pode completar os dados das máquinas.');
        } else if (!requirements.general.length && !requirements.machines.length && !(card.permissions || {}).finalize) {
            requirements.general.push('Seu perfil não tem permissão para finalizar este card.');
        }
        return requirements;
    }

    function finalizeBlockedReason(card) {
        var requirements = finalizeRequirements(card);
        return requirements.general[0]
            || (requirements.machines[0] && (requirements.machines[0].title + ': ' + requirements.machines[0].missing[0] + '.'))
            || 'Este card ainda não pode ser finalizado.';
    }

    function showFinalizeRequirements(card) {
        var requirements = finalizeRequirements(card);
        var total = requirements.general.length + requirements.machines.reduce(function (count, machine) {
            return count + machine.missing.length;
        }, 0);
        var html = '<div class="tralla-finalize-requirements">'
            + '<strong>' + total + ' pendência' + (total === 1 ? '' : 's') + ' para resolver</strong>';
        if (requirements.general.length) {
            html += '<ul>' + requirements.general.map(function (item) {
                return '<li>' + esc(item) + '</li>';
            }).join('') + '</ul>';
        }
        requirements.machines.forEach(function (machine) {
            html += '<section><h3><i class="ti ti-device-desktop" aria-hidden="true"></i> '
                + esc(machine.title) + '</h3><ul>' + machine.missing.map(function (item) {
                    return '<li>' + esc(item) + '</li>';
                }).join('') + '</ul></section>';
        });
        html += '</div>';
        return openPluginDialog({
            mode: 'info',
            title: 'O que falta para finalizar?',
            message: 'Complete os itens abaixo para liberar o botão Finalizar.',
            confirmLabel: 'Entendi',
            icon: 'ti-list-check',
            detailsHtml: html
        });
    }

    function renderSideActions(card) {
        var permissions = card.permissions || {};
        var buttons = '';
        var note = '';

        if (permissions.take) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="take">'
                + '<i class="ti ti-hand-stop"></i> Pegar para mim</button>';
        }

        if (permissions.request_call_release) {
            buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="request_call_release">'
                + '<i class="ti ti-lock-open"></i> Solicitar liberação</button>';
        }

        if (card.status === 'in_progress' && Number(card.stage) === 1) {
            var finalizeLabel = 'Finalizar ' + (card.workflow_type === 'call' ? 'chamado' : 'manutenção');
            if (permissions.finalize) {
                buttons += '<button type="button" class="tralla-btn tralla-btn-primary" data-card-action="finalize">'
                    + '<i class="ti ti-checkup-list"></i> ' + finalizeLabel + '</button>';
            } else {
                note = finalizeBlockedReason(card);
                buttons += '<button type="button" class="tralla-btn tralla-btn-primary tralla-finalize-blocked"'
                    + ' data-finalize-requirements aria-disabled="true" title="' + attr(note) + '">'
                    + '<i class="ti ti-checkup-list"></i> ' + finalizeLabel + '</button>';
            }
        }

        if (card.status === 'withdrawal' && Number(card.stage) === 1 && card.assignature_url) {
            buttons += '<a class="tralla-btn tralla-btn-sign" href="' + attr(card.assignature_url)
                + '" target="_blank" rel="noopener noreferrer"><i class="ti ti-signature"></i> Assinar</a>';
        }

        if (permissions.approve) {
            var kind = approvalKind(card);
            var approveLabel = kind === 'deletion'
                ? 'Aprovar exclusão'
                : (kind === 'edit' ? 'Aprovar edição' : (kind === 'split' ? 'Aprovar separação'
                    : (kind === 'call_release' ? 'Definir prioridade e liberar' : 'Aprovar')));
            var rejectLabel = kind === 'deletion'
                ? 'Recusar exclusão'
                : (kind === 'edit' ? 'Recusar edição' : (kind === 'split' ? 'Recusar separação'
                    : (kind === 'call_release' ? 'Não liberar' : 'Reprovar')));
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

    function renderMachine(machine, canEdit, permissions, sequence, canBulkSelect) {
        var id = Number(machine.id);
        var title = machineDisplayTitle(machine, sequence);
        var disabled = canEdit ? '' : ' disabled';

        return '<div class="tralla-machine" data-machine-id="' + id + '">'
            + '<div class="tralla-machine-head">'
            + '<div class="tralla-machine-title">'
            + '<span data-machine-title-text>' + esc(title) + '</span>'
            + '<small>' + esc(machine.model_name || 'Modelo não informado') + '</small>'
            + '</div>'
            + (canBulkSelect ? '<label class="tralla-machine-select"><input type="checkbox" data-select-machine="' + id
                + '" aria-label="Selecionar ' + attr(title) + '"><span>Selecionar</span></label>' : '')
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
        if (isApproval && approvalKind(card) === 'split') {
            var selectedIds = (card.split_machine_ids || []).map(Number);
            machines = machines.filter(function (machine) {
                return selectedIds.indexOf(Number(machine.id)) !== -1;
            });
        }
        var progress = machineProgress(machines);
        var permissions = card.permissions || {};
        var canEdit = !isApproval && !!permissions.edit_machines;
        var bulkTools = canEdit && machines.length > 1
            ? '<div class="tralla-bulk-toolbar">'
                + '<label class="tralla-machine-select"><input type="checkbox" data-select-all-machines>'
                + '<span>Selecionar todas</span></label>'
                + '<span data-bulk-count>0 selecionadas</span>'
                + '</div>'
                + '<div class="tralla-bulk-editor" data-bulk-editor hidden>'
                + '<strong>Editar máquinas selecionadas</strong>'
                + '<p>Marque somente os campos que deseja alterar. O relatório será substituído nas máquinas selecionadas.</p>'
                + '<div class="tralla-bulk-fields">'
                + '<div class="tralla-field"><label class="tralla-check"><input type="checkbox" data-bulk-use="status"> Alterar status</label>'
                + '<select data-bulk-value="status" disabled><option value="__select">Selecione...</option>'
                + machineStatusOptions('') + '</select></div>'
                + '<div class="tralla-field tralla-bulk-report"><label class="tralla-check"><input type="checkbox" data-bulk-use="report"> Alterar relatório técnico</label>'
                + '<textarea data-bulk-value="report" maxlength="200000" rows="3" disabled placeholder="Novo relatório para as máquinas selecionadas"></textarea></div>'
                + '<div class="tralla-field"><label class="tralla-check"><input type="checkbox" data-bulk-use="service_done"> Alterar Serviço realizado</label>'
                + '<select data-bulk-value="service_done" disabled><option value="1">Sim</option><option value="0">Não</option></select></div>'
                + '<div class="tralla-field"><label class="tralla-check"><input type="checkbox" data-bulk-use="needs_inventory"> Alterar Necessita inventariar</label>'
                + '<select data-bulk-value="needs_inventory" disabled><option value="1">Sim</option><option value="0">Não</option></select></div>'
                + '</div><button type="button" class="tralla-btn tralla-btn-primary" data-bulk-apply>'
                + '<i class="ti ti-device-floppy"></i> Aplicar às selecionadas</button></div>'
            : '';

        return '<section class="tralla-detail-section">'
            + '<h3><i class="ti ti-device-desktop"></i> Máquinas</h3>'
            + '<div class="tralla-progress-summary" data-progress-summary>'
            + '<div class="tralla-progress' + (progress.percent >= 100 ? ' is-complete' : '') + '">'
            + '<span data-progress-bar style="width:' + progress.percent + '%"></span></div>'
            + '<span data-progress-label>' + progress.filled + '/' + progress.total + '</span>'
            + '</div>'
            + bulkTools
            + (machines.length
                ? machines.map(function (machine, index) {
                    return renderMachine(machine, canEdit, permissions, index + 1, !!bulkTools);
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

        if (kind === 'split') {
            return '<div class="tralla-approval-banner is-edit">'
                + '<i class="ti ti-git-branch"></i>'
                + '<div class="tralla-approval-content"><strong>Separação aguardando aprovação</strong>'
                + parentLine
                + '<small>' + Number((card.split_machine_ids || []).length)
                + ' máquina(s) selecionada(s). Confira a lista abaixo antes de decidir.</small></div>'
                + openParent
                + '</div>';
        }

        if (kind === 'call_release') {
            return '<div class="tralla-approval-banner is-call-release">'
                + '<i class="ti ti-lock-open"></i>'
                + '<div class="tralla-approval-content"><strong>Aguardando prioridade e liberação</strong>'
                + parentLine
                + '<small>Defina a prioridade (P1 a P5). Ao liberar, o card fica disponível para o técnico clicar em Pegar e os prazos de SLA começam a contar.</small></div>'
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
        var kindClass = kind === 'deletion' ? 'deletion' : (kind === 'call_release' ? 'call-release'
            : (kind === 'edit' || kind === 'split' ? 'edit' : 'finalization'));
        var label = kind === 'deletion'
            ? 'Exclusão pendente de aprovação'
            : (kind === 'edit' ? 'Edição pendente de aprovação'
                : (kind === 'split' ? 'Separação pendente de aprovação'
                    : (kind === 'call_release' ? 'Liberação pendente de aprovação' : 'Finalização pendente de aprovação')));
        var icon = kind === 'deletion' ? 'ti-trash' : (kind === 'edit' ? 'ti-pencil'
            : (kind === 'split' ? 'ti-git-branch' : (kind === 'call_release' ? 'ti-lock-open' : 'ti-shield-check')));

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
        if (card.assignature_term_url) {
            return '<a class="tralla-assignature-link" href="' + attr(card.assignature_term_url) + '" target="_blank" rel="noopener noreferrer">'
                + '<i class="ti ti-file-check"></i><span>Abrir termo assinado</span></a>';
        }
        if (!card.assignature_url) {
            return '';
        }
        return '<a class="tralla-assignature-link" href="' + attr(card.assignature_url) + '" target="_blank" rel="noopener noreferrer">'
            + '<i class="ti ti-external-link"></i><span>'
            + (card.assignature_sent ? 'Abrir comprovante no Assignat-ure' : 'Assinar retirada no Assignat-ure')
            + '</span></a>';
    }

    function fileSizeLabel(bytes) {
        var size = Number(bytes) || 0;
        if (size >= 1048576) { return (size / 1048576).toFixed(1).replace('.', ',') + ' MB'; }
        if (size >= 1024) { return Math.ceil(size / 1024) + ' KB'; }
        return size + ' B';
    }

    function renderCallFiles(card) {
        if (!isMaintenance || card.workflow_type !== 'call') { return ''; }
        var files = Array.isArray(card.call_files) ? card.call_files : [];
        var rows = files.map(function (file) {
            return '<div class="tralla-call-file-row">'
                + '<i class="ti ti-paperclip" aria-hidden="true"></i>'
                + '<span><a href="' + attr(file.url) + '">' + esc(file.name) + '</a>'
                + '<small>' + esc(fileSizeLabel(file.size)) + ' · ' + esc(file.uploaded_by || '')
                + ' · ' + esc(formatDate(file.date_creation)) + '</small></span>'
                + (file.can_delete ? '<button type="button" class="tralla-icon-btn" data-call-file-delete="'
                    + Number(file.id) + '" title="Remover arquivo" aria-label="Remover arquivo ' + attr(file.name) + '">'
                    + '<i class="ti ti-trash"></i></button>' : '')
                + '</div>';
        }).join('');
        var upload = card.permissions && card.permissions.upload_call_file
            ? '<form id="tralla-call-file-form" class="tralla-call-file-form">'
                + '<label for="tralla-call-file-input">Vincular arquivos (até 100 MB cada)</label>'
                + '<input id="tralla-call-file-input" type="file" name="files" multiple required>'
                + '<button type="submit" class="tralla-btn tralla-btn-primary"><i class="ti ti-upload"></i> Enviar arquivos</button>'
                + '<div class="tralla-call-file-progress" role="status" hidden></div>'
                + '</form>' : '';
        return '<section class="tralla-detail-section tralla-call-files">'
            + '<h3><i class="ti ti-paperclip"></i> Arquivos vinculados</h3>'
            + (rows || '<p class="tralla-empty-mini">Nenhum arquivo vinculado.</p>')
            + upload + '</section>';
    }

    function renderUpdateFileRows(files) {
        return (files || []).map(function (file) {
            return '<div class="tralla-call-file-row"><i class="ti ti-paperclip" aria-hidden="true"></i>'
                + '<span><a href="' + attr(file.url) + '">' + esc(file.name) + '</a>'
                + '<small>' + esc(fileSizeLabel(file.size)) + ' · ' + esc(file.uploaded_by || '') + '</small></span>'
                + (file.can_delete ? '<button type="button" class="tralla-icon-btn" data-call-file-delete="'
                    + Number(file.id) + '" aria-label="Remover arquivo ' + attr(file.name) + '">'
                    + '<i class="ti ti-trash"></i></button>' : '') + '</div>';
        }).join('');
    }

    function renderUpdateUpload(card, itemId, count) {
        if (!card.permissions || !card.permissions.upload_update_file) { return ''; }
        if (itemId && count >= 10) { return '<small>Limite de 10 arquivos neste item.</small>'; }
        return '<form class="tralla-call-file-form tralla-update-file-form" data-update-item-id="'
            + Number(itemId || 0) + '">'
            + '<label>Anexar arquivos (até 100 MB cada' + (itemId ? ', 10 por item' : '') + ')</label>'
            + '<input type="file" name="files" multiple required>'
            + '<button type="submit" class="tralla-btn tralla-btn-ghost"><i class="ti ti-upload"></i> Enviar</button>'
            + '<div class="tralla-call-file-progress" role="status" hidden></div></form>';
    }

    function renderUpdateModal(card) {
        var p = card.permissions || {};
        var files = Array.isArray(card.update_files) ? card.update_files : [];
        var body = '';
        if (card.update_mode === 'description') {
            body += '<section class="tralla-detail-section"><h3><i class="ti ti-file-text"></i> Descrição</h3>';
            body += p.update_edit_brief
                ? '<div class="tralla-field"><label>O que precisa ser feito</label><textarea rows="7" maxlength="200000"'
                    + ' data-update-text="description">' + esc(card.description || '') + '</textarea>'
                    + '<small>Salvo automaticamente enquanto você digita.</small></div>'
                : '<div class="tralla-detail-description">' + (card.description ? esc(card.description)
                    : '<span class="tralla-empty-mini">Sem descrição.</span>') + '</div>';
            body += renderUpdateFileRows(files.filter(function (file) { return !Number(file.item_id); }))
                + renderUpdateUpload(card, 0, 0) + '</section>';
            if (card.status === 'update_in_progress' || card.status === 'update_done') {
                body += '<section class="tralla-detail-section"><h3><i class="ti ti-bulb"></i> Solução</h3>'
                    + (p.update_edit_work
                        ? '<div class="tralla-field"><label>Descreva a solução</label><textarea rows="7" maxlength="200000"'
                            + ' data-update-text="update_solution">' + esc(card.update_solution || '') + '</textarea>'
                            + '<small>Salvo automaticamente enquanto você digita.</small></div>'
                        : '<div class="tralla-detail-description">' + (card.update_solution ? esc(card.update_solution)
                            : '<span class="tralla-empty-mini">Solução não registrada.</span>') + '</div>') + '</section>';
            }
        } else {
            var items = Array.isArray(card.update_items) ? card.update_items : [];
            body += '<section class="tralla-detail-section"><h3><i class="ti ti-list-check"></i> Checklist</h3>';
            body += items.length ? items.map(function (item) {
                var itemFiles = files.filter(function (file) { return Number(file.item_id) === Number(item.id); });
                return '<div class="tralla-update-item" data-update-item="' + Number(item.id) + '">'
                    + '<label class="tralla-update-item-title"><input type="checkbox" data-update-toggle="'
                    + Number(item.id) + '"' + (item.is_done ? ' checked' : '')
                    + (p.update_edit_work ? '' : ' disabled') + '><strong>' + esc(item.title) + '</strong></label>'
                    + ((p.update_edit_brief || p.update_edit_work)
                        ? '<button type="button" class="tralla-icon-btn" data-update-rename="' + Number(item.id)
                            + '" title="Renomear item"><i class="ti ti-pencil"></i></button>'
                            + '<button type="button" class="tralla-icon-btn" data-update-delete="' + Number(item.id)
                            + '" title="Excluir item"><i class="ti ti-trash"></i></button>' : '')
                    + '<div class="tralla-update-item-files">' + renderUpdateFileRows(itemFiles)
                    + renderUpdateUpload(card, Number(item.id), itemFiles.length) + '</div></div>';
            }).join('') : '<p class="tralla-empty-mini">Nenhum item na checklist.</p>';
            if (p.update_edit_brief || p.update_edit_work) {
                body += '<form id="tralla-update-add-item" class="tralla-update-add-item">'
                    + '<input name="title" type="text" maxlength="500" placeholder="Novo item da checklist" required>'
                    + '<button type="submit" class="tralla-btn tralla-btn-ghost"><i class="ti ti-plus"></i> Adicionar</button></form>';
            }
            body += '</section>';
            if (card.status === 'update_in_progress' || card.status === 'update_done') {
                body += '<section class="tralla-detail-section"><h3><i class="ti ti-note"></i> Observação final (opcional)</h3>'
                    + (p.update_edit_work
                        ? '<textarea rows="5" maxlength="200000" data-update-text="update_observation"'
                            + ' placeholder="Acrescente uma observação, se quiser...">'
                            + esc(card.update_observation || '') + '</textarea><small>Salvo automaticamente.</small>'
                        : '<div class="tralla-detail-description">' + (card.update_observation ? esc(card.update_observation)
                            : '<span class="tralla-empty-mini">Sem observação.</span>') + '</div>') + '</section>';
            }
        }
        body += renderHistory(card);
        var actions = '';
        if (p.update_schedule) { actions += '<button type="button" class="tralla-btn tralla-btn-primary" data-update-transition="schedule">Mover para Pendente</button>'; }
        if (p.update_take) { actions += '<button type="button" class="tralla-btn tralla-btn-primary" data-update-transition="take">Fazer</button>'; }
        if (p.update_finish) { actions += '<button type="button" class="tralla-btn tralla-btn-primary" data-update-transition="finish">Finalizar</button>'; }
        var side = renderSideMeta(card) + '<div class="tralla-card"><h3>Ações</h3><div class="tralla-form">'
            + (actions || '<p class="tralla-muted">Nenhuma ação disponível neste estado.</p>') + '</div></div>';
        showModal(detailHeader(card) + '<div class="tralla-modal-body"><div class="tralla-detail-grid">'
            + '<div class="tralla-detail-main">' + body + '</div>'
            + '<aside class="tralla-detail-side">' + side + '</aside></div></div>', true);
    }

    function renderApprovalNavigation(card) {
        if (!isMaintenance || Number(card.stage) !== 2) { return ''; }
        var approvalList = (state.lists || []).find(function (list) {
            return list.category === 'maintenance_approval';
        });
        if (!approvalList || Number(card.plugin_tralla_lists_id) !== Number(approvalList.id)) { return ''; }
        var approvalCards = (state.cards || []).filter(function (item) {
            return Number(item.plugin_tralla_lists_id) === Number(approvalList.id)
                && Number(item.stage) === 2;
        }).sort(compareBySla);
        if (!approvalCards.some(function (item) { return Number(item.id) === Number(card.id); })) {
            approvalCards.push(card);
        }
        return '<nav class="tralla-approval-navigation" aria-label="Cards da Manutenção Aprovação">'
            + '<div class="tralla-approval-navigation-head"><strong>Manutenção Aprovação</strong>'
            + '<span>' + approvalCards.length + ' card' + (approvalCards.length === 1 ? '' : 's') + '</span></div>'
            + '<div class="tralla-approval-navigation-list">'
            + approvalCards.map(function (item) {
                var current = Number(item.id) === Number(card.id);
                return '<button type="button" class="tralla-approval-navigation-item '
                    + esc(approvalKindClass(item)) + (current ? ' is-current' : '') + '"'
                    + ' data-open-card="' + Number(item.id) + '"'
                    + (current ? ' aria-current="true" autofocus' : '') + '>'
                    + '<span class="tralla-approval-navigation-code">' + esc(item.code || ('#' + item.id))
                    + (item.workflow_type === 'call' ? ' · Chamado' : '') + '</span>'
                    + '<strong>' + esc(item.name) + '</strong>'
                    + '<span class="tralla-approval-navigation-kind">' + esc(approvalKindLabel(item)) + '</span>'
                    + '</button>';
            }).join('') + '</div></nav>';
    }

    var withdrawalSelection = {};

    function withdrawalSelectedCards() {
        return (state.cards || []).filter(function (item) {
            return withdrawalSelection[Number(item.id)] && Number(item.stage) === 1
                && item.status === 'withdrawal' && item.assignature_url;
        });
    }

    function withdrawalBatchUrl(cards) {
        if (!cards.length) { return ''; }
        try {
            var url = new URL(cards[0].assignature_url, window.location.href);
            var refs = cards.map(function (item) {
                return new URL(item.assignature_url, window.location.href).searchParams.get('card') || '';
            }).filter(Boolean);
            if (!refs.length) { return ''; }
            url.searchParams.delete('card');
            url.searchParams.set('cards', refs.join(','));
            return url.toString();
        } catch (error) {
            return '';
        }
    }

    function syncWithdrawalSignButton(root) {
        var scope = root || document;
        var button = scope.querySelector('[data-withdrawal-sign]');
        if (!button) { return; }
        var count = withdrawalSelectedCards().length;
        button.disabled = count === 0;
        button.querySelector('[data-withdrawal-count]').textContent = String(count);
        var all = scope.querySelector('[data-withdrawal-select-all]');
        if (all) {
            var boxes = scope.querySelectorAll('[data-withdrawal-select]:not(:disabled)');
            var checked = scope.querySelectorAll('[data-withdrawal-select]:checked');
            all.checked = boxes.length > 0 && checked.length === boxes.length;
            all.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
    }

    document.addEventListener('change', function (event) {
        var box = event.target.closest('[data-withdrawal-select]');
        var all = event.target.closest('[data-withdrawal-select-all]');
        if (!box && !all) { return; }
        var nav = event.target.closest('.tralla-withdrawal-navigation');
        if (all && nav) {
            nav.querySelectorAll('[data-withdrawal-select]:not(:disabled)').forEach(function (input) {
                input.checked = all.checked;
                withdrawalSelection[Number(input.value)] = all.checked;
            });
        } else if (box) {
            withdrawalSelection[Number(box.value)] = box.checked;
        }
        syncWithdrawalSignButton(nav);
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-withdrawal-sign]');
        if (!button || button.disabled) { return; }
        event.preventDefault();
        var url = withdrawalBatchUrl(withdrawalSelectedCards());
        if (!url) {
            toast('Configure a URL do Assignat-ure para assinar pelo Tralla.', 'error');
            return;
        }
        window.open(url, '_blank', 'noopener');
    });

    function renderWithdrawalNavigation(card) {
        if (!isMaintenance || Number(card.stage) !== 1 || card.status !== 'withdrawal') { return ''; }
        var entityName = String(card.name || '').trim();
        var withdrawalCards = (state.cards || []).filter(function (item) {
            return Number(item.stage) === 1 && item.status === 'withdrawal'
                && String(item.name || '').trim() === entityName;
        }).sort(function (left, right) {
            return Number(left.rank) - Number(right.rank) || Number(left.id) - Number(right.id);
        });
        if (!withdrawalCards.some(function (item) { return Number(item.id) === Number(card.id); })) {
            withdrawalCards.push(card);
        }
        var visibleIds = {};
        withdrawalCards.forEach(function (item) { visibleIds[Number(item.id)] = true; });
        Object.keys(withdrawalSelection).forEach(function (id) {
            if (!visibleIds[Number(id)]) { delete withdrawalSelection[id]; }
        });
        var signable = withdrawalCards.filter(function (item) { return !!item.assignature_url; });
        var selectedCount = withdrawalCards.filter(function (item) {
            return withdrawalSelection[Number(item.id)] && item.assignature_url;
        }).length;
        return '<nav class="tralla-approval-navigation tralla-withdrawal-navigation" aria-label="Cards em Retirada desta entidade">'
            + '<div class="tralla-approval-navigation-head"><strong>Retiradas desta entidade</strong>'
            + '<span>' + withdrawalCards.length + ' card' + (withdrawalCards.length === 1 ? '' : 's') + '</span></div>'
            + (signable.length > 1
                ? '<label class="tralla-withdrawal-select-all"><input type="checkbox" data-withdrawal-select-all'
                    + (selectedCount === signable.length ? ' checked' : '') + '> Selecionar todos</label>'
                : '')
            + '<div class="tralla-approval-navigation-list">'
            + withdrawalCards.map(function (item) {
                var current = Number(item.id) === Number(card.id);
                var canSign = !!item.assignature_url;
                return '<div class="tralla-withdrawal-pick' + (withdrawalSelection[Number(item.id)] && canSign ? ' is-selected' : '') + '">'
                    + '<input type="checkbox" class="tralla-withdrawal-check" data-withdrawal-select value="' + Number(item.id) + '"'
                    + ' aria-label="Selecionar ' + attr(item.code || ('#' + item.id)) + ' para assinar"'
                    + (withdrawalSelection[Number(item.id)] && canSign ? ' checked' : '')
                    + (canSign ? '' : ' disabled title="Assignat-ure não configurado"') + '>'
                    + '<button type="button" class="tralla-approval-navigation-item'
                    + (current ? ' is-current' : '') + '" data-open-card="' + Number(item.id) + '"'
                    + (current ? ' aria-current="true"' : '') + '>'
                    + '<span class="tralla-approval-navigation-code">' + esc(item.code || ('#' + item.id)) + '</span>'
                    + '<strong>' + esc(item.name) + '</strong>'
                    + '<span class="tralla-approval-navigation-kind">'
                    + esc(item.machine_summary || ((item.machine_count || 0) + ' máquina(s)')) + '</span>'
                    + '</button></div>';
            }).join('') + '</div>'
            + (signable.length
                ? '<div class="tralla-withdrawal-sign-bar">'
                    + '<button type="button" class="tralla-btn tralla-btn-sign" data-withdrawal-sign' + (selectedCount ? '' : ' disabled') + '>'
                    + '<i class="ti ti-signature" aria-hidden="true"></i> Assinar selecionados'
                    + ' (<span data-withdrawal-count>' + selectedCount + '</span>)</button>'
                    + '<small>Abre o Assignat-ure com os cards marcados para assinar de uma vez.</small></div>'
                : '')
            + '</nav>';
    }

    function renderCardModal(card) {
        if (isUpdates) { renderUpdateModal(card); return; }
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

        var hasLegacyCallMachines = card.workflow_type === 'call' && (
            (card.machines || []).length > 0 || (card.parent && (card.parent.machines || []).length > 0));
        if (isMaintenance && card.workflow_type === 'call' && !hasLegacyCallMachines) {
            var callSource = card;
            var canEditCall = Number(card.stage) === 1 && !!permissions.edit_machines;
            body += '<section class="tralla-detail-section tralla-call-report">'
                + '<h3><i class="ti ti-file-report"></i> Relatório do atendimento</h3>'
                + (canEditCall
                    ? '<div class="tralla-field"><label for="tralla-call-report">Descreva o atendimento realizado</label>'
                        + '<textarea id="tralla-call-report" rows="7" maxlength="200000" placeholder="Registre o diagnóstico e a solução aplicada...">'
                        + esc(callSource.service_report || '') + '</textarea></div>'
                        + '<button type="button" class="tralla-btn tralla-btn-primary" data-save-call-report><i class="ti ti-device-floppy"></i> Salvar relatório</button>'
                    : '<div class="tralla-detail-description">'
                        + (callSource.service_report ? esc(callSource.service_report) : '<span class="tralla-empty-mini">Ainda não há relatório.</span>')
                        + '</div>')
                + '</section>';
        } else if (isMaintenance) {
            body += renderMachines(card);
        }

        body += renderCallFiles(card);
        body += renderAssignature(card);
        body += renderHistory(card);

        var approvalNavigation = renderApprovalNavigation(card) || renderWithdrawalNavigation(card);
        showModal(approvalNavigation + detailHeader(card)
            + '<div class="tralla-modal-body"><div class="tralla-detail-grid">'
            + '<div class="tralla-detail-main">' + body + '</div>'
            + '<aside class="tralla-detail-side">' + renderSideMeta(card) + renderSideActions(card) + '</aside>'
            + '</div></div>', true);
        if (approvalNavigation) {
            modalRoot.querySelector('.tralla-modal').classList.add('has-approval-navigation');
        }
    }

    async function openCard(id) {
        var requestId = ++openCardRequest;
        try {
            if (isUpdates && activeCard && Number(activeCard.id) !== Number(id)) {
                await flushUpdateText();
            }
            var card = await api('card_get', { id: id });
            if (requestId !== openCardRequest) { return; }
            activeCard = card;
            renderCardModal(card);
        } catch (error) {
            if (requestId === openCardRequest) { toast(error.message, 'error'); }
        }
    }

    async function uploadFileChunks(cardId, itemId, files, onProgress, onFileComplete) {
        var chunkSize = 1048576;
        var total = files.reduce(function (sum, file) { return sum + file.size; }, 0);
        var sent = 0;
        for (var f = 0; f < files.length; f++) {
            var file = files[f];
            var random = crypto.getRandomValues(new Uint8Array(16));
            var uploadId = Array.prototype.map.call(random, function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
            var count = Math.ceil(file.size / chunkSize);
            for (var index = 0; index < count; index++) {
                var start = index * chunkSize;
                var chunk = file.slice(start, Math.min(start + chunkSize, file.size));
                await api('call_file_chunk', {
                    id: cardId, upload_id: uploadId, index: index, count: count,
                    file_size: file.size, file_name: file.name, file: chunk, item_id: itemId
                });
                sent += chunk.size;
                if (onProgress) { onProgress(file.name, Math.round(sent / total * 100)); }
            }
            if (onFileComplete) { onFileComplete(); }
        }
    }

    async function uploadCallFiles(form) {
        if (!activeCard || !['call', 'update'].includes(activeCard.workflow_type)) { return; }
        var cardId = Number(activeCard.id);
        var itemId = Number(form.dataset.updateItemId || 0);
        var input = form.querySelector('input[type="file"]');
        var files = Array.prototype.slice.call(input && input.files ? input.files : []);
        if (!files.length) { toast('Selecione ao menos um arquivo.', 'error'); return; }
        var maxSize = 104857600;
        if (files.some(function (file) { return file.size < 1 || file.size > maxSize; })) {
            toast('Cada arquivo deve ter até 100 MB e não pode estar vazio.', 'error');
            return;
        }
        if (itemId && activeCard.workflow_type === 'update') {
            var alreadyAttached = (activeCard.update_files || []).filter(function (file) {
                return Number(file.item_id) === itemId;
            }).length;
            if (alreadyAttached + files.length > 10) {
                toast('Cada item da checklist aceita até 10 arquivos.', 'error');
                return;
            }
        }
        var button = form.querySelector('button[type="submit"]');
        var progress = form.querySelector('.tralla-call-file-progress');
        var uploaded = 0;
        setBusy(button, true);
        progress.hidden = false;
        try {
            await uploadFileChunks(cardId, itemId, files, function (name, percent) {
                progress.textContent = 'Enviando ' + name + ' · ' + percent + '%';
            }, function () { uploaded++; });
            await refreshState();
            await openCard(cardId);
            toast(uploaded === 1 ? 'Arquivo vinculado.' : uploaded + ' arquivos vinculados.', 'success');
        } catch (error) {
            toast(error.message, 'error');
            if (uploaded > 0) {
                await refreshState();
                await openCard(cardId);
            } else {
                setBusy(button, false);
                progress.hidden = true;
            }
        }
    }

    async function deleteCallFile(fileId) {
        if (!activeCard) { return; }
        var cardId = Number(activeCard.id);
        var confirmed = await confirmDialog({
            title: 'Remover arquivo',
            message: 'Remover este arquivo vinculado ao card?',
            icon: 'ti-trash',
            tone: 'danger',
            confirmLabel: 'Remover arquivo'
        });
        if (!confirmed) { return; }
        try {
            var data = await api('call_file_delete', { file_id: fileId });
            applyState(data);
            await openCard(cardId);
            toast('Arquivo removido.', 'success');
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

    async function saveCallReport(button) {
        if (!activeCard) { return; }
        var field = modalRoot.querySelector('#tralla-call-report');
        if (!field) { return; }
        setBusy(button, true);
        try {
            var data = await api('call_report_update', { id: activeCard.id, report: field.value });
            applyState(data);
            toast('Relatório do chamado salvo.', 'success');
            await openCard(data.card.id);
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

    function checkboxConfirmation(options) {
        options = options || {};
        return new Promise(function (resolve) {
            var uid = 'tralla-confirm-' + Date.now();
            var previousFocus = document.activeElement;
            var machineCount = options.card ? (options.card.machines || []).length : 0;
            var layer = document.createElement('div');
            layer.className = 'tralla-dialog-layer';
            layer.innerHTML = '<div class="tralla-dialog-backdrop" data-dialog-backdrop></div>'
                + '<div class="tralla-modal tralla-dialog tralla-dialog-primary tralla-confirm-dialog" role="alertdialog" aria-modal="true"'
                + ' aria-labelledby="' + uid + '-title" aria-describedby="' + uid + '-message">'
                + '<div class="tralla-dialog-head">'
                + '<span class="tralla-dialog-icon" aria-hidden="true"><i class="ti ' + attr(options.icon || 'ti-shield-check') + '"></i></span>'
                + '<div class="tralla-dialog-copy">'
                + '<span class="tralla-confirm-eyebrow">Confirmação da ação</span>'
                + '<h2 class="tralla-dialog-title" id="' + uid + '-title">' + esc(options.title || 'Confirmar ação') + '</h2>'
                + '<p class="tralla-dialog-message" id="' + uid + '-message">' + esc(options.message || 'Deseja confirmar esta ação?') + '</p>'
                + '</div></div>'
                + (options.card ? '<div class="tralla-confirm-card">'
                    + '<span>Card selecionado</span>'
                    + '<strong><span class="tralla-confirm-code">' + esc(options.card.code || ('#' + options.card.id)) + '</span> '
                    + esc(options.card.name) + '</strong>'
                    + '<small>' + esc(options.card.workflow_type === 'call' ? 'Chamado' : 'Manutenção')
                    + (machineCount ? ' · ' + machineCount + (machineCount === 1 ? ' máquina' : ' máquinas') : '')
                    + '</small></div>' : '')
                + (options.impact ? '<p class="tralla-confirm-impact"><i class="ti ti-arrow-right" aria-hidden="true"></i><span>'
                    + esc(options.impact) + '</span></p>' : '')
                + '<label class="tralla-confirm-checkbox" for="' + uid + '-check">'
                + '<input id="' + uid + '-check" type="checkbox">'
                + '<span><strong>Confirmo esta ação</strong><small>Revisei as informações acima.</small></span></label>'
                + '<div class="tralla-dialog-actions">'
                + '<button type="button" class="tralla-btn tralla-btn-ghost" data-dialog-cancel>Cancelar</button>'
                + '<button type="button" class="tralla-btn tralla-btn-primary" data-dialog-confirm disabled>'
                + '<i class="ti ti-check" aria-hidden="true"></i> ' + esc(options.confirmLabel || 'Confirmar') + '</button>'
                + '</div></div>';
            document.body.appendChild(layer);
            document.body.classList.add('tralla-dialog-open');
            var inputEl = layer.querySelector('input');
            var confirmEl = layer.querySelector('[data-dialog-confirm]');
            var finished = false;
            function finish(ok) {
                if (finished) return;
                finished = true;
                document.removeEventListener('keydown', onKeydown, true);
                document.body.classList.remove('tralla-dialog-open');
                layer.remove();
                if (previousFocus && document.contains(previousFocus)) { previousFocus.focus(); }
                resolve(ok);
            }
            inputEl.addEventListener('change', function () { confirmEl.disabled = !inputEl.checked; });
            confirmEl.addEventListener('click', function () { if (inputEl.checked) finish(true); });
            layer.querySelector('[data-dialog-backdrop]').addEventListener('click', function () { finish(false); });
            layer.querySelector('[data-dialog-cancel]').addEventListener('click', function () { finish(false); });
            function onKeydown(event) {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); finish(false); }
                if (event.key === 'Enter' && inputEl.checked
                    && (document.activeElement === inputEl || document.activeElement === confirmEl)) {
                    event.preventDefault(); finish(true);
                }
                if (event.key === 'Tab') {
                    var items = [inputEl, layer.querySelector('[data-dialog-cancel]'), confirmEl]
                        .filter(function (item) { return item && !item.disabled; });
                    if (event.shiftKey && document.activeElement === items[0]) {
                        event.preventDefault(); items[items.length - 1].focus();
                    } else if (!event.shiftKey && document.activeElement === items[items.length - 1]) {
                        event.preventDefault(); items[0].focus();
                    }
                }
            }
            document.addEventListener('keydown', onKeydown, true);
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
        var isSplitApproval = kind === 'split';
        var isCallReleaseApproval = kind === 'call_release';
        var chosenPriority = '';
        var departingCard = Object.assign({}, activeCard);

        if (action === 'split') {
            openSplitModal(activeCard);
            return;
        }
        if (action === 'edit') {
            openEditModal(activeCard);
            return;
        }

        if (action === 'request_call_release') {
            try {
                setBusy(button, true);
                var releaseRequested = await api('request_call_release', { id: cardId });
                applyState(releaseRequested);
                toast('Liberação enviada para aprovação.', 'success');
                await openCard(cardId);
            } catch (error) {
                toast(error.message, 'error');
                setBusy(button, false);
            }
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
                } else if (isSplitApproval) {
                    rejectConfig = {
                        title: 'Recusar separação',
                        message: 'Nenhuma máquina será movida; o card de origem será liberado.',
                        label: 'Motivo (opcional)',
                        confirmLabel: 'Recusar separação'
                    };
                } else if (isCallReleaseApproval) {
                    rejectConfig = {
                        title: 'Não liberar chamado',
                        message: 'O card continuará bloqueado. Quem o criou ou um SuperAdmin poderá solicitar a liberação novamente.',
                        label: 'Motivo (opcional)',
                        confirmLabel: 'Não liberar'
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
                            : (isSplitApproval ? 'Descreva o motivo da recusa da separação...'
                                : (isCallReleaseApproval ? 'Descreva o motivo da recusa da liberação...'
                                    : 'Descreva o motivo da reprovação...'))),
                    icon: 'ti-shield-x',
                    tone: 'danger'
                }, rejectConfig));
                if (rejectReason === null) {
                    return;
                }
                if (!await checkboxConfirmation({
                    title: rejectConfig.title,
                    message: 'Confirma esta recusa? O motivo informado será registrado no histórico.',
                    confirmLabel: rejectConfig.confirmLabel
                })) {
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
                            : (isSplitApproval ? 'Separação recusada. Card liberado.'
                                : isCallReleaseApproval ? 'Liberação recusada. O card continua bloqueado.'
                                : (departingCard.workflow_type === 'call'
                                    ? 'Chamado reprovado. O card voltou para Em Andamento.'
                                    : 'Manutenção reprovada. O card voltou para Em Andamento.'))),
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
                var pendingCount = (activeCard.machines || []).filter(function (machine) {
                    return machine.status === 'pending';
                }).length;
                var finalizeConfirmed = await checkboxConfirmation({
                    title: activeCard.workflow_type === 'call' ? 'Finalizar chamado' : 'Finalizar manutenção',
                    message: 'Confira as informações antes de enviar este card para aprovação.',
                    card: activeCard,
                    impact: activeCard.workflow_type === 'call'
                        ? 'Após a aprovação, o chamado irá para Concluído.' : pendingCount > 0
                        ? 'As máquinas concluídas ou inservíveis seguirão para aprovação. As ' + pendingCount + ' pendente(s) serão reunidas em um novo card.'
                        : 'Após a aprovação, a manutenção seguirá para Retirada.',
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
                } else if (isSplitApproval) {
                    approveConfig = {
                        title: 'Aprovar separação',
                        message: 'Criar um novo card com as máquinas selecionadas?',
                        icon: 'ti-git-branch',
                        confirmLabel: 'Aprovar separação'
                    };
                } else if (isCallReleaseApproval) {
                    approveConfig = null;
                } else {
                    approveConfig = {
                        title: 'Aprovar manutenção',
                        message: 'Aprovar esta manutenção e liberar a retirada?',
                        icon: 'ti-shield-check',
                        confirmLabel: 'Aprovar'
                    };
                }
                if (isCallReleaseApproval) {
                    chosenPriority = await priorityDialog(activeCard);
                    if (!chosenPriority) {
                        return;
                    }
                } else {
                    var approveConfirmed = await checkboxConfirmation(approveConfig);
                    if (!approveConfirmed) {
                        return;
                    }
                }
            }

            setBusy(button, true);
            var data;

            if (action === 'take') {
                var takeChallenge = await checkboxConfirmation({
                    title: 'Pegar card',
                    message: 'Confirma que deseja assumir este card?',
                    confirmLabel: 'Pegar card'
                });
                if (!takeChallenge) {
                    return;
                }
                data = await api('take_card', { id: cardId });
                applyState(data);
                toast('Card atribuído a você.', 'success');
                await openCard(cardId);
            } else if (action === 'finalize') {
                data = await api('finalize_card', { id: cardId });
                applyState(data);
                toast(departingCard.workflow_type === 'call'
                    ? 'Chamado finalizado e enviado para aprovação.' : data.pending_card
                    ? 'Manutenção enviada para aprovação. Máquinas pendentes no card ' + (data.pending_card.code || ('#' + data.pending_card.id)) + '.'
                    : 'Manutenção finalizada e enviada para aprovação.', 'success');
                await openCard(data.approval ? data.approval.id : cardId);
            } else if (action === 'approve') {
                data = await api('approve_card', { id: cardId, priority: chosenPriority });
                applyState(data);
                scheduleDepartingCard(departingCard, 'approved');
                if (isDeletionApproval) {
                    toast('Exclusão aprovada. Card movido para Excluídos.', 'success');
                    closeModal();
                } else if (isEditApproval) {
                    toast('Edição aprovada e aplicada.', 'success');
                    await openCard(data.card.id);
                } else if (isSplitApproval) {
                    toast('Separação aprovada. Novo card criado.', 'success');
                    await openCard(data.card.id);
                } else if (isCallReleaseApproval) {
                    toast('Card liberado como ' + (priorityInfo(chosenPriority).label || chosenPriority) + '. Um técnico já pode clicar em Pegar.', 'success');
                    await openCard(data.card.id);
                } else {
                    toast(departingCard.workflow_type === 'call'
                        ? 'Chamado aprovado e concluído.' : data.card.family_ready
                        ? 'Manutenção aprovada. Família reunida para retirada.'
                        : 'Manutenção aprovada. Aguardando os demais cards da família.', 'success');
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
            + '<p class="tralla-muted">Selecione as máquinas e envie para aprovação. Após aprovada, a separação criará um novo card. Quando os dois forem finalizados e aprovados, as máquinas voltarão ao card de origem para a retirada.</p>'
            + '<div class="tralla-split-list">' + rows + '</div>'
            + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-git-branch"></i> Solicitar separação</button>'
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
            var approval = data.approval;
            applyState(data);
            closeModal();
            toast('Separação enviada para aprovação em ' + (approval.code || ('#' + approval.id)) + '.', 'success');
            await openCard(data.card.id);
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
            if (!await checkboxConfirmation({
                title: 'Solicitar edição',
                message: 'Confirma o envio das alterações para aprovação?',
                confirmLabel: 'Solicitar edição'
            })) {
                setBusy(submit, false);
                return;
            }
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

    function historyFilterOptions(items, selected, placeholder) {
        return '<option value="">' + esc(placeholder) + '</option>'
            + (items || []).map(function (item) {
                var value = String(item.value !== undefined ? item.value : item.id);
                return '<option value="' + attr(value) + '"' + (value === String(selected) ? ' selected' : '') + '>'
                    + esc(item.label !== undefined ? item.label : item.name) + '</option>';
            }).join('');
    }

    function renderHistoryModal(data) {
        var rows = Array.isArray(data.rows) ? data.rows : [];
        var options = data.options || {};
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
            + '<div class="tralla-field"><label for="tralla-history-user">Usuário do quadro</label>'
            + '<select id="tralla-history-user" name="users_id">'
            + historyFilterOptions(options.users, historyFilters.users_id, 'Todos os usuários') + '</select></div>'
            + '<div class="tralla-field tralla-history-machine-filter"><label for="tralla-history-machine">Máquina</label>'
            + '<input id="tralla-history-machine" type="text" name="machine_query" maxlength="255"'
            + ' value="' + attr(historyFilters.machine_query) + '" placeholder="Nome, série ou ID da máquina"></div>'
            + '<div class="tralla-field"><label for="tralla-history-model">Modelo</label>'
            + '<select id="tralla-history-model" name="model_id">'
            + historyFilterOptions(options.models, historyFilters.model_id, 'Todos os modelos') + '</select></div>'
            + '<div class="tralla-field"><label for="tralla-history-action">Tipo de atualização</label>'
            + '<select id="tralla-history-action" name="history_action">'
            + historyFilterOptions(options.actions, historyFilters.history_action, 'Todas as ações') + '</select></div>'
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
                users_id: historyFilters.users_id,
                machine_query: historyFilters.machine_query,
                model_id: historyFilters.model_id,
                history_action: historyFilters.history_action,
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

    function updateBulkSelection() {
        var all = Array.prototype.slice.call(modalRoot.querySelectorAll('[data-select-machine]'));
        var selected = all.filter(function (checkbox) { return checkbox.checked; });
        var selectAll = modalRoot.querySelector('[data-select-all-machines]');
        var count = modalRoot.querySelector('[data-bulk-count]');
        var editor = modalRoot.querySelector('[data-bulk-editor]');

        if (selectAll) {
            selectAll.checked = all.length > 0 && selected.length === all.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
        }
        if (count) {
            count.textContent = selected.length + (selected.length === 1 ? ' selecionada' : ' selecionadas');
        }
        if (editor) {
            editor.hidden = selected.length === 0;
        }
        all.forEach(function (checkbox) {
            checkbox.closest('.tralla-machine').classList.toggle('is-selected', checkbox.checked);
        });
    }

    async function applyBulkMachines(button) {
        if (!activeCard || !activeCard.permissions || !activeCard.permissions.edit_machines) {
            return;
        }
        var cardId = Number(activeCard.id);
        var machineIds = Array.prototype.slice.call(modalRoot.querySelectorAll('[data-select-machine]:checked'))
            .map(function (checkbox) { return Number(checkbox.dataset.selectMachine); });
        if (!machineIds.length) {
            toast('Selecione as máquinas que deseja editar.', 'error');
            return;
        }

        var changes = {};
        Array.prototype.forEach.call(modalRoot.querySelectorAll('[data-bulk-use]:checked'), function (checkbox) {
            var field = checkbox.dataset.bulkUse;
            var input = modalRoot.querySelector('[data-bulk-value="' + field + '"]');
            if (input) {
                changes[field] = input.value;
            }
        });
        if (!Object.keys(changes).length) {
            toast('Marque ao menos um campo para alterar.', 'error');
            return;
        }
        if (changes.status === '__select') {
            toast('Selecione o novo status.', 'error');
            return;
        }

        var confirmed = await confirmDialog({
            title: 'Aplicar edição em massa',
            message: 'Atualizar ' + machineIds.length + (machineIds.length === 1 ? ' máquina' : ' máquinas')
                + ' deste card?' + (Object.prototype.hasOwnProperty.call(changes, 'report')
                    ? ' O relatório atual das selecionadas será substituído.' : ''),
            confirmLabel: 'Aplicar alterações',
            icon: 'ti-device-floppy'
        });
        if (!confirmed || !activeCard || Number(activeCard.id) !== cardId) {
            return;
        }

        setBusy(button, true);
        try {
            Array.prototype.forEach.call(modalRoot.querySelectorAll('[data-machine-id]'), flushMachineSave);
            await machineSaveQueue;
            if (!activeCard || Number(activeCard.id) !== cardId) {
                return;
            }
            var data = await api('machine_bulk_update', Object.assign({
                card_id: cardId,
                machine_ids: machineIds
            }, changes));
            applyState(data);
            if (activeCard && Number(activeCard.id) === cardId) {
                activeCard = data.card;
                renderCardModal(data.card);
            }
            shouldRefreshBoard = false;
            toast('Máquinas atualizadas.', 'success');
        } catch (error) {
            setBusy(button, false);
            toast(error.message, 'error');
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
            return '<button type="button" class="tralla-combobox-option" role="option"'
                + ' id="' + attr(list.id) + '-entity-' + index + '" aria-selected="false"'
                + ' data-entity-name="' + attr(name) + '" data-entity-id="' + Number(entity.id) + '">'
                + '<span>' + esc(name) + '</span></button>';
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
        input.classList.remove('is-entity-invalid');
        input.classList.add('is-entity-selected');
        var entityId = document.getElementById(input.id + '-entity-id');
        if (entityId) {
            entityId.value = option.dataset.entityId || '';
        }
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
        input.addEventListener('input', function () {
            input.classList.remove('is-entity-selected', 'is-entity-invalid');
            var entityId = document.getElementById(input.id + '-entity-id');
            if (entityId) {
                entityId.value = '';
            }
            openList();
        });
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

    function createUpdateItemRow(first) {
        return '<div class="tralla-update-create-item">'
            + '<input class="tralla-update-create-title" type="text" maxlength="500" placeholder="'
            + (first ? 'Primeiro item' : 'Novo item') + '">'
            + '<label class="tralla-update-create-attach" title="Anexar até 10 arquivos de 100 MB">'
            + '<i class="ti ti-paperclip" aria-hidden="true"></i><span>Anexar</span>'
            + '<input type="file" multiple data-update-create-files></label>'
            + (first ? '' : '<button type="button" class="tralla-icon-btn" data-update-remove-create-item'
                + ' aria-label="Remover item" title="Remover item"><i class="ti ti-x"></i></button>')
            + '<small class="tralla-update-create-file-count">Nenhum arquivo selecionado</small></div>';
    }

    function openCreateUpdateCard(list) {
        if (addCardBlockedCategories[list.category]) { return; }
        showModal('<form id="tralla-create-update-form" class="tralla-form" data-list-id="'
            + Number(list.id) + '">'
            + '<div class="tralla-modal-head"><div><h2><i class="ti ti-refresh"></i> Nova atualização</h2>'
            + '<p>' + esc(list.name) + '</p></div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">'
            + '<div class="tralla-field"><label for="tralla-update-title">Título *</label>'
            + '<input id="tralla-update-title" name="name" type="text" maxlength="255" required autofocus></div>'
            + '<fieldset class="tralla-card-workflow-choice"><legend>Como deseja organizar?</legend>'
            + '<div class="tralla-card-workflow-options">'
            + '<label class="tralla-card-workflow-option"><input type="radio" name="mode" value="description" checked>'
            + '<span><i class="ti ti-file-text"></i><strong>Descrição</strong><small>Explique a atualização em texto e anexe arquivos depois</small></span></label>'
            + '<label class="tralla-card-workflow-option"><input type="radio" name="mode" value="checklist">'
            + '<span><i class="ti ti-list-check"></i><strong>Checklist</strong><small>Adicione itens e arquivos a cada etapa</small></span></label>'
            + '</div></fieldset>'
            + '<div class="tralla-field" data-update-description><label for="tralla-update-description">Descrição</label>'
            + '<textarea id="tralla-update-description" name="description" rows="7" maxlength="200000"'
            + ' placeholder="Descreva a situação ou o que precisa ser atualizado..."></textarea></div>'
            + '<div class="tralla-field" data-update-checklist hidden><label>Itens da checklist</label>'
            + '<div id="tralla-update-create-items">' + createUpdateItemRow(true) + '</div>'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-update-add-create-item>'
            + '<i class="ti ti-plus"></i> Adicionar item</button>'
            + '<small>Até 10 arquivos de 100 MB por item. Você também poderá adicionar arquivos depois.</small></div>'
            + '<div class="tralla-call-file-progress" data-update-create-progress role="status" hidden></div>'
            + '</div><div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">Criar card</button></div></form>');
    }

    async function submitCreateUpdate(form) {
        var name = form.querySelector('[name="name"]').value.trim();
        var mode = form.querySelector('[name="mode"]:checked').value;
        var rows = Array.prototype.map.call(form.querySelectorAll('.tralla-update-create-item'), function (row) {
            var files = Array.prototype.slice.call(row.querySelector('[data-update-create-files]').files || []);
            return { title: row.querySelector('.tralla-update-create-title').value.trim(), files: files };
        });
        var items = rows.filter(function (row) { return row.title; });
        if (!name) { toast('Informe o título da atualização.', 'error'); return; }
        if (mode === 'checklist' && rows.some(function (row) { return !row.title && row.files.length; })) {
            toast('Dê um nome a cada item que contém arquivos.', 'error'); return;
        }
        if (mode === 'checklist' && items.some(function (row) { return row.files.length > 10; })) {
            toast('Cada item aceita até 10 arquivos.', 'error'); return;
        }
        if (mode === 'checklist' && items.some(function (row) {
            return row.files.some(function (file) { return file.size < 1 || file.size > 104857600; });
        })) { toast('Cada arquivo deve ter até 100 MB e não pode estar vazio.', 'error'); return; }
        var button = form.querySelector('button[type="submit"]');
        var progress = form.querySelector('[data-update-create-progress]');
        setBusy(button, true);
        var createdCardId = 0;
        var uploadError = null;
        try {
            var data = await api('update_create', {
                boards_id: state.board.id, list_id: Number(form.dataset.listId), name: name,
                mode: mode, description: form.querySelector('[name="description"]').value,
                items: mode === 'checklist' ? items.map(function (row) { return row.title; }) : []
            });
            createdCardId = Number(data.card.id);
            applyState(data);
            if (mode === 'checklist' && items.some(function (row) { return row.files.length; })) {
                var savedItems = data.card.update_items || [];
                if (savedItems.length !== items.length) {
                    throw new Error('O card foi criado, mas não foi possível associar os arquivos aos itens.');
                }
                progress.hidden = false;
                for (var i = 0; i < items.length; i++) {
                    if (!items[i].files.length) { continue; }
                    var itemTitle = items[i].title;
                    await uploadFileChunks(createdCardId, Number(savedItems[i].id), items[i].files,
                        function (fileName, percent) {
                            progress.textContent = 'Item ' + (i + 1) + '/' + items.length + ': '
                                + itemTitle + ' · ' + fileName + ' · ' + percent + '%';
                        });
                }
            }
        } catch (error) {
            if (!createdCardId) {
                toast(error.message, 'error');
                setBusy(button, false);
                return;
            }
            uploadError = error;
        }
        closeModal();
        try {
            await refreshState();
            await openCard(createdCardId);
        } catch (error) {
            toast('Card criado. Atualize a página para ver todos os arquivos: ' + error.message, 'error');
            return;
        }
        toast(uploadError
            ? 'Card criado, mas nem todos os arquivos foram enviados: ' + uploadError.message
            : 'Atualização criada.', uploadError ? 'error' : 'success');
    }

    var updateTextTimers = {};
    var updateTextQueue = Promise.resolve();

    function queueUpdateText(input) {
        var field = input.dataset.updateText;
        if (!activeCard || !field) { return; }
        var id = Number(activeCard.id);
        var value = input.value;
        input.dataset.saveState = 'Salvando...';
        window.clearTimeout(updateTextTimers[field]);
        updateTextTimers[field] = window.setTimeout(function () {
            delete updateTextTimers[field];
            updateTextQueue = updateTextQueue.catch(function () { }).then(async function () {
                var data = await api('update_text', { id: id, field: field, value: value });
                if (activeCard && Number(activeCard.id) === id) {
                    activeCard[field] = data.card[field];
                    input.dataset.saveState = 'Salvo';
                }
                applyState(data);
            }).catch(function (error) {
                input.dataset.saveState = 'Falha ao salvar';
                toast(error.message, 'error');
            });
        }, 600);
    }

    async function flushUpdateText() {
        Object.keys(updateTextTimers).forEach(function (field) {
            window.clearTimeout(updateTextTimers[field]);
            delete updateTextTimers[field];
        });
        if (!activeCard) { return; }
        var card = activeCard;
        var id = Number(card.id);
        var inputs = Array.prototype.slice.call(modalRoot.querySelectorAll('[data-update-text]'));
        await updateTextQueue;
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            if (input.value !== String(card[input.dataset.updateText] || '')) {
                var data = await api('update_text', { id: id, field: input.dataset.updateText, value: input.value });
                card[input.dataset.updateText] = data.card[input.dataset.updateText];
                applyState(data);
            }
        }
    }

    async function updateTransition(action, cardId) {
        var id = Number(cardId || (activeCard && activeCard.id));
        if (!id) { return; }
        try {
            if (activeCard && Number(activeCard.id) === id) { await flushUpdateText(); }
            var data = await api('update_transition', { id: id, transition: action });
            applyState(data);
            if (activeCard && Number(activeCard.id) === id) { await openCard(id); }
            toast(action === 'take' ? 'Atualização assumida.'
                : (action === 'schedule' ? 'Movida para Pendente.' : 'Atualização concluída.'), 'success');
        } catch (error) { toast(error.message, 'error'); }
    }

    async function updateItem(action, itemId, title) {
        if (!activeCard) { return; }
        var id = Number(activeCard.id);
        try {
            await flushUpdateText();
            var data = await api('update_item', {
                id: id, item_id: Number(itemId || 0), item_action: action, title: title || ''
            });
            applyState(data);
            await openCard(id);
        } catch (error) { toast(error.message, 'error'); }
    }

    function openCreateCard(listId) {
        var list = state.lists.find(function (item) {
            return Number(item.id) === Number(listId);
        });
        if (!list) {
            return;
        }

        if (isUpdates) {
            openCreateUpdateCard(list);
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
            ? '<section class="tralla-detail-section" data-create-machines-section>'
                + '<h3><i class="ti ti-device-desktop"></i> Máquinas *</h3>'
                + '<div id="tralla-create-machines"></div>'
                + '<button type="button" class="tralla-add-card" data-add-create-machine style="margin:0;padding-left:0">'
                + '<i class="ti ti-plus"></i> Adicionar outro modelo</button>'
                + '</section>'
            : '';

        var entities = Array.isArray(state.entities) ? state.entities : [];
        var hasEntities = entities.length > 0;
        var callCategories = state.call_categories || {};
        var categoryOptions = Object.keys(callCategories).map(function (category) {
            return '<option value="' + attr(category) + '">' + esc(category) + '</option>';
        }).join('');
        var categoryFields = isMaintenance
            ? '<div class="tralla-form-row" data-create-category-section hidden>'
                + '<div class="tralla-field"><label for="tralla-create-category">Categoria *</label>'
                + '<select id="tralla-create-category" name="call_category" required>'
                + '<option value="">Selecione uma categoria...</option>' + categoryOptions + '</select></div>'
                + '<div class="tralla-field"><label for="tralla-create-subcategory">Subcategoria *</label>'
                + '<select id="tralla-create-subcategory" name="call_subcategory" required disabled>'
                + '<option value="">Selecione a categoria primeiro...</option></select></div></div>'
            : '';
        var nameField = '<div class="tralla-field tralla-combobox-field">'
            + '<label for="tralla-create-name">Nome do card *</label>'
            + '<div class="tralla-combobox">'
            + '<input id="tralla-create-name" type="text" name="name" maxlength="255" required autocomplete="off"'
            + ' placeholder="Busque e selecione uma entidade..."'
            + (hasEntities
                ? ' role="combobox" aria-autocomplete="list" aria-expanded="false"'
                    + ' aria-controls="tralla-create-name-list" aria-haspopup="listbox"'
                : '')
            + '>'
            + '<input id="tralla-create-name-entity-id" type="hidden" name="entity_id" value="">'
            + (hasEntities
                ? '<div id="tralla-create-name-list" class="tralla-combobox-list" role="listbox" hidden></div>'
                : '')
            + '</div><small>Digite para buscar e clique em uma entidade da lista.</small></div>';

        showModal('<form id="tralla-create-card-form" class="tralla-form" data-list-id="' + Number(listId) + '" novalidate>'
            + '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-plus"></i> Novo card</h2>'
            + '<p>' + esc(list.name) + ' · ' + (isMaintenance ? 'Fluxo de manutenção' : 'Quadro normal') + '</p>'
            + '</div>' + modalCloseButton() + '</div>'
            + '<div class="tralla-modal-body">'
            + '<fieldset class="tralla-card-workflow-choice"><legend>Qual tipo de card?</legend>'
            + '<div class="tralla-card-workflow-options">'
            + '<label class="tralla-card-workflow-option"><input type="radio" name="workflow_type" value="maintenance" checked>'
            + '<span><i class="ti ti-tool"></i><strong>Manutenção</strong><small>Equipamentos, aprovação e retirada</small></span></label>'
            + '<label class="tralla-card-workflow-option"><input type="radio" name="workflow_type" value="call">'
            + '<span><i class="ti ti-headset"></i><strong>Chamado</strong><small>Situação, atendimento e aprovação</small></span></label>'
            + '</div>'
            + '</fieldset>'
            + '<section class="tralla-create-basics">'
            + '<h3><i class="ti ti-list-details"></i> Dados do atendimento</h3>'
            + nameField
            + categoryFields
            + '</section>'
            + machinesSection
            + '<section class="tralla-detail-section tralla-create-call-description" data-create-call-section hidden>'
            + '<div class="tralla-field"><label for="tralla-create-description">Descrição da situação *</label>'
            + '<textarea id="tralla-create-description" name="description" rows="6" maxlength="200000"'
            + ' placeholder="Descreva o que aconteceu, o impacto e as informações necessárias para o atendimento..."></textarea>'
            + '<small>O técnico poderá registrar o relatório do atendimento ao assumir o chamado.</small></div></section>'
            + (state.board && state.board.can_defer_call
                ? '<fieldset class="tralla-card-workflow-choice tralla-call-release-choice" data-call-release-section hidden>'
                    + '<legend>Quando disponibilizar o chamado?</legend>'
                    + '<div class="tralla-card-workflow-options">'
                    + '<label class="tralla-card-workflow-option"><input type="radio" name="release_mode" value="later" checked>'
                    + '<span><i class="ti ti-lock"></i><strong>Disponibilizar depois</strong><small>Bloqueia o chamado e envia para Manutenção Aprovação, onde a prioridade é definida</small></span></label>'
                    + '<label class="tralla-card-workflow-option"><input type="radio" name="release_mode" value="now">'
                    + '<span><i class="ti ti-lock-open"></i><strong>Disponibilizar agora</strong><small>Somente chamados P1 (Alta). O botão Pegar fica disponível imediatamente</small></span></label>'
                    + '</div>'
                    + '<div class="tralla-p1-confirm" data-p1-confirm hidden role="alert">'
                    + '<div class="tralla-p1-confirm-head"><i class="ti ti-alert-triangle" aria-hidden="true"></i>'
                    + '<strong>ATENÇÃO: este chamado será tratado como P1 · PRIORIDADE ALTA</strong></div>'
                    + '<p>Disponibilizar agora pula a aprovação e define a prioridade <b>P1 (ALTA)</b> automaticamente. '
                    + 'Os prazos começam a contar agora: <b>1º atendimento em ' + esc(priorityInfo('P1').first || '30 min') + '</b>, '
                    + '<b>resposta paliativa em ' + esc(priorityInfo('P1').palliative || '4 h') + '</b> e '
                    + '<b>resposta definitiva em ' + esc(priorityInfo('P1').definitive || '8 h') + '</b>.</p>'
                    + '<p>Use somente para situações críticas, que param o atendimento. Se não for urgente, escolha <b>Disponibilizar depois</b>.</p>'
                    + '<label class="tralla-p1-confirm-check"><input type="checkbox" name="confirm_p1" value="1">'
                    + '<span>Tenho certeza de que este chamado é <b>NÍVEL P1 (ALTO)</b></span></label>'
                    + '</div></fieldset>'
                : '')
            + (isMaintenance ? '<p class="tralla-create-approval-note" data-approval-note>'
                + '<i class="ti ti-info-circle" aria-hidden="true"></i>'
                + '<span>O card será criado <b>bloqueado</b> em Manutenção Pendente e enviado para <b>Manutenção Aprovação</b>, '
                + 'onde o aprovador define a prioridade (P1 a P5) e os prazos de SLA.</span></p>' : '')
            + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-tralla-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-check"></i> Criar card</button>'
            + '</div></form>');

        if (isMaintenance) {
            addCreateMachineRow();
        }
        var createForm = modalRoot.querySelector('#tralla-create-card-form');
        var categorySelect = createForm.querySelector('#tralla-create-category');
        var subcategorySelect = createForm.querySelector('#tralla-create-subcategory');
        if (categorySelect && subcategorySelect) {
            categorySelect.addEventListener('change', function () {
                var choices = callCategories[categorySelect.value] || [];
                subcategorySelect.innerHTML = '<option value="">Selecione uma subcategoria...</option>'
                    + choices.map(function (value) {
                        return '<option value="' + attr(value) + '">' + esc(value) + '</option>';
                    }).join('');
                subcategorySelect.disabled = !choices.length;
            });
        }
        function syncReleaseUi() {
            var typeField = createForm.querySelector('[name="workflow_type"]:checked');
            var call = !!typeField && typeField.value === 'call';
            var releaseSection = createForm.querySelector('[data-call-release-section]');
            var modeField = createForm.querySelector('[name="release_mode"]:checked');
            var now = call && !!releaseSection && !!modeField && modeField.value === 'now';
            var p1Box = createForm.querySelector('[data-p1-confirm]');
            if (p1Box) {
                p1Box.hidden = !now;
                if (!now) {
                    var p1Check = p1Box.querySelector('[name="confirm_p1"]');
                    if (p1Check) { p1Check.checked = false; }
                }
            }
            var note = createForm.querySelector('[data-approval-note]');
            if (note) { note.hidden = now; }
        }
        createForm.querySelectorAll('[name="workflow_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var call = radio.value === 'call' && radio.checked;
                if (!radio.checked) { return; }
                createForm.querySelector('[data-create-machines-section]').hidden = call;
                createForm.querySelector('[data-create-call-section]').hidden = !call;
                var categorySection = createForm.querySelector('[data-create-category-section]');
                if (categorySection) { categorySection.hidden = !call; }
                var releaseSection = createForm.querySelector('[data-call-release-section]');
                if (releaseSection) { releaseSection.hidden = !call; }
                syncReleaseUi();
            });
        });
        createForm.querySelectorAll('[name="release_mode"]').forEach(function (radio) {
            radio.addEventListener('change', syncReleaseUi);
        });
        var p1CheckField = createForm.querySelector('[name="confirm_p1"]');
        if (p1CheckField) {
            p1CheckField.addEventListener('change', function () {
                var box = p1CheckField.closest('[data-p1-confirm]');
                if (box) { box.classList.toggle('is-confirmed', p1CheckField.checked); box.classList.remove('is-invalid'); }
            });
        }
        syncReleaseUi();
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
        var workflowType = form.querySelector('[name="workflow_type"]:checked')
            ? form.querySelector('[name="workflow_type"]:checked').value : 'maintenance';
        var releaseModeField = form.querySelector('[name="release_mode"]:checked');
        var releaseMode = workflowType === 'call' && releaseModeField ? releaseModeField.value : 'later';
        var confirmP1Field = form.querySelector('[name="confirm_p1"]');
        var confirmP1 = !!(confirmP1Field && confirmP1Field.checked);
        if (!inline && isMaintenance && releaseMode === 'now' && !confirmP1) {
            toast('Para disponibilizar agora, marque a confirmação de que o chamado é P1 (ALTO).', 'error');
            if (confirmP1Field) {
                var p1Box = confirmP1Field.closest('[data-p1-confirm]');
                if (p1Box) { p1Box.classList.add('is-invalid'); }
                confirmP1Field.focus();
            }
            return;
        }
        var descriptionField = form.querySelector('[name="description"]');
        var description = descriptionField ? descriptionField.value.trim() : '';
        var categoryField = form.querySelector('[name="call_category"]');
        var subcategoryField = form.querySelector('[name="call_subcategory"]');
        var callCategory = categoryField && workflowType === 'call' ? categoryField.value : '';
        var callSubcategory = subcategoryField && workflowType === 'call' ? subcategoryField.value : '';
        var validSubcategories = (state.call_categories || {})[callCategory] || [];
        if (!inline && isMaintenance && workflowType === 'call' && !validSubcategories.includes(callSubcategory)) {
            toast('Selecione uma categoria e subcategoria.', 'error');
            if (!callCategory && categoryField) { categoryField.focus(); }
            else if (subcategoryField) { subcategoryField.focus(); }
            return;
        }
        var entityIdField = form.querySelector('[name="entity_id"]');
        var entityId = entityIdField ? entityIdField.value : '';
        if (!inline && isMaintenance && entityId === '') {
            toast('Selecione uma entidade da lista para o nome do card.', 'error');
            nameEl.classList.add('is-entity-invalid');
            nameEl.focus();
            return;
        }
        if (workflowType === 'call' && !description) {
            toast('Descreva a situação do chamado.', 'error');
            descriptionField.focus();
            return;
        }
        var invalidQuantity = false;
        Array.prototype.forEach.call(workflowType === 'call' ? [] : form.querySelectorAll('[data-create-machine]'), function (row) {
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

        if (!inline && isMaintenance && workflowType !== 'call' && !machines.length) {
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
                entity_id: entityId,
                description: description,
                call_category: callCategory,
                call_subcategory: callSubcategory,
                source: 'manual',
                workflow_type: workflowType,
                release_mode: releaseMode,
                confirm_p1: confirmP1 ? 1 : 0,
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
            toast(isMaintenance
                ? (releaseMode === 'now'
                    ? 'Chamado P1 (Alta) criado e disponível para Pegar.'
                    : 'Card criado bloqueado e enviado para Manutenção Aprovação.')
                : 'Card criado.', 'success');
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
            + attr(existing ? existing.name : '') + '"'
            + (existing && !(state.board && state.board.can_rename_lists) ? ' readonly' : '') + '>'
            + (existing && !(state.board && state.board.can_rename_lists)
                ? '<small>Somente SuperAdmin deste quadro pode alterar o título da lista.</small>' : '')
            + '</div>'
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

        var updateTakeButton = event.target.closest('[data-update-take]');
        if (updateTakeButton) {
            event.preventDefault();
            event.stopPropagation();
            updateTransition('take', Number(updateTakeButton.dataset.updateTake));
            return;
        }

        if (event.target.closest('.tralla-card-term, .tralla-card-sign')) {
            event.stopPropagation();
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
        if (event.target.closest('[data-card-take], [data-update-take], .tralla-card-term, .tralla-card-sign')) {
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
        if (!cardElement || isMaintenance || !can.move_card || moveInFlight || searchQuery !== '') {
            event.preventDefault();
            return;
        }
        dragCardId = Number(cardElement.dataset.cardId);
        dragSource = cardElement;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(dragCardId));
        // Let the browser capture the original card as the cursor image first.
        window.setTimeout(function () {
            if (dragSource === cardElement) {
                cardElement.classList.add('is-dragging');
            }
        }, 0);
    });

    listsEl.addEventListener('dragend', function () {
        dragCardId = null;
        clearDropPreview();
    });

    listsEl.addEventListener('dragover', function (event) {
        var container = dropContainer(event.target);
        if (!container || dragCardId === null) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        positionDropPreview(container, event.clientY);
    });

    listsEl.addEventListener('dragleave', function (event) {
        var bounds = listsEl.getBoundingClientRect();
        var outside = event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom;
        if (outside && !listsEl.contains(event.relatedTarget)) {
            clearDropPreview(true);
        }
    });

    listsEl.addEventListener('drop', async function (event) {
        var container = dropContainer(event.target);
        if (!container || dragCardId === null) {
            return;
        }
        event.preventDefault();
        positionDropPreview(container, event.clientY);
        var movingId = dragCardId;
        var listId = Number(container.dataset.listId);
        var rank = computeDropRank(container, movingId);
        var card = getCard(movingId);
        var originalListId = card ? Number(card.plugin_tralla_lists_id) : 0;
        var originalRank = card ? Number(card.rank) : 0;
        dragCardId = null;
        clearDropPreview();

        if (!card || !Number.isFinite(rank)) {
            return;
        }

        // Show the resulting board immediately while the server saves the move.
        card.plugin_tralla_lists_id = listId;
        card.rank = rank;
        moveInFlight = true;
        renderBoard();

        try {
            var data = await api('card_move', {
                id: movingId,
                list_id: listId,
                rank: rank
            });
            applyState(data);
        } catch (error) {
            card.plugin_tralla_lists_id = originalListId;
            card.rank = originalRank;
            renderBoard();
            toast(error.message, 'error');
        } finally {
            moveInFlight = false;
        }
    });

    modalRoot.addEventListener('click', function (event) {
        if (event.target.closest('[data-tralla-close]')) {
            closeModal();
            return;
        }

        if (event.target.closest('[data-finalize-requirements]')) {
            event.preventDefault();
            if (activeCard) { showFinalizeRequirements(activeCard); }
            return;
        }

        var openCardButton = event.target.closest('[data-open-card]');
        if (openCardButton) {
            openCard(Number(openCardButton.dataset.openCard));
            return;
        }

        var deleteFileButton = event.target.closest('[data-call-file-delete]');
        if (deleteFileButton) {
            event.preventDefault();
            deleteCallFile(Number(deleteFileButton.dataset.callFileDelete));
            return;
        }

        var transitionButton = event.target.closest('[data-update-transition]');
        if (transitionButton) {
            updateTransition(transitionButton.dataset.updateTransition);
            return;
        }
        if (event.target.closest('[data-update-add-create-item]')) {
            var itemList = modalRoot.querySelector('#tralla-update-create-items');
            if (itemList) {
                itemList.insertAdjacentHTML('beforeend', createUpdateItemRow(false));
                itemList.lastElementChild.querySelector('.tralla-update-create-title').focus();
            }
            return;
        }
        var removeCreateItem = event.target.closest('[data-update-remove-create-item]');
        if (removeCreateItem) {
            removeCreateItem.closest('.tralla-update-create-item').remove();
            return;
        }
        var renameItemButton = event.target.closest('[data-update-rename]');
        if (renameItemButton && activeCard) {
            var renameId = Number(renameItemButton.dataset.updateRename);
            var renameItem = (activeCard.update_items || []).find(function (item) { return Number(item.id) === renameId; });
            if (renameItem) {
                promptDialog({ title: 'Renomear item', label: 'Título', value: renameItem.title,
                    icon: 'ti-pencil', required: true, confirmLabel: 'Salvar' }).then(function (title) {
                    if (title !== null) { updateItem('rename', renameId, title); }
                });
            }
            return;
        }
        var deleteItemButton = event.target.closest('[data-update-delete]');
        if (deleteItemButton) {
            var deleteId = Number(deleteItemButton.dataset.updateDelete);
            confirmDialog({ title: 'Excluir item', message: 'Excluir este item da checklist?',
                icon: 'ti-trash', tone: 'danger', confirmLabel: 'Excluir' }).then(function (ok) {
                    if (ok) { updateItem('delete', deleteId); }
                });
            return;
        }

        var saveButton = event.target.closest('[data-save-card]');
        if (saveButton) {
            saveCard(saveButton);
            return;
        }
        var saveCallButton = event.target.closest('[data-save-call-report]');
        if (saveCallButton) {
            saveCallReport(saveCallButton);
            return;
        }

        var bulkApplyButton = event.target.closest('[data-bulk-apply]');
        if (bulkApplyButton) {
            applyBulkMachines(bulkApplyButton);
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
            historyFilters = {
                card_id: '', date_from: '', date_to: '', users_id: '', machine_query: '', model_id: '', history_action: ''
            };
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

        if (form.id === 'tralla-call-file-form' || form.classList.contains('tralla-update-file-form')) {
            event.preventDefault();
            uploadCallFiles(form);
            return;
        }

        if (form.id === 'tralla-create-update-form') {
            event.preventDefault();
            submitCreateUpdate(form);
            return;
        }

        if (form.id === 'tralla-update-add-item') {
            event.preventDefault();
            var title = form.querySelector('[name="title"]').value.trim();
            if (title) { updateItem('add', 0, title); }
            return;
        }

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
                date_to: dateToInput ? dateToInput.value : '',
                users_id: form.querySelector('[name="users_id"]').value,
                machine_query: form.querySelector('[name="machine_query"]').value.trim(),
                model_id: form.querySelector('[name="model_id"]').value,
                history_action: form.querySelector('[name="history_action"]').value
            };
            historyPage = 1;
            openHistoryModal();
        }
    });

    modalRoot.addEventListener('input', function (event) {
        if (event.target.matches('[data-update-text]')) {
            queueUpdateText(event.target);
            return;
        }
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
        if (event.target.matches('[data-update-create-files]')) {
            var fileInput = event.target;
            var selected = Array.prototype.slice.call(fileInput.files || []);
            var countLabel = fileInput.closest('.tralla-update-create-item')
                .querySelector('.tralla-update-create-file-count');
            if (selected.length > 10 || selected.some(function (file) {
                return file.size < 1 || file.size > 104857600;
            })) {
                fileInput.value = '';
                countLabel.textContent = 'Nenhum arquivo selecionado';
                toast('Selecione até 10 arquivos por item, com até 100 MB cada.', 'error');
                return;
            }
            countLabel.textContent = selected.length
                ? selected.length + (selected.length === 1 ? ' arquivo selecionado: ' : ' arquivos selecionados: ')
                    + selected.map(function (file) { return file.name; }).join(', ')
                : 'Nenhum arquivo selecionado';
            return;
        }
        if (event.target.matches('#tralla-create-update-form [name="mode"]')) {
            var createUpdateForm = event.target.closest('form');
            var checklistMode = event.target.value === 'checklist';
            createUpdateForm.querySelector('[data-update-description]').hidden = checklistMode;
            createUpdateForm.querySelector('[data-update-checklist]').hidden = !checklistMode;
            return;
        }
        if (event.target.matches('[data-update-toggle]')) {
            updateItem('toggle', Number(event.target.dataset.updateToggle));
            return;
        }
        if (event.target.matches('[data-select-all-machines]')) {
            var checked = event.target.checked;
            Array.prototype.forEach.call(modalRoot.querySelectorAll('[data-select-machine]'), function (checkbox) {
                checkbox.checked = checked;
            });
            updateBulkSelection();
            return;
        }
        if (event.target.matches('[data-select-machine]')) {
            updateBulkSelection();
            return;
        }
        if (event.target.matches('[data-bulk-use]')) {
            var bulkInput = modalRoot.querySelector('[data-bulk-value="' + event.target.dataset.bulkUse + '"]');
            if (bulkInput) {
                bulkInput.disabled = !event.target.checked;
                if (event.target.checked) {
                    bulkInput.focus();
                }
            }
            return;
        }

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
