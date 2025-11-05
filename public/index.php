<?php

require_once __DIR__ . '/../config.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Social Insight - Monitoramento WhatsApp</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div id="app">
        <aside class="sidebar">
            <header class="sidebar__header">
                <div>
                    <h1>Social Insight</h1>
                    <p>Monitoramento de grupos WhatsApp</p>
                </div>
            </header>
            <div class="sidebar__search">
                <input type="search" id="groupSearch" placeholder="Buscar grupos">
            </div>
            <div class="sidebar__groups" id="groupList" role="listbox" aria-label="Grupos monitorados">
                <div class="empty-list">
                    Nenhum grupo carregado ainda.
                </div>
            </div>
        </aside>
        <main class="conversation">
            <header class="conversation__header" id="chatHeader">
                <div class="placeholder">
                    <h2>Selecione um grupo</h2>
                    <p>Escolha um grupo à esquerda para visualizar as mensagens.</p>
                </div>
            </header>
            <section class="conversation__messages" id="messageList">
                <div class="empty-state">
                    <p>As mensagens aparecerão aqui assim que um grupo for selecionado.</p>
                </div>
            </section>
        </main>
    </div>
    <script>
        window.APP_CONFIG = {
            debug: <?php echo json_encode(APP_DEBUG); ?>,
            endpoints: {
                groups: 'api/groups.php',
                messages: 'api/messages.php'
            }
        };
    </script>
    <script src="assets/app.js" defer></script>
</body>
</html>
