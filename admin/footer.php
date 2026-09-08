    </main>

    <footer class="bg-slate-900 border-t border-slate-800 py-6 text-center text-xs text-slate-500 font-mono">
        <div class="max-w-7xl mx-auto px-4 flex items-center justify-between">
            <div>VladAero Control Center • PHP <?= PHP_VERSION ?></div>
            <div class="space-x-4">
                <a href="<?= url('/admin/audit.php') ?>" class="text-slate-400 hover:text-sky-400">Журнал аудита</a>
                <a href="<?= url('/install.php') ?>" class="text-slate-400 hover:text-sky-400">Техническая консоль</a>
            </div>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
