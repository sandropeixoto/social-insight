(() => {
    const debugMode = Boolean(window.APP_CONFIG?.debug);

    const state = {
        groups: [],
        filteredGroups: [],
        selectedGroupId: null,
        selectedGroupName: null,
        refreshGroupsInterval: null,
        refreshMessagesInterval: null,
        refreshInstanceInterval: null,
        lastGroupsError: null,
        lastMessagesError: null,
        instanceStatus: {
            connected: null,
            lastUpdated: null,
            profile: null,
            qr: null,
            rawStatus: null,
            error: null,
            profileError: null,
            qrError: null,
        },
    };

    const elements = {
        groupList: document.getElementById('groupList'),
        groupSearch: document.getElementById('groupSearch'),
        messageList: document.getElementById('messageList'),
        chatHeader: document.getElementById('chatHeader'),
        instanceStatus: document.getElementById('instanceStatus'),
    };

    const endpoints = window.APP_CONFIG?.endpoints ?? {
        groups: 'api/groups.php',
        messages: 'api/messages.php',
        instance: 'api/instance.php',
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

    async function loadInstanceStatus() {
        if (!elements.instanceStatus || !endpoints.instance) {
            return;
        }

        try {
            const response = await fetch(endpoints.instance, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`Erro ao consultar instancia: ${response.status} ${response.statusText}`);
            }

            const payload = await response.json();

            state.instanceStatus.connected = Boolean(payload.connected);
            state.instanceStatus.lastUpdated = payload.timestamp ?? new Date().toISOString();
            state.instanceStatus.profile = payload.profile ?? null;
            state.instanceStatus.qr = payload.qr_code ?? null;
            state.instanceStatus.rawStatus = payload.status ?? null;
            state.instanceStatus.error = payload.error ?? null;
            state.instanceStatus.profileError = payload.profile_error ?? null;
            state.instanceStatus.qrError = payload.qr_error ?? null;

            renderInstanceStatus();
        } catch (error) {
            state.instanceStatus.error = normalizeErrorMessage(error);
            state.instanceStatus.connected = null;
            state.instanceStatus.profile = null;
            state.instanceStatus.qr = null;
            state.instanceStatus.profileError = null;
            state.instanceStatus.qrError = null;
            console.error('[Social Insight] Falha ao consultar status da instancia:', error);
            renderInstanceStatus();
        }
    }

    function renderInstanceStatus() {
        const container = elements.instanceStatus;

        if (!container) {
            return;
        }

        const status = state.instanceStatus;
        const wrapper = document.createElement('div');
        wrapper.className = 'instance-status';

        let icon = '..';
        let title = 'Verificando instancia...';
        let description = 'Sincronizando com W-API.';

        if (status.error) {
            icon = '!!';
            title = 'Falha ao consultar instancia';
            description = status.error;
            wrapper.classList.add('instance-status--error');
        } else if (status.connected === true) {
            icon = 'OK';
            title = 'Instancia conectada';
            description = 'Sessao ativa no WhatsApp.';
            wrapper.classList.add('instance-status--connected');
        } else if (status.connected === false) {
            icon = 'QR';
            title = 'Instancia aguardando pareamento';
            description = 'Escaneie o QR Code abaixo no WhatsApp.';
            wrapper.classList.add('instance-status--disconnected');
        } else {
            wrapper.classList.add('instance-status--loading');
        }

        const iconElement = document.createElement('span');
        iconElement.className = 'instance-status__icon';
        iconElement.textContent = icon;

        const content = document.createElement('div');
        content.className = 'instance-status__body';

        const titleElement = document.createElement('strong');
        titleElement.textContent = title;

        const descriptionElement = document.createElement('p');
        descriptionElement.textContent = description;

        content.append(titleElement, descriptionElement);

        if (status.connected === true && status.profile) {
            const meta = document.createElement('div');
            meta.className = 'instance-status__meta';

            if (status.profile.name) {
                const name = document.createElement('span');
                name.textContent = `Nome: ${status.profile.name}`;
                meta.appendChild(name);
            }

            if (status.profile.info?.phone) {
                const phone = document.createElement('span');
                phone.textContent = `Telefone: ${status.profile.info.phone}`;
                meta.appendChild(phone);
            }

            if (status.profile.wid) {
                const wid = document.createElement('span');
                wid.textContent = `WID: ${status.profile.wid}`;
                meta.appendChild(wid);
            }

            if (status.profile.is_business) {
                const business = document.createElement('span');
                business.textContent = 'Conta comercial';
                meta.appendChild(business);
            }

            content.appendChild(meta);

            if (status.profileError) {
                const profileError = document.createElement('p');
                profileError.textContent = `Aviso: ${status.profileError}`;
                content.appendChild(profileError);
            }
        }

        if (status.connected === false) {
            if (status.qr?.value) {
                const qr = document.createElement('div');
                qr.className = 'instance-status__qr';

                const img = document.createElement('img');
                img.alt = 'QR Code WhatsApp';
                img.src = status.qr.value;
                qr.appendChild(img);

                const qrCaption = document.createElement('p');
                qrCaption.textContent = 'Abra o WhatsApp > Aparelhos conectados > Conectar um aparelho.';
                qr.appendChild(qrCaption);

                content.appendChild(qr);
            } else if (status.qrError) {
                const qrError = document.createElement('p');
                qrError.textContent = `Nao foi possivel carregar o QR Code: ${status.qrError}`;
                content.appendChild(qrError);
            }
        }

        if (status.lastUpdated) {
            const lastUpdated = document.createElement('p');
            lastUpdated.className = 'instance-status__timestamp';
            const datetime = new Date(status.lastUpdated);
            const formatted = Number.isNaN(datetime.getTime())
                ? status.lastUpdated
                : datetime.toLocaleString('pt-BR', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
            lastUpdated.textContent = `Atualizado as ${formatted}`;
            content.appendChild(lastUpdated);
        }

        wrapper.append(iconElement, content);
        container.replaceChildren(wrapper);
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
                    <p>Escolha um grupo a esquerda para visualizar as mensagens.</p>
                </div>`;
            return;
        }

        elements.chatHeader.innerHTML = `
            <div>
                <h2>${state.selectedGroupName ?? 'Grupo'}</h2>
                <p>Monitoramento em tempo real - Atualize para sincronizar</p>
            </div>`;
    }

    function renderMessages(messages) {
        if (!messages.length) {
            const text = state.lastMessagesError
                ? `<strong>Erro ao carregar mensagens</strong><br>${state.lastMessagesError}`
                : 'Nenhuma mensagem registrada neste grupo ate o momento.';

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
            text.textContent = message.message_body || '[mensagem sem conteudo]';

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

        return value.length > max ? `${value.slice(0, max)}â€¦` : value;
    }

    loadGroups();
    renderHeader();
    renderInstanceStatus();
    loadInstanceStatus();

    state.refreshGroupsInterval = setInterval(loadGroups, 8000);
    state.refreshInstanceInterval = setInterval(loadInstanceStatus, 15000);
})();



