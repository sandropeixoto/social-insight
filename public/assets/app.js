(() => {
    const state = {
        groups: [],
        filteredGroups: [],
        selectedGroupId: null,
        selectedGroupName: null,
        refreshGroupsInterval: null,
        refreshMessagesInterval: null,
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

    elements.groupSearch.addEventListener('input', event => {
        const term = event.target.value.toLowerCase();
        state.filteredGroups = state.groups.filter(group => group.name.toLowerCase().includes(term));
        renderGroups();
    });

    async function loadGroups() {
        try {
            const response = await fetch(endpoints.groups, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`Erro ao carregar grupos: ${response.status}`);
            }

            const payload = await response.json();
            state.groups = payload.data ?? [];

            const searchTerm = elements.groupSearch.value.trim().toLowerCase();
            state.filteredGroups = searchTerm
                ? state.groups.filter(group => group.name.toLowerCase().includes(searchTerm))
                : [...state.groups];

            renderGroups();
        } catch (error) {
            console.error(error);
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
                throw new Error(`Erro ao carregar mensagens: ${response.status}`);
            }

            const payload = await response.json();
            state.selectedGroupName = payload.group?.name ?? state.selectedGroupName;

            renderHeader();
            renderMessages(payload.data ?? []);
        } catch (error) {
            console.error(error);
        }
    }

    function renderGroups() {
        const fragment = document.createDocumentFragment();

        if (!state.filteredGroups.length) {
            const empty = document.createElement('div');
            empty.className = 'empty-list';
            empty.textContent = 'Nenhum grupo localizado.';
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
            elements.messageList.innerHTML = `
                <div class="empty-state">
                    <p>Nenhuma mensagem registrada neste grupo até o momento.</p>
                </div>`;
            return;
        }

        const fragment = document.createDocumentFragment();

        messages.forEach(message => {
            const bubble = document.createElement('article');
            bubble.className = 'message ';
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

        renderGroups();
        renderHeader();

        if (state.refreshMessagesInterval) {
            clearInterval(state.refreshMessagesInterval);
        }

        loadMessages(groupId);
        state.refreshMessagesInterval = setInterval(() => loadMessages(groupId), 5000);
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
