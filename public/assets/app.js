(() => {
    const debugMode = Boolean(window.APP_CONFIG?.debug);

    const state = {
        groups: [],
        filteredGroups: [],
        selectedGroupId: null,
        selectedGroupName: null,
        refreshGroupsInterval: null,
        refreshMessagesInterval: null,
        lastGroupsError: null,
        lastMessagesError: null,
    };

    const elements = {
        groupList: document.getElementById('groupList'),
        groupSearch: document.getElementById('groupSearch'),
        messageList: document.getElementById('messageList'),
        chatHeader: document.getElementById('chatHeader'),
    };

    const endpoints = window.APP_CONFIG?.endpoints ?? {
        groups: 'api/groups.php',
        messages: 'api/messages.php',
    };

    if (elements.groupSearch) {
        elements.groupSearch.addEventListener('input', event => {
            const term = event.target.value.toLowerCase();
            state.filteredGroups = state.groups.filter(group => group.name.toLowerCase().includes(term));
            state.lastGroupsError = null;
            renderGroups();
        });
    }

    if (debugMode) {
        attachDebugBadge();
        console.info('[Social Insight] Debug mode enabled.');
    }

    async function loadGroups() {
        try {
            const response = await fetch(endpoints.groups, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`Erro ao carregar grupos: ${response.status} ${response.statusText}`);
            }

            const payload = await response.json();
            state.groups = payload.data ?? [];
            state.lastGroupsError = null;

            const searchTerm = elements.groupSearch.value.trim().toLowerCase();
            state.filteredGroups = searchTerm
                ? state.groups.filter(group => group.name.toLowerCase().includes(searchTerm))
                : [...state.groups];

            renderGroups();
        } catch (error) {
            handleGroupsError(error);
        }
    }

    async function loadMessages(groupId) {
        if (!groupId) {
            return;
        }

        try {
            const url = new URL(endpoints.messages, window.location.origin);
            url.searchParams.set('group_id', groupId);

            const response = await fetch(url, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`Erro ao carregar mensagens: ${response.status} ${response.statusText}`);
            }

            const payload = await response.json();
            state.selectedGroupName = payload.group?.name ?? state.selectedGroupName;
            state.lastMessagesError = null;

            renderHeader();
            renderMessages(payload.data ?? []);
        } catch (error) {
            handleMessagesError(error);
        }
    }

    function renderGroups() {
        const fragment = document.createDocumentFragment();

        if (!state.filteredGroups.length) {
            const message = state.lastGroupsError
                ? `<strong>Erro ao carregar grupos</strong><br>${state.lastGroupsError}`
                : 'Nenhum grupo localizado.';

            const empty = document.createElement('div');
            empty.className = state.lastGroupsError ? 'status status--error' : 'empty-list';
            empty.innerHTML = message;
            elements.groupList.replaceChildren(empty);
            return;
        }

        state.filteredGroups.forEach(group => {
            const item = document.createElement('article');
            item.className = 'group-item';
            if (group.id === state.selectedGroupId) {
                item.classList.add('is-active');
            }

            item.tabIndex = 0;
            item.role = 'option';
            item.dataset.groupId = group.id;

            item.addEventListener('click', () => selectGroup(group.id));
            item.addEventListener('keypress', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectGroup(group.id);
                }
            });

            const avatar = document.createElement('div');
            avatar.className = 'group-item__avatar';
            avatar.textContent = group.name.slice(0, 2).toUpperCase();

            const details = document.createElement('div');
            details.className = 'group-item__details';

            const name = document.createElement('span');
            name.className = 'group-item__name';
            name.textContent = group.name;

            const meta = document.createElement('span');
            meta.className = 'group-item__meta';
            meta.textContent = formatTime(group.last_message_sent_at);

            const preview = document.createElement('span');
            preview.className = 'group-item__preview';
            preview.textContent = group.last_message_body ? truncate(group.last_message_body, 70) : 'Sem mensagens registradas.';

            const count = document.createElement('span');
            count.className = 'group-item__count';
            count.textContent = group.message_count;

            details.appendChild(name);
            details.appendChild(meta);
            details.appendChild(preview);
            details.appendChild(count);

            item.appendChild(avatar);
            item.appendChild(details);

            fragment.appendChild(item);
        });

        elements.groupList.replaceChildren(fragment);
    }

    function renderHeader() {
        if (!state.selectedGroupId) {
            elements.chatHeader.innerHTML = `
                <div class="placeholder">
                    <h2>Selecione um grupo</h2>
                    <p>Escolha um grupo à esquerda para visualizar as mensagens.</p>
                </div>`;
            return;
        }

        elements.chatHeader.innerHTML = `
            <div>
                <h2>${state.selectedGroupName ?? 'Grupo'}</h2>
                <p>Monitoramento em tempo real · Atualize para sincronizar</p>
            </div>`;
    }

    function renderMessages(messages) {
        if (!messages.length) {
            const text = state.lastMessagesError
                ? `<strong>Erro ao carregar mensagens</strong><br>${state.lastMessagesError}`
                : 'Nenhuma mensagem registrada neste grupo até o momento.';

            elements.messageList.innerHTML = `
                <div class="empty-state">
                    <p>${text}</p>
                </div>`;
            return;
        }

        const fragment = document.createDocumentFragment();

        messages.forEach(message => {
            const bubble = document.createElement('article');
            bubble.className = 'message';
            bubble.classList.add(message.is_from_me ? 'is-outbound' : 'is-inbound');

            if (message.message_type && message.message_type !== 'text') {
                bubble.classList.add('is-attachment');
            }

            const author = document.createElement('span');
            author.className = 'message__author';
            author.textContent = message.sender_name ?? 'Contato';

            const text = document.createElement('div');
            text.className = 'message__body';
            text.textContent = message.message_body || '[mensagem sem conteúdo]';

            const meta = document.createElement('span');
            meta.className = 'message__meta';
            meta.textContent = formatTime(message.sent_at);

            bubble.appendChild(author);
            bubble.appendChild(text);
            bubble.appendChild(meta);

            fragment.appendChild(bubble);
        });

        elements.messageList.replaceChildren(fragment);
        elements.messageList.scrollTop = elements.messageList.scrollHeight;
    }

    function selectGroup(groupId) {
        if (state.selectedGroupId === groupId) {
            return;
        }

        state.selectedGroupId = groupId;
        state.selectedGroupName = state.groups.find(group => group.id === groupId)?.name ?? null;
        state.lastMessagesError = null;

        renderGroups();
        renderHeader();

        if (state.refreshMessagesInterval) {
            clearInterval(state.refreshMessagesInterval);
        }

        loadMessages(groupId);
        state.refreshMessagesInterval = setInterval(() => loadMessages(groupId), 5000);
    }

    function handleGroupsError(error) {
        const message = normalizeErrorMessage(error);
        state.lastGroupsError = message;
        console.error('[Social Insight] Falha ao carregar grupos:', error);
        renderGroups();
    }

    function handleMessagesError(error) {
        state.lastMessagesError = normalizeErrorMessage(error);
        console.error('[Social Insight] Falha ao carregar mensagens:', error);
        renderMessages([]);
    }

    function normalizeErrorMessage(error) {
        if (!error) {
            return 'Erro inesperado.';
        }

        if (typeof error === 'string') {
            return exposeIfDebug(error);
        }

        if (error instanceof Error) {
            return exposeIfDebug(error.message);
        }

        try {
            return exposeIfDebug(JSON.stringify(error));
        } catch {
            return 'Erro desconhecido.';
        }
    }

    function exposeIfDebug(message) {
        if (!debugMode) {
            return 'Verifique o console do navegador para mais detalhes.';
        }

        return message;
    }

    function attachDebugBadge() {
        const badge = document.createElement('div');
        badge.className = 'debug-indicator';
        badge.textContent = 'DEBUG ATIVO';
        document.body.appendChild(badge);
    }

    function formatTime(isoString) {
        if (!isoString) {
            return '';
        }

        const date = new Date(isoString);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
            day: '2-digit',
            month: '2-digit',
        });
    }

    function truncate(value, max) {
        if (!value) {
            return '';
        }

        return value.length > max ? `${value.slice(0, max)}…` : value;
    }

    loadGroups();
    renderHeader();

    state.refreshGroupsInterval = setInterval(loadGroups, 8000);
})();
