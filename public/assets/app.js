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
        lastInstanceAction: null,
        instanceActionTimeout: null,
        isSyncingChats: false,
        isDisconnecting: false,
        instanceCollapsed: false,
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
        instanceContainer: document.querySelector('.sidebar__instance'),
        instanceStatus: document.getElementById('instanceStatus'),
        instanceToggle: document.getElementById('instanceToggle'),
    };

    const endpoints = window.APP_CONFIG?.endpoints ?? {
        groups: 'api/groups.php',
        messages: 'api/messages.php',
        instance: 'api/instance.php',
    };

    if (elements.groupSearch) {
        elements.groupSearch.addEventListener('input', event => {
            const term = event.target.value.toLowerCase();
            state.filteredGroups = term
                ? state.groups.filter(group => matchesSearchTerm(group, term))
                : [...state.groups];
            state.lastGroupsError = null;
            renderGroups();
        });
    }

    if (elements.instanceToggle && elements.instanceContainer) {
        elements.instanceToggle.addEventListener('click', () => {
            state.instanceCollapsed = !state.instanceCollapsed;
            updateInstancePanel();
        });

        updateInstancePanel();
    }

    if (debugMode) {
        attachDebugBadge();
        console.info('[Social Insight] Debug mode enabled.');
    }

    async function loadGroups(forceRefresh = false) {
        try {
            const url = new URL(endpoints.groups, window.location.href);

            if (forceRefresh) {
                url.searchParams.set('refresh', '1');
                url.searchParams.set('_ts', Date.now().toString());
            }

            const response = await fetch(url, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`Erro ao carregar grupos: ${response.status} ${response.statusText}`);
            }

            const payload = await response.json();
            state.groups = payload.data ?? [];
            state.lastGroupsError = payload.meta?.sync?.error ?? null;

            if (debugMode && payload.meta?.sync) {
                console.info('[Social Insight] Sync metadata:', payload.meta.sync);
            }

            const searchTerm = elements.groupSearch.value.trim().toLowerCase();
            state.filteredGroups = searchTerm
                ? state.groups.filter(group => matchesSearchTerm(group, searchTerm))
                : [...state.groups];

            renderGroups();
            return true;
        } catch (error) {
            handleGroupsError(error);
            return false;
        }
    }

    async function loadMessages(groupId) {
        if (!groupId) {
            return;
        }

        try {
            const url = new URL(endpoints.messages, window.location.href);
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

    async function forceSyncChats() {
        if (state.isSyncingChats) {
            return;
        }

        state.isSyncingChats = true;
        setInstanceAction();
        renderInstanceStatus();

        const result = await loadGroups(true);

        if (result && !state.lastGroupsError) {
            if (state.groups.length === 0) {
                setInstanceAction('info', 'Nenhuma conversa foi retornada pela API.');
            } else {
                setInstanceAction('success', 'Conversas sincronizadas com sucesso.');
            }
        } else if (state.lastGroupsError) {
            setInstanceAction('error', state.lastGroupsError);
        } else if (!result) {
            setInstanceAction('error', 'Não foi possível atualizar as conversas.');
        }

        state.isSyncingChats = false;
        renderInstanceStatus();
    }

    async function disconnectInstance() {
        if (state.isDisconnecting) {
            return;
        }

        state.isDisconnecting = true;
        setInstanceAction();
        renderInstanceStatus();

        try {
            const response = await fetch(endpoints.instance, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: 'disconnect' }),
            });

            let payload = {};
            try {
                payload = await response.json();
            } catch {
                payload = {};
            }

            if (!response.ok || (payload && payload.error)) {
                const message = typeof payload?.error === 'string'
                    ? payload.error
                    : payload?.message ?? payload?.details ?? `Erro ao desconectar: ${response.status} ${response.statusText}`;
                throw new Error(message);
            }

            setInstanceAction('success', 'Instância desconectada. Escaneie o QR Code para reconectar.');
            await loadInstanceStatus();
        } catch (error) {
            console.error('[Social Insight] Falha ao desconectar a instancia:', error);
            setInstanceAction('error', extractActionError(error));
        } finally {
            state.isDisconnecting = false;
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
            const identity = document.createElement('div');
            identity.className = 'instance-status__identity';

            const avatar = document.createElement('div');
            avatar.className = 'instance-status__avatar';

            if (status.profile.profile_picture_url) {
                const img = document.createElement('img');
                img.src = status.profile.profile_picture_url;
                img.alt = status.profile.name ? `Foto de ${status.profile.name}` : 'Foto do perfil conectado';
                avatar.appendChild(img);
            } else {
                avatar.classList.add('instance-status__avatar--fallback');
                const initialsSource = status.profile.name
                    ?? status.profile.info?.phone
                    ?? status.profile.wid
                    ?? 'WA';
                avatar.textContent = initialsSource.slice(0, 2).toUpperCase();
            }

            const identityInfo = document.createElement('div');
            identityInfo.className = 'instance-status__identity-info';

            const name = document.createElement('span');
            name.className = 'instance-status__identity-name';
            name.textContent = status.profile.name ?? 'Perfil sem nome';
            identityInfo.appendChild(name);

            if (status.profile.info?.phone) {
                const phone = document.createElement('span');
                phone.className = 'instance-status__identity-phone';
                phone.textContent = status.profile.info.phone;
                identityInfo.appendChild(phone);
            }

            if (status.profile.info?.platform) {
                const platform = document.createElement('span');
                platform.className = 'instance-status__identity-platform';
                platform.textContent = `Dispositivo: ${status.profile.info.platform}`;
                identityInfo.appendChild(platform);
            }

            identity.append(avatar, identityInfo);
            content.appendChild(identity);

            const meta = document.createElement('div');
            meta.className = 'instance-status__meta';

            if (status.profile.wid) {
                const wid = document.createElement('span');
                wid.textContent = `WID: ${status.profile.wid}`;
                meta.appendChild(wid);
            }

            if (status.profile.info?.lid) {
                const lid = document.createElement('span');
                lid.textContent = `LID: ${status.profile.info.lid}`;
                meta.appendChild(lid);
            }

            if (status.profile.is_business) {
                const business = document.createElement('span');
                business.textContent = 'Conta comercial';
                meta.appendChild(business);
            }

            if (status.profile.info?.about) {
                const about = document.createElement('span');
                about.textContent = `Recado: ${status.profile.info.about}`;
                meta.appendChild(about);
            }

            if (meta.childElementCount > 0) {
                content.appendChild(meta);
            }

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

        const actions = document.createElement('div');
        actions.className = 'instance-status__actions';

        const refreshButton = document.createElement('button');
        refreshButton.type = 'button';
        refreshButton.className = 'instance-status__action';
        refreshButton.textContent = state.isSyncingChats ? 'Sincronizando...' : 'Atualizar conversas';
        refreshButton.disabled = state.isSyncingChats;
        refreshButton.addEventListener('click', () => forceSyncChats());
        actions.appendChild(refreshButton);

        if (status.connected === true) {
            const disconnectButton = document.createElement('button');
            disconnectButton.type = 'button';
            disconnectButton.className = 'instance-status__action instance-status__action--danger';
            disconnectButton.textContent = state.isDisconnecting ? 'Desconectando...' : 'Desconectar';
            disconnectButton.disabled = state.isDisconnecting;
            disconnectButton.addEventListener('click', () => disconnectInstance());
            actions.appendChild(disconnectButton);
        }

        content.appendChild(actions);

        if (state.lastInstanceAction?.message) {
            const note = document.createElement('p');
            note.className = 'instance-status__note';

            if (state.lastInstanceAction.type === 'error') {
                note.classList.add('instance-status__note--error');
            } else if (state.lastInstanceAction.type === 'success') {
                note.classList.add('instance-status__note--success');
            } else {
                note.classList.add('instance-status__note--info');
            }

            note.textContent = state.lastInstanceAction.message;
            content.appendChild(note);
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
        updateInstancePanel();
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
            const isGroup = group.is_group ?? (group.type === 'group');
            item.classList.add(isGroup ? 'group-item--group' : 'group-item--contact');

            if (group.id === state.selectedGroupId) {
                item.classList.add('is-active');
            }

            item.tabIndex = 0;
            item.role = 'option';
            item.dataset.groupId = group.id;
            item.setAttribute('aria-label', `${normalizeGroupName(group, true)} (${isGroup ? 'Grupo' : 'Contato'})`);

            item.addEventListener('click', () => selectGroup(group.id));
            item.addEventListener('keypress', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectGroup(group.id);
                }
            });

            const avatar = document.createElement('div');
            avatar.className = 'group-item__avatar';
            avatar.classList.add(isGroup ? 'group-item__avatar--group' : 'group-item__avatar--contact');
            avatar.textContent = buildInitials(normalizeGroupName(group, true));

            const details = document.createElement('div');
            details.className = 'group-item__details';

            const name = document.createElement('span');
            name.className = 'group-item__name';
            name.textContent = normalizeGroupName(group, true);

            const meta = document.createElement('div');
            meta.className = 'group-item__meta';

            const metaTime = document.createElement('span');
            metaTime.className = 'group-item__time';
            metaTime.textContent = formatTime(group.last_message_sent_at);

            const typeTag = document.createElement('span');
            typeTag.className = 'group-item__tag';
            typeTag.textContent = isGroup ? 'Grupo' : 'Contato';

            meta.append(metaTime, typeTag);

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

            const hasMedia = Boolean(message.media && message.media.url);

            if (message.message_type && message.message_type !== 'text') {
                bubble.classList.add('is-attachment');
            }

            const author = document.createElement('span');
            author.className = 'message__author';
            author.textContent = message.sender_name ?? 'Contato';
            bubble.appendChild(author);

            if (hasMedia) {
                const mediaElement = createMediaElement(message);
                if (mediaElement) {
                    bubble.classList.add('message--has-media');
                    bubble.appendChild(mediaElement);
                }
            }

            const bodyText = (message.message_body || '').trim();

            if (bodyText !== '') {
                const text = document.createElement('div');
                text.className = 'message__body';
                text.textContent = bodyText;
                bubble.appendChild(text);
            } else if (!hasMedia) {
                const placeholder = document.createElement('div');
                placeholder.className = 'message__body message__body--muted';
                placeholder.textContent = '[mensagem sem conteúdo]';
                bubble.appendChild(placeholder);
            }

            const meta = document.createElement('span');
            meta.className = 'message__meta';
            meta.textContent = formatTime(message.sent_at);
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

    function extractActionError(error) {
        if (error instanceof Error && error.message) {
            return error.message;
        }

        if (typeof error === 'string') {
            return error;
        }

        return normalizeErrorMessage(error);
    }

    function setInstanceAction(type, message) {
        if (state.instanceActionTimeout) {
            clearTimeout(state.instanceActionTimeout);
            state.instanceActionTimeout = null;
        }

        if (!type || !message) {
            state.lastInstanceAction = null;
            return;
        }

        state.lastInstanceAction = { type, message };
        state.instanceActionTimeout = setTimeout(() => {
            state.lastInstanceAction = null;
            state.instanceActionTimeout = null;
            renderInstanceStatus();
        }, 6000);
    }

    function updateInstancePanel() {
        if (!elements.instanceContainer || !elements.instanceToggle) {
            return;
        }

        const collapsed = state.instanceCollapsed;
        elements.instanceContainer.setAttribute('data-collapsed', collapsed ? 'true' : 'false');
        elements.instanceToggle.textContent = collapsed ? 'Mostrar detalhes' : 'Ocultar detalhes';
        elements.instanceToggle.setAttribute('aria-expanded', String(!collapsed));
        elements.instanceToggle.setAttribute('title', collapsed ? 'Expandir painel' : 'Recolher painel');
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

    function normalizeGroupName(group, preserveCase = false) {
        if (!group) {
            return preserveCase ? 'Conversa' : '';
        }

        const candidates = [
            typeof group.name === 'string' ? group.name.trim() : '',
            formatWaId(group.wa_id),
        ].filter(Boolean);

        const label = candidates.length ? candidates[0] : preserveCase ? 'Conversa' : '';

        return preserveCase ? label : label.toLowerCase();
    }

    function buildInitials(label) {
        const source = (label ?? '').trim();

        if (!source) {
            return '??';
        }

        const parts = source.split(/\s+/);

        if (parts.length === 1) {
            return parts[0].slice(0, 2).toUpperCase();
        }

        const first = parts[0][0] ?? '';
        const last = parts[parts.length - 1][0] ?? '';

        return `${first}${last}`.toUpperCase();
    }

    function formatWaId(value) {
        if (!value) {
            return '';
        }

        const label = value.toString();

        if (label.includes('@')) {
            const before = label.split('@')[0];
            if (before) {
                return before;
            }
        }

        return label;
    }

    function matchesSearchTerm(group, term) {
        if (!term) {
            return true;
        }

        const haystack = [
            normalizeGroupName(group, true),
            formatWaId(group?.wa_id ?? ''),
        ].join(' ').toLowerCase();

        return haystack.includes(term);
    }

    function createMediaElement(message) {
        const media = message.media;

        if (!media || !media.url) {
            return null;
        }

        const mime = (media.mime || '').toLowerCase();
        const type = (message.message_type || '').toLowerCase();

        if (mime.startsWith('audio/') || type === 'audio') {
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'metadata';
            audio.src = media.url;
            audio.className = 'message__media message__media--audio';
            return audio;
        }

        const img = document.createElement('img');
        img.src = media.url;
        img.alt = media.original_name ?? 'Arquivo de mídia';
        img.loading = 'lazy';
        img.className = 'message__media message__media--image';

        if (type === 'sticker') {
            img.classList.add('message__media--sticker');
        }

        return img;
    }

    loadGroups();
    renderHeader();
    renderInstanceStatus();
    loadInstanceStatus();

    state.refreshGroupsInterval = setInterval(() => loadGroups(), 8000);
    state.refreshInstanceInterval = setInterval(() => loadInstanceStatus(), 15000);
})();
