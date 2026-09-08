<?php
if (!defined('VLADINC_INIT')) exit;
$currentRoute = $route ?? 'feed';
?>
    </div> <!-- /main-wrapper -->

    <!-- STICKY GLOBAL AUDIO PLAYER BAR (VK Music) -->
    <div id="global-audio-player" class="global-audio-player">
        <button type="button" class="audio-play-btn" id="gap-play-btn" onclick="toggleGlobalPlay()" style="width: 38px; height: 38px; font-size: 15px;">
            ▶
        </button>
        <div style="display: flex; flex-direction: column; min-width: 140px; max-width: 220px;">
            <div id="gap-title" style="font-size: 13px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Музыка VladInc</div>
            <div id="gap-artist" style="font-size: 11px; color: var(--text-muted);">Исполнитель</div>
        </div>
        <div class="player-scrubber" onclick="scrubAudio(event)">
            <div class="player-progress" id="gap-progress"></div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('global-audio-player').classList.remove('active'); globalAudio.pause();" style="padding: 4px 8px; font-size: 11px;">
            ✕
        </button>
    </div>

    <!-- MOBILE BOTTOM NAVIGATION BAR (ЧПУ) -->
    <nav class="mobile-bottom-nav">
        <a href="<?= url('feed') ?>" class="nav-item <?= in_array($currentRoute, ['feed', 'social', 'post'], true) ? 'active' : '' ?>" style="padding: 6px 12px; font-size: 20px;">
            📰
        </a>
        <a href="<?= url('messages') ?>" class="nav-item <?= in_array($currentRoute, ['messages', 'im', 'messenger'], true) ? 'active' : '' ?>" style="padding: 6px 12px; font-size: 20px;">
            💬
        </a>
        <a href="<?= url('wallet') ?>" class="nav-item <?= $currentRoute === 'wallet' ? 'active' : '' ?>" style="padding: 6px 12px; font-size: 20px;">
            🪙
        </a>
        <a href="<?= url('bookmarks') ?>" class="nav-item <?= $currentRoute === 'bookmarks' ? 'active' : '' ?>" style="padding: 6px 12px; font-size: 20px;">
            📑
        </a>
        <a href="<?= url('settings') ?>" class="nav-item <?= in_array($currentRoute, ['settings', 'id'], true) ? 'active' : '' ?>" style="padding: 6px 12px; font-size: 20px;">
            🆔
        </a>
    </nav>

    <!-- TOAST CONTAINER -->
    <div id="toast-container"></div>

    <!-- MAIN ENGINE SCRIPT -->
    <script src="<?= asset('js/vladinc.js') ?>?v=<?= APP_VERSION ?>"></script>
    <script>
    function scrubAudio(e) {
        if (!globalAudio.duration) return;
        const rect = e.currentTarget.getBoundingClientRect();
        const pos = (e.clientX - rect.left) / rect.width;
        globalAudio.currentTime = pos * globalAudio.duration;
    }
    </script>
</body>
</html>
