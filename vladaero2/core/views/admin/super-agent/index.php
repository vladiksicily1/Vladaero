<?php /** admin super agent */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🤖 ИИ Супер-Агент</h1>

        <div class="super-agent">
            <div class="super-agent__chat" id="agentChat">
                <div class="agent-message agent-message--bot">
                    <p>👋 Привет! Я Супер-Агент VladAero.</p>
                    <p>Введите команду или задайте вопрос о данных сайта.</p>
                    <p class="text-muted mt-2">Примеры: «Статистика», «Покажи самолёты», «Помощь»</p>
                </div>
            </div>

            <form id="agentForm" class="super-agent__form">
                <?= csrf_field() ?>
                <div class="form-row">
                    <input type="text" id="agentInput" class="form-input" placeholder="Введите команду..." required autocomplete="off">
                    <button type="submit" class="btn btn--primary">Отправить</button>
                </div>
            </form>
        </div>
    </div>
</section>

<script>
document.getElementById('agentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const input = document.getElementById('agentInput');
    const chat = document.getElementById('agentChat');
    const cmd = input.value.trim();
    if (!cmd) return;

    // Show user message
    chat.innerHTML += '<div class="agent-message agent-message--user"><p>' + escapeHtml(cmd) + '</p></div>';
    input.value = '';

    // Show loading
    const loadingId = 'loading-' + Date.now();
    chat.innerHTML += '<div class="agent-message agent-message--bot" id="' + loadingId + '"><p>⏳ Выполняю...</p></div>';
    chat.scrollTop = chat.scrollHeight;

    try {
        const formData = new FormData(this);
        formData.set('command', cmd);
        const resp = await fetch('/admin/ai/execute', { method: 'POST', body: formData });
        const data = await resp.json();

        const el = document.getElementById(loadingId);
        if (data.type === 'text') {
            el.innerHTML = '<pre class="agent-pre">' + escapeHtml(data.text) + '</pre>';
        } else if (data.type === 'table') {
            let html = '<h4>' + escapeHtml(data.name || '') + '</h4><div class="table-responsive"><table class="table table--sm"><thead><tr>';
            if (data.data && data.data.length) {
                Object.keys(data.data[0]).forEach(k => html += '<th>' + escapeHtml(k) + '</th>');
                html += '</tr></thead><tbody>';
                data.data.forEach(row => {
                    html += '<tr>';
                    Object.values(row).forEach(v => html += '<td>' + escapeHtml(String(v ?? '')) + '</td>');
                    html += '</tr>';
                });
            }
            html += '</tbody></table></div>';
            el.innerHTML = html;
        }
    } catch(err) {
        const el = document.getElementById(loadingId);
        el.innerHTML = '<p class="text-danger">Ошибка: ' + err.message + '</p>';
    }
    chat.scrollTop = chat.scrollHeight;
});

function escapeHtml(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}
</script>
