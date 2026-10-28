/**
 * [URE] Tralla — modal "Novo card" aberto pelo WhatsAppSimples.
 *
 * Mesmo modal do Kanban, restrito a Chamado (entidade, categoria e
 * descrição), com a prioridade escolhida por quem abre. O card é criado
 * direto em Manutenção Em Andamento; o envio é feito pelo WhatsAppSimples,
 * que vincula a conversa do atendimento.
 *
 * Uso: TrallaWhatsappCard.open({ chatId, request, optionsUrl, submitUrl, onCreated })
 *   request(url, formData?) -> Promise<json> (o WhatsAppSimples cuida do CSRF)
 */
(function () {
    'use strict';

    var escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    var root = null;
    var toastRoot = null;
    var data = null;
    var settings = null;

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (character) {
                return escapeMap[character];
            });
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

    function show(html) {
        root.innerHTML = '<div class="tralla-modal-backdrop" data-wa-backdrop>'
            + '<div class="tralla-modal" role="dialog" aria-modal="true">' + html + '</div></div>';
        document.body.classList.add('tralla-modal-open');
    }

    function close() {
        if (root) {
            root.innerHTML = '';
        }
        document.body.classList.remove('tralla-modal-open');
    }

    /* ------------------------------------------------ combobox de entidades */

    function normalize(value) {
        return String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    function renderEntityOptions(input, list) {
        var terms = normalize(input.value).split(/\s+/).filter(Boolean);
        var matches = (data.entities || []).filter(function (entity) {
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
            var options = Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
            var active = list.querySelector('.is-active');
            var index = options.indexOf(active);
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && options.length) {
                event.preventDefault();
                index = event.key === 'ArrowDown'
                    ? (index + 1) % options.length
                    : (index <= 0 ? options.length - 1 : index - 1);
                options.forEach(function (option) { option.classList.remove('is-active'); });
                options[index].classList.add('is-active');
                options[index].scrollIntoView({ block: 'nearest' });
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

    /* ------------------------------------------------------------ modal */

    function render() {
        var board = data.board || {};
        var categoryOptions = Object.keys(data.call_categories || {}).map(function (name) {
            return '<option value="' + esc(name) + '">' + esc(name) + '</option>';
        }).join('');
        var priorityOptions = (data.priorities || []).map(function (item) {
            return '<label class="tralla-priority-option is-' + esc(String(item.key).toLowerCase()) + '">'
                + '<input type="radio" name="priority" value="' + esc(item.key) + '">'
                + '<span class="tralla-priority-tag">' + esc(item.key) + '</span>'
                + '<span class="tralla-priority-text"><strong>' + esc(item.name) + '</strong>'
                + '<small>1º atendimento: ' + esc(item.first) + ' · Paliativa: ' + esc(item.palliative)
                + ' · Definitiva: ' + esc(item.definitive) + '</small></span></label>';
        }).join('');

        show('<form id="tralla-create-card-form" class="tralla-form" novalidate>'
            + '<div class="tralla-modal-head"><div>'
            + '<h2><i class="ti ti-plus"></i> Novo card</h2>'
            + '<p>' + esc(board.name) + ' · ' + esc(board.in_progress_list) + ' · Aberto pelo WhatsApp</p>'
            + '</div><button type="button" class="tralla-icon-btn" data-wa-close aria-label="Fechar">'
            + '<i class="ti ti-x"></i></button></div>'
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
            + '<button type="button" class="tralla-btn tralla-btn-ghost" data-wa-close>Cancelar</button>'
            + '<button type="submit" class="tralla-btn tralla-btn-primary">'
            + '<i class="ti ti-check"></i> Criar card</button>'
            + '</div></form>');

        setupEntityCombobox();
        window.setTimeout(function () {
            root.querySelector('#tralla-create-name').focus();
        }, 20);
    }

    function onClick(event) {
        if (event.target.closest('[data-wa-close]') || event.target.hasAttribute('data-wa-backdrop')) {
            close();
        }
    }

    function onChange(event) {
        var target = event.target;
        if (target.id === 'tralla-create-category') {
            var choices = (data.call_categories || {})[target.value] || [];
            var sub = root.querySelector('#tralla-create-subcategory');
            sub.innerHTML = '<option value="">Selecione uma subcategoria...</option>'
                + choices.map(function (value) {
                    return '<option value="' + esc(value) + '">' + esc(value) + '</option>';
                }).join('');
            sub.disabled = !choices.length;
        }
    }

    async function onSubmit(event) {
        event.preventDefault();
        var form = event.target;
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
        if (((data.call_categories || {})[category] || []).indexOf(subcategory) === -1) {
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

        var body = new FormData();
        body.append('chat_id', settings.chatId);
        body.append('entity_id', entityId);
        body.append('priority', priority.value);
        body.append('description', description);
        body.append('call_category', category);
        body.append('call_subcategory', subcategory);

        var submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            var result = await settings.request(settings.submitUrl, body);
            if (!result || !result.success) {
                toast((result && result.error) || 'Não foi possível criar o card.', 'error');
                return;
            }
            close();
            toast('Chamado ' + result.card.code + ' criado em ' + (data.board.in_progress_list || 'Em Andamento') + '.', 'success');
            if (typeof settings.onCreated === 'function') {
                settings.onCreated(result.card);
            }
        } finally {
            submit.disabled = false;
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root && root.innerHTML) {
            close();
        }
    });

    window.TrallaWhatsappCard = {
        open: async function (options) {
            settings = options;
            ensureRoots();
            var result = await settings.request(settings.optionsUrl);
            if (!result || !result.success) {
                toast((result && result.error) || 'Não foi possível carregar o Tralla.', 'error');
                return;
            }
            data = result.data;
            render();
        }
    };
})();
