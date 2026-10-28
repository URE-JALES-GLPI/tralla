/**
 * [URE] Tralla — cards do contato abertos pelo WhatsAppSimples.
 *
 * Três telas no padrão do Kanban:
 * - Cards do contato: chamados ainda não concluídos, com Editar e Finalizar, e "Criar card";
 * - Novo card: o modal do Kanban restrito a Chamado, com a prioridade escolhida por quem abre
 *   (o card nasce direto em Manutenção Em Andamento);
 * - Card: descrição e relatório do atendimento editáveis, SLA, detalhes e conversa do WhatsApp.
 *
 * Os dados passam pelo WhatsAppSimples, que valida o atendimento e vincula a conversa.
 *
 * Uso: TrallaWhatsappCard.open({ chatId, contactName, url, request, onChanged })
 *   request(url, formData?) -> Promise<json> (o WhatsAppSimples cuida do CSRF)
 */
(function () {
    'use strict';

    var escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    var statusClasses = {
        pending: 'pending',
        in_progress: 'is-in-progress',
        waiting_approval: 'approval',
        withdrawal: 'withdrawal',
        done: 'done',
        approved: 'done',
        rejected: 'danger'
    };
    var root = null;
    var toastRoot = null;
    var options = null;
    var settings = null;
    var activeCard = null;
    var clockTimer = null;

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (character) {
                return escapeMap[character];
            });
    }

    function displayCode(code, id) {
        return '#' + String(code || id || '').replace(/^#+/, '');
    }

    function formatDate(value) {
        if (!value) {
            return '—';
        }
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) {
            return String(value);
        }
        return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(date);
    }

    function ensureRoots() {
        if (!root) {
            root = document.createElement('div');
            root.id = 'tralla-wa-modal-root';
            document.body.appendChild(root);
            root.addEventListener('click', onClick);
            root.addEventListener('change', onChange);
            root.addEventListener('submit', onSubmit);
        }
        if (!toastRoot) {
            toastRoot = document.getElementById('tralla-toast-root');
            if (!toastRoot) {
                toastRoot = document.createElement('div');
                toastRoot.id = 'tralla-toast-root';
                toastRoot.setAttribute('aria-live', 'polite');
                document.body.appendChild(toastRoot);
            }
        }
    }

    function toast(message, type) {
        var element = document.createElement('div');
        element.className = 'tralla-toast' + (type === 'error' ? ' is-error' : (type === 'success' ? ' is-success' : ''));
        element.innerHTML = '<i class="ti ' + (type === 'error' ? 'ti-alert-triangle' : 'ti-circle-check') + '"></i>'
            + '<span>' + esc(message) + '</span>';
        toastRoot.appendChild(element);
        window.setTimeout(function () {
            element.remove();
        }, 4200);
    }

    function show(html, wide) {
        root.innerHTML = '<div class="tralla-modal-backdrop" data-wa-backdrop>'
            + '<div class="tralla-modal' + (wide ? ' is-wide' : '') + '" role="dialog" aria-modal="true">' + html + '</div></div>';
        document.body.classList.add('tralla-modal-open');
    }

    function close() {
        if (root) {
            root.innerHTML = '';
        }
        activeCard = null;
        window.clearInterval(clockTimer);
        document.body.classList.remove('tralla-modal-open');
    }

    function head(icon, title, subtitle) {
        return '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ' + icon + '"></i> ' + esc(title) + '</h2>'
            + (subtitle ? '<p>' + esc(subtitle) + '</p>' : '')
            + '</div><button type="button" class="tralla-icon-btn" data-wa-close aria-label="Fechar">'
            + '<i class="ti ti-x"></i></button></div>';
    }

    function showLoading(title) {
        show(head('ti-loader-2', title, settings.contactName || '')
            + '<div class="tralla-modal-body"><p class="tralla-empty-mini">Carregando...</p></div>');
    }

    function get(action, params) {
        var query = '?action=' + encodeURIComponent(action) + '&chat_id=' + Number(settings.chatId);
        Object.keys(params || {}).forEach(function (key) {
            query += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        });
        return settings.request(settings.url + query);
    }

    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('chat_id', settings.chatId);
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key]);
        });
        return settings.request(settings.url, body);
    }

    function changed() {
        if (typeof settings.onChanged === 'function') {
            settings.onChanged();
        }
    }

    function priorityBadge(priority) {
        return priority
            ? '<span class="tralla-card-badge tralla-priority-badge is-' + esc(String(priority).toLowerCase()) + '">' + esc(priority) + '</span>'
            : '';
    }

    function statusPill(status, label) {
        return '<span class="tralla-pill ' + esc(statusClasses[status] || '') + '">' + esc(label) + '</span>';
    }

    /* ------------------------------------------------ cards do contato */

    async function showList() {
        showLoading('Cards do contato');
        var result = await get('list');
        if (!result || !result.success) {
            close();
            toast((result && result.error) || 'Não foi possível carregar os cards do Tralla.', 'error');
            return;
        }
        var cards = result.cards || [];
        var body = cards.length
            ? cards.map(function (card) {
                return '<section class="tralla-detail-section tralla-wa-card-row">'
                    + '<div class="tralla-wa-card-main">'
                    + '<div class="tralla-detail-code">' + esc(displayCode(card.code, card.id)) + ' · ' + esc(card.list_name) + '</div>'
                    + '<strong class="tralla-wa-card-name">' + esc(card.name) + '</strong>'
                    + '<div class="tralla-detail-sub">' + priorityBadge(card.priority) + statusPill(card.status, card.status_label)
                    + '<span>' + esc(card.technician_name || 'Sem técnico') + '</span>'
                    + '<span>Aberto em ' + esc(formatDate(card.date_creation)) + '</span></div>'
                    + '</div>'
                    + '<div class="tralla-wa-card-actions">'
                    + '<button type="button" class="tralla-btn tralla-btn-ghost" data-wa-open-card="' + Number(card.id) + '">'
                    + '<i class="ti ' + (card.wa && card.wa.edit ? 'ti-pencil' : 'ti-eye') + '"></i> '
                    + (card.wa && card.wa.edit ? 'Editar' : 'Ver') + '</button>'
                    + (card.wa && card.wa.finalize
                        ? '<button type="button" class="tralla-btn tralla-btn-primary" data-wa-finalize="' + Number(card.id)
                            + '" data-wa-code="' + esc(displayCode(card.code, card.id)) + '"><i class="ti ti-flag-check"></i> Finalizar</button>'
                        : '')
                    + '</div></section>';
            }).join('')
            : '<section class="tralla-detail-section tralla-wa-empty">'
                + '<i class="ti ti-layout-kanban" aria-hidden="true"></i>'
                + '<strong>Nenhum card aberto para este contato.</strong>'
                + '<span>Crie um card para registrar este atendimento no Tralla.</span></section>';

        show(head('ti-layout-kanban', 'Cards do contato', (settings.contactName || '') + ' · Tralla')
            + '<div class="tralla-modal-body">' + body + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-wa-close>Fechar</button>'
            + '<button type="button" class="tralla-btn tralla-btn-primary" data-wa-create>'
            + '<i class="ti ti-plus"></i> Criar card</button></div>');
    }

    /* ------------------------------------------------------- novo card */

    async function showCreate() {
        if (!options) {
            showLoading('Novo card');
            var result = await get('options');
            if (!result || !result.success) {
                toast((result && result.error) || 'Não foi possível carregar o Tralla.', 'error');
                showList();
                return;
            }
            options = result.data;
        }
        var board = options.board || {};
        var categoryOptions = Object.keys(options.call_categories || {}).map(function (name) {
            return '<option value="' + esc(name) + '">' + esc(name) + '</option>';
        }).join('');
        var priorityOptions = (options.priorities || []).map(function (item) {
            return '<label class="tralla-priority-option is-' + esc(String(item.key).toLowerCase()) + '">'
                + '<input type="radio" name="priority" value="' + esc(item.key) + '">'
                + '<span class="tralla-priority-tag">' + esc(item.key) + '</span>'
                + '<span class="tralla-priority-text"><strong>' + esc(item.name) + '</strong>'
                + '<small>1º atendimento: ' + esc(item.first) + ' · Paliativa: ' + esc(item.palliative)
                + ' · Definitiva: ' + esc(item.definitive) + '</small></span></label>';
        }).join('');

        show('<form id="tralla-create-card-form" class="tralla-form" novalidate>'
            + head('ti-plus', 'Novo card', board.name + ' · ' + board.in_progress_list + ' · Aberto pelo WhatsApp')
            + '<div class="tralla-modal-body">'
            + '<section class="tralla-create-basics">'
            + '<h3><i class="ti ti-list-details"></i> Dados do atendimento</h3>'
            + '<div class="tralla-field tralla-combobox-field">'
            + '<label for="tralla-create-name">Nome do card *</label>'
            + '<div class="tralla-combobox">'
            + '<input id="tralla-create-name" type="text" name="name" maxlength="255" required autocomplete="off"'
            + ' placeholder="Busque e selecione uma entidade..." role="combobox" aria-autocomplete="list"'
            + ' aria-expanded="false" aria-controls="tralla-create-name-list" aria-haspopup="listbox">'
            + '<input id="tralla-create-name-entity-id" type="hidden" name="entity_id" value="">'
            + '<div id="tralla-create-name-list" class="tralla-combobox-list" role="listbox" hidden></div>'
            + '</div><small>Digite para buscar e clique em uma entidade da lista.</small></div>'
            + '<div class="tralla-form-row">'
            + '<div class="tralla-field"><label for="tralla-create-category">Categoria *</label>'
            + '<select id="tralla-create-category" name="call_category">'
            + '<option value="">Selecione uma categoria...</option>' + categoryOptions + '</select></div>'
            + '<div class="tralla-field"><label for="tralla-create-subcategory">Subcategoria *</label>'
            + '<select id="tralla-create-subcategory" name="call_subcategory" disabled>'
            + '<option value="">Selecione a categoria primeiro...</option></select></div></div>'
            + '</section>'
            + '<section class="tralla-detail-section tralla-create-call-description">'
            + '<div class="tralla-field"><label for="tralla-create-description">Descrição da situação *</label>'
            + '<textarea id="tralla-create-description" name="description" rows="6" maxlength="2000"'
            + ' placeholder="Descreva o que aconteceu, o impacto e as informações necessárias para o atendimento..."></textarea>'
            + '<small>A conversa do WhatsApp fica vinculada ao card e é atualizada sempre que ele é aberto.</small></div></section>'
            + '<section class="tralla-detail-section">'
            + '<h3><i class="ti ti-flag"></i> Prioridade do atendimento *</h3>'
            + '<div class="tralla-priority-options" role="radiogroup" aria-label="Prioridade">' + priorityOptions + '</div>'
            + '</section>'
            + '<p class="tralla-create-approval-note">'
            + '<i class="ti ti-info-circle" aria-hidden="true"></i>'
            + '<span>O chamado será criado direto em <b>' + esc(board.in_progress_list) + '</b>, com você como técnico responsável. '
            + 'O SLA da prioridade escolhida começa a contar agora.</span></p>'
            + '</div>'
            + '<div class="tralla-modal-actions">'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-wa-back><i class="ti ti-arrow-left"></i> Voltar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-check"></i> Criar card</button>'
            + '</div></form>');

        setupEntityCombobox();
        window.setTimeout(function () {
            root.querySelector('#tralla-create-name').focus();
        }, 20);
    }

    async function submitCreate(form) {
        var nameEl = form.querySelector('[name="name"]');
        var entityId = form.querySelector('[name="entity_id"]').value;
        if (entityId === '') {
            toast('Selecione uma entidade da lista para o nome do card.', 'error');
            nameEl.classList.add('is-entity-invalid');
            nameEl.focus();
            return;
        }
        var category = form.querySelector('[name="call_category"]').value;
        var subcategory = form.querySelector('[name="call_subcategory"]').value;
        var description = form.querySelector('[name="description"]').value.trim();
        if (((options.call_categories || {})[category] || []).indexOf(subcategory) === -1) {
            toast('Selecione uma categoria e subcategoria.', 'error');
            return;
        }
        if (!description) {
            toast('Descreva a situação do chamado.', 'error');
            form.querySelector('[name="description"]').focus();
            return;
        }
        var priority = form.querySelector('[name="priority"]:checked');
        if (!priority) {
            toast('Selecione a prioridade do atendimento.', 'error');
            return;
        }

        var submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            var result = await post('create', {
                entity_id: entityId,
                priority: priority.value,
                description: description,
                call_category: category,
                call_subcategory: subcategory
            });
            if (!result || !result.success) {
                toast((result && result.error) || 'Não foi possível criar o card.', 'error');
                return;
            }
            toast('Chamado ' + result.card.code + ' criado em ' + ((options.board || {}).in_progress_list || 'Em Andamento') + '.', 'success');
            changed();
            showList();
        } finally {
            submit.disabled = false;
        }
    }

    /* ---------------------------------------------- combobox de entidades */

    function normalize(value) {
        return String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    function renderEntityOptions(input, list) {
        var terms = normalize(input.value).split(/\s+/).filter(Boolean);
        var matches = (options.entities || []).filter(function (entity) {
            var haystack = normalize((entity.name || '') + ' ' + (entity.completename || ''));
            return terms.every(function (term) { return haystack.indexOf(term) !== -1; });
        }).slice(0, 60);
        list.innerHTML = matches.length
            ? matches.map(function (entity, index) {
                return '<button type="button" class="tralla-combobox-option" role="option"'
                    + ' id="' + list.id + '-entity-' + index + '" aria-selected="false"'
                    + ' data-entity-name="' + esc(entity.name) + '" data-entity-id="' + Number(entity.id) + '">'
                    + '<span>' + esc(entity.name) + '</span></button>';
            }).join('')
            : '<div class="tralla-combobox-empty">Nenhuma entidade encontrada.</div>';
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function closeEntityOptions(input, list) {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    function selectEntity(input, list, option) {
        input.value = option.dataset.entityName || '';
        input.classList.remove('is-entity-invalid');
        input.classList.add('is-entity-selected');
        root.querySelector('#tralla-create-name-entity-id').value = option.dataset.entityId || '';
        closeEntityOptions(input, list);
    }

    function setupEntityCombobox() {
        var input = root.querySelector('#tralla-create-name');
        var list = root.querySelector('#tralla-create-name-list');
        var hidden = root.querySelector('#tralla-create-name-entity-id');
        input.addEventListener('click', function () { renderEntityOptions(input, list); });
        input.addEventListener('input', function () {
            input.classList.remove('is-entity-selected', 'is-entity-invalid');
            hidden.value = '';
            renderEntityOptions(input, list);
        });
        input.addEventListener('keydown', function (event) {
            var choices = Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
            var active = list.querySelector('.is-active');
            var index = choices.indexOf(active);
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && choices.length) {
                event.preventDefault();
                index = event.key === 'ArrowDown'
                    ? (index + 1) % choices.length
                    : (index <= 0 ? choices.length - 1 : index - 1);
                choices.forEach(function (option) { option.classList.remove('is-active'); });
                choices[index].classList.add('is-active');
                choices[index].scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter' && !list.hidden && active) {
                event.preventDefault();
                selectEntity(input, list, active);
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                event.stopPropagation();
                closeEntityOptions(input, list);
            }
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () { closeEntityOptions(input, list); }, 120);
        });
        list.addEventListener('mousedown', function (event) { event.preventDefault(); });
        list.addEventListener('click', function (event) {
            var option = event.target.closest('[role="option"]');
            if (option) {
                selectEntity(input, list, option);
            }
        });
    }

    /* ------------------------------------------------------------- card */

    function slaStateLabel(state) {
        return {
            waiting: 'Aguardando liberação',
            running: 'No prazo',
            overdue: 'Atrasado',
            met: 'Cumprido',
            late_met: 'Cumprido com atraso'
        }[state] || '';
    }

    function slaSecondsLabel(totalSeconds) {
        var seconds = Math.max(0, Math.floor(totalSeconds));
        var days = Math.floor(seconds / 86400);
        var hours = Math.floor(seconds % 86400 / 3600);
        var minutes = Math.floor(seconds % 3600 / 60);
        return (days ? days + 'd ' : '') + (days || hours ? String(hours).padStart(2, '0') + 'h ' : '')
            + String(minutes).padStart(2, '0') + 'min ' + String(seconds % 60).padStart(2, '0') + 's';
    }

    function updateSlaClocks() {
        root.querySelectorAll('[data-sla-clock]').forEach(function (clock) {
            var met = Date.parse(String(clock.dataset.slaMet || '').replace(' ', 'T'));
            var start = Date.parse(String(clock.dataset.slaStart || '').replace(' ', 'T'));
            var deadline = Date.parse(String(clock.dataset.slaDeadline || '').replace(' ', 'T'));
            if (!isNaN(met) && !isNaN(start)) {
                clock.textContent = 'Tempo até cumprir: ' + slaSecondsLabel((met - start) / 1000);
            } else if (!isNaN(deadline)) {
                var difference = (deadline - Date.now()) / 1000;
                clock.textContent = (difference < 0 ? 'Atrasado há ' : 'Faltam ') + slaSecondsLabel(Math.abs(difference));
            }
        });
    }

    function renderSla(card) {
        var sla = card.sla;
        if (!sla || !sla.priority) {
            return '';
        }
        var rows = (sla.steps || []).map(function (step) {
            var when = step.met_at
                ? 'Em ' + formatDate(step.met_at)
                : (step.deadline ? 'Até ' + formatDate(step.deadline) : step.span);
            return '<div class="tralla-sla-row is-' + esc(step.state) + '">'
                + '<div><strong>' + esc(step.title) + '</strong><small>Prazo: ' + esc(step.span) + '</small></div>'
                + '<div class="tralla-sla-when"><span>' + esc(when) + '</span>'
                + '<small data-sla-clock data-sla-start="' + esc(sla.start || '') + '" data-sla-deadline="'
                + esc(step.deadline || '') + '" data-sla-met="' + esc(step.met_at || '') + '"></small>'
                + '<em>' + esc(slaStateLabel(step.state)) + '</em></div></div>';
        }).join('');
        return '<div class="tralla-card tralla-sla-card"><h3>Prioridade e SLA</h3>'
            + '<div class="tralla-sla-priority is-' + esc(String(sla.priority).toLowerCase()) + '">' + esc(sla.label) + '</div>'
            + (sla.start ? '<small class="tralla-sla-start">Contando desde ' + esc(formatDate(sla.start)) + '</small>' : '')
            + '<div class="tralla-sla-rows">' + rows + '</div></div>';
    }

    function metaRow(label, value) {
        return value
            ? '<div class="tralla-meta-row"><span>' + esc(label) + '</span><span>' + esc(value) + '</span></div>'
            : '';
    }

    function textBlock(value, empty) {
        return '<div class="tralla-detail-description">'
            + (value ? esc(value) : '<span class="tralla-empty-mini">' + esc(empty) + '</span>') + '</div>';
    }

    async function showCard(cardId) {
        showLoading('Card');
        var result = await get('card', { card_id: cardId });
        if (!result || !result.success) {
            toast((result && result.error) || 'Não foi possível abrir o card.', 'error');
            showList();
            return;
        }
        var card = result.card;
        var wa = card.wa || {};
        activeCard = card;

        var sub = statusPill(card.status, card.status_label || '')
            + (card.source_label ? '<span>' + esc(card.source_label) + '</span>' : '')
            + (card.technician_name ? '<span>' + esc(card.technician_name) + '</span>' : '');
        var header = '<div class="tralla-modal-head"><div>'
            + '<div class="tralla-detail-code">' + esc(displayCode(card.code, card.id)) + ' · ' + esc(card.list_name || '') + '</div>'
            + (card.workflow_type === 'call' ? '<span class="tralla-card-call-badge">Chamado</span>' : '')
            + '<h2 class="tralla-detail-title">' + esc(card.name) + '</h2>'
            + '<div class="tralla-detail-sub">' + sub + '</div>'
            + '</div><button type="button" class="tralla-icon-btn" data-wa-close aria-label="Fechar">'
            + '<i class="ti ti-x"></i></button></div>';

        var main = '<section class="tralla-detail-section">'
            + '<h3><i class="ti ti-file-text"></i> Descrição</h3>'
            + (wa.edit
                ? '<div class="tralla-field"><textarea id="tralla-wa-description" rows="4" maxlength="2000">'
                    + esc(card.description || '') + '</textarea></div>'
                : textBlock(card.description, 'Sem descrição.'))
            + '</section>'
            + '<section class="tralla-detail-section tralla-call-report">'
            + '<h3><i class="ti ti-file-report"></i> Relatório do atendimento</h3>'
            + (wa.edit
                ? '<div class="tralla-field"><label for="tralla-wa-report">Descreva o atendimento realizado</label>'
                    + '<textarea id="tralla-wa-report" rows="7" maxlength="200000" placeholder="Registre o diagnóstico e a solução aplicada...">'
                    + esc(card.service_report || '') + '</textarea></div>'
                    + '<button type="button" class="tralla-btn tralla-btn-primary" data-wa-save>'
                    + '<i class="ti ti-device-floppy"></i> Salvar alterações</button>'
                : textBlock(card.service_report, 'Ainda não há relatório.'))
            + '</section>'
            + (card.whatsapp_transcript
                ? '<section class="tralla-detail-section">'
                    + '<h3><i class="ti ti-brand-whatsapp"></i> Conversa do WhatsApp'
                    + (Number(card.whatsapp_chats_id) > 0 ? ' · WA-' + String(Number(card.whatsapp_chats_id)).padStart(6, '0') : '')
                    + '</h3><div class="tralla-detail-description tralla-whatsapp-transcript">' + esc(card.whatsapp_transcript) + '</div>'
                    + '</section>'
                : '');

        var side = renderSla(card)
            + '<div class="tralla-card"><h3>Detalhes</h3><div class="tralla-meta-list">'
            + metaRow('Código', displayCode(card.code, card.id))
            + metaRow('Lista', card.list_name)
            + metaRow('Tipo', card.workflow_type === 'call' ? 'Chamado' : 'Manutenção')
            + metaRow('Categoria', card.call_category)
            + metaRow('Subcategoria', card.call_subcategory)
            + metaRow('Responsável', card.technician_name || 'Não atribuído')
            + metaRow('Criado em', formatDate(card.date_creation))
            + metaRow('Atualizado em', formatDate(card.date_mod))
            + '</div></div>'
            + '<div class="tralla-card"><h3>Ações</h3><div class="tralla-wa-side-actions">'
            + (wa.finalize
                ? '<button type="button" class="tralla-btn tralla-btn-primary" data-wa-finalize="' + Number(card.id)
                    + '" data-wa-code="' + esc(displayCode(card.code, card.id)) + '"><i class="ti ti-flag-check"></i> Finalizar card</button>'
                : '')
            + '<a class="tralla-btn tralla-btn-ghost" href="' + esc(card.url || '#') + '" target="_blank" rel="noopener noreferrer">'
            + '<i class="ti ti-external-link"></i> Abrir no Tralla</a>'
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-wa-back><i class="ti ti-arrow-left"></i> Voltar aos cards</button>'
            + '</div></div>';

        show(header + '<div class="tralla-modal-body"><div class="tralla-detail-grid">'
            + '<div class="tralla-detail-main">' + main + '</div>'
            + '<aside class="tralla-detail-side">' + side + '</aside>'
            + '</div></div>', true);
        updateSlaClocks();
        window.clearInterval(clockTimer);
        clockTimer = window.setInterval(updateSlaClocks, 1000);
    }

    /** Salva descrição e relatório se mudaram. Retorna false se falhou. */
    async function saveCard(showToast) {
        var description = root.querySelector('#tralla-wa-description');
        var report = root.querySelector('#tralla-wa-report');
        if (!activeCard || !description || !report) {
            return true;
        }
        if (description.value.trim() === String(activeCard.description || '').trim()
            && report.value.trim() === String(activeCard.service_report || '').trim()) {
            if (showToast) {
                toast('Nenhuma alteração para salvar.', 'success');
            }
            return true;
        }
        var result = await post('update', {
            card_id: activeCard.id,
            description: description.value,
            report: report.value
        });
        if (!result || !result.success) {
            toast((result && result.error) || 'Não foi possível salvar o card.', 'error');
            return false;
        }
        activeCard.description = result.card.description;
        activeCard.service_report = result.card.service_report;
        if (showToast) {
            toast('Card ' + displayCode(activeCard.code, activeCard.id) + ' atualizado no Tralla.', 'success');
        }
        return true;
    }

    async function finalizeCard(button) {
        var cardId = Number(button.dataset.waFinalize);
        var code = button.dataset.waCode || '';
        if (!window.confirm('Finalizar o card ' + code + ' no Tralla?\n\nO chamado segue para Aprovação, como no quadro.')) {
            return;
        }
        button.disabled = true;
        try {
            if (activeCard && Number(activeCard.id) === cardId && !(await saveCard(false))) {
                return;
            }
            var result = await post('finalize', { card_id: cardId });
            if (!result || !result.success) {
                toast((result && result.error) || 'Não foi possível finalizar o card.', 'error');
                return;
            }
            toast('Card ' + code + ' finalizado: ' + (result.card.status_label || 'enviado para aprovação') + '.', 'success');
            changed();
            showList();
        } finally {
            button.disabled = false;
        }
    }

    /* ----------------------------------------------------------- eventos */

    function onClick(event) {
        var target = event.target;
        if (target.closest('[data-wa-close]') || target.hasAttribute('data-wa-backdrop')) {
            close();
            return;
        }
        if (target.closest('[data-wa-back]')) {
            activeCard = null;
            window.clearInterval(clockTimer);
            showList();
            return;
        }
        if (target.closest('[data-wa-create]')) {
            showCreate();
            return;
        }
        var open = target.closest('[data-wa-open-card]');
        if (open) {
            showCard(Number(open.dataset.waOpenCard));
            return;
        }
        var finalize = target.closest('[data-wa-finalize]');
        if (finalize) {
            finalizeCard(finalize);
            return;
        }
        var save = target.closest('[data-wa-save]');
        if (save) {
            save.disabled = true;
            saveCard(true).then(function (ok) {
                save.disabled = false;
                if (ok) {
                    changed();
                }
            });
        }
    }

    function onChange(event) {
        var target = event.target;
        if (target.id === 'tralla-create-category') {
            var choices = (options.call_categories || {})[target.value] || [];
            var sub = root.querySelector('#tralla-create-subcategory');
            sub.innerHTML = '<option value="">Selecione uma subcategoria...</option>'
                + choices.map(function (value) {
                    return '<option value="' + esc(value) + '">' + esc(value) + '</option>';
                }).join('');
            sub.disabled = !choices.length;
        }
    }

    function onSubmit(event) {
        event.preventDefault();
        if (event.target.id === 'tralla-create-card-form') {
            submitCreate(event.target);
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root && root.innerHTML) {
            close();
        }
    });

    window.TrallaWhatsappCard = {
        open: function (config) {
            settings = config;
            options = null;
            ensureRoots();
            showList();
        }
    };
})();
