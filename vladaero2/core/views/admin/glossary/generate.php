<?php /** AI generate glossary */ ?>
<section class="section"><div class="container">
    <h1 class="page-title">🤖 Генерация глоссария (AI)</h1>
    <form method="POST" action="<?= url('/admin/glossary/generate-run') ?>" class="form-card">
        <?= csrf_field() ?>
        <p class="text-muted">AI автоматически сгенерирует 10 терминов по указанной теме и добавит в глоссарий.</p>
        <div class="form-group"><label>Тема / категория</label>
            <input type="text" name="topic" class="form-input" required placeholder="напр. Аэродинамика, Метеорология, Авионика, Двигатели">
        </div>
        <button type="submit" class="btn btn--accent">🤖 Сгенерировать 10 терминов</button>
        <a href="<?= url('/admin/glossary') ?>" class="btn btn--outline">← Назад</a>
    </form>
</div></section>
