<?php
if (!defined('VLADINC_INIT')) exit;

$pageTitle = 'VladInc Social — Лента публикаций';
$currentUser = Auth::user();

// Filter tab
$filter = $_GET['filter'] ?? 'all';

// Build posts query
$where = [];
$params = [];

if ($filter === 'following' && $currentUser) {
    $where[] = "(p.user_id IN (SELECT following_id FROM follows WHERE follower_id = ?) OR p.user_id = ?)";
    $params[] = $currentUser['id'];
    $params[] = $currentUser['id'];
} elseif ($filter === 'media') {
    $where[] = "(p.media_url IS NOT NULL AND p.media_url != '')";
}

$whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
$orderBy = $filter === 'popular' ? "p.likes_count DESC, p.id DESC" : "p.is_pinned DESC, p.id DESC";

$sql = "SELECT p.*, u.username, u.display_name, u.avatar, u.role, u.level,
               c.name as community_name, c.slug as community_slug,
               qp.content as quote_content, qu.username as quote_username, qu.display_name as quote_display_name, qu.avatar as quote_avatar
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN communities c ON p.community_id = c.id
        LEFT JOIN posts qp ON p.quote_post_id = qp.id
        LEFT JOIN users qu ON qp.user_id = qu.id
        {$whereSql} 
        ORDER BY {$orderBy} 
        LIMIT 40";

$posts = DB::fetchAll($sql, $params);

// Fetch carousel media for posts
$postMediaMap = [];
if (!empty($posts)) {
    $postIds = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $allMedia = DB::fetchAll("SELECT * FROM post_media WHERE post_id IN ({$placeholders}) ORDER BY sort_order ASC", $postIds);
    foreach ($allMedia as $m) {
        $postMediaMap[$m['post_id']][] = $m['media_url'];
    }
}

// Fetch user's liked and bookmarked post IDs
$likedPostMap = [];
$bookmarkedPostIds = [];
if ($currentUser && !empty($posts)) {
    $postIds = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    
    $likedRows = DB::fetchAll("SELECT post_id, reaction FROM post_likes WHERE user_id = ? AND post_id IN ({$placeholders})", array_merge([$currentUser['id']], $postIds));
    foreach ($likedRows as $lr) {
        $likedPostMap[$lr['post_id']] = $lr['reaction'];
    }

    $bmRows = DB::fetchAll("SELECT post_id FROM bookmarks WHERE user_id = ? AND post_id IN ({$placeholders})", array_merge([$currentUser['id']], $postIds));
    $bookmarkedPostIds = array_column($bmRows, 'post_id');
}

// Fetch active stories (within last 24h)
$activeStories = DB::fetchAll("
    SELECT s.*, u.username, u.display_name, u.avatar 
    FROM stories s
    JOIN users u ON s.user_id = u.id
    WHERE s.expires_at > NOW()
    ORDER BY s.id DESC LIMIT 15
");

// Sidebar data
$trends = [
    ['tag' => '#VladInc', 'count' => 142],
    ['tag' => '#VladPay', 'count' => 89],
    ['tag' => '#Music', 'count' => 73],
    ['tag' => '#Tech', 'count' => 45],
    ['tag' => '#Crypto', 'count' => 38]
];

$topUsers = DB::fetchAll("SELECT username, display_name, avatar, level, coins FROM users ORDER BY xp DESC LIMIT 4");

require_once TEMPLATES_PATH . '/header.php';
?>

<!-- FEED & POST COMPOSER AREA -->
<main class="feed-container">

    <!-- 1. STORIES CAROUSEL (Instagram) -->
    <div class="card" style="padding: 14px; overflow-x: auto; display: flex; gap: 14px; align-items: center; scrollbar-width: none;">
        <?php if ($currentUser): ?>
            <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; flex-shrink: 0;" onclick="document.getElementById('create-story-modal').style.display='block'">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: var(--bg-input); border: 2px dashed #3b82f6; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #3b82f6;">
                    +
                </div>
                <span style="font-size: 11px; font-weight: 600; color: var(--text-secondary);">Ваша история</span>
            </div>
        <?php endif; ?>

        <?php foreach ($activeStories as $st): ?>
            <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; flex-shrink: 0;" onclick="viewStory('<?= e(url($st['media_url'])) ?>', '<?= e($st['display_name']) ?>', '<?= e($st['caption'] ?? '') ?>')">
                <div style="width: 58px; height: 58px; border-radius: 50%; padding: 2px; background: linear-gradient(45deg, #ec4899, #8b5cf6, #3b82f6); display: flex; align-items: center; justify-content: center;">
                    <img src="<?= e(url($st['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 2px solid var(--bg-surface);">
                </div>
                <span style="font-size: 11px; font-weight: 600; max-width: 60px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= e($st['display_name']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 2. ULTIMATE POST COMPOSER -->
    <?php if ($currentUser): ?>
        <div class="card composer-card">
            <form id="post-composer-form" enctype="multipart/form-data">
                <div class="composer-top">
                    <img src="<?= e(url($currentUser['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" alt="Avatar" class="composer-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($currentUser['display_name']) ?>&background=3b82f6&color=fff'">
                    <div class="composer-input-wrap">
                        <textarea name="content" class="composer-textarea" placeholder="Что нового в экосистеме, <?= e($currentUser['display_name']) ?>? Используйте @mentions, #hashtags, вставляйте музыку и опросы..." rows="3"></textarea>
                    </div>
                </div>

                <!-- Feelings / Status selector (Facebook) -->
                <div id="feeling-selector" style="display: none; margin-top: 8px;">
                    <select name="feeling" class="form-control" style="font-size: 13px;">
                        <option value="">Выберите настроение / действие...</option>
                        <option value="🎉 празднует">🎉 празднует</option>
                        <option value="💻 пишет код">💻 пишет код</option>
                        <option value="🎧 слушает музыку">🎧 слушает музыку</option>
                        <option value="🚀 запускает проект">🚀 запускает проект</option>
                        <option value="☕ пьет кофе">☕ пьет кофе</option>
                        <option value="🔥 вдохновлен">🔥 вдохновлен</option>
                        <option value="✈️ путешествует">✈️ путешествует</option>
                        <option value="🎮 играет">🎮 играет</option>
                    </select>
                </div>

                <!-- Music track uploader (VK Music) -->
                <div id="music-uploader" style="display: none; background: var(--bg-input); padding: 14px; border-radius: var(--radius-md); margin-top: 8px;">
                    <div style="font-weight: 700; font-size: 13px; margin-bottom: 8px; color: #a78bfa;">🎵 Прикрепить аудиотрек (MP3):</div>
                    <input type="file" name="audio_file" accept="audio/*" class="form-control" style="margin-bottom: 6px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <input type="text" name="audio_title" class="form-control" placeholder="Название трека">
                        <input type="text" name="audio_artist" class="form-control" placeholder="Исполнитель">
                    </div>
                </div>

                <!-- Poll Builder (Twitter) -->
                <div id="poll-builder" style="display: none; background: var(--bg-input); padding: 14px; border-radius: var(--radius-md); margin-top: 8px;">
                    <div style="font-weight: 700; font-size: 13px; margin-bottom: 8px; color: #60a5fa;">📊 Создание опроса:</div>
                    <input type="text" name="poll_question" class="form-control" placeholder="Вопрос для голосования..." style="margin-bottom: 8px;">
                    <input type="text" name="poll_option[]" class="form-control" placeholder="Вариант ответа 1" style="margin-bottom: 6px;">
                    <input type="text" name="poll_option[]" class="form-control" placeholder="Вариант ответа 2" style="margin-bottom: 6px;">
                    <input type="text" name="poll_option[]" class="form-control" placeholder="Вариант ответа 3 (необязательно)">
                </div>

                <!-- Hidden quote post id -->
                <input type="hidden" name="quote_post_id" id="composer-quote-id" value="">
                <div id="quote-preview-box" style="display: none; margin-top: 10px;"></div>

                <div id="media-preview" style="margin-top: 10px; max-height: 180px; overflow: hidden; border-radius: 8px;"></div>

                <div class="composer-bottom">
                    <div class="composer-actions">
                        <label class="composer-btn-tool" title="Прикрепить до 10 фото в карусель">
                            <span>📷</span>
                            <span>Фото (Карусель)</span>
                            <input type="file" name="media[]" accept="image/*" multiple style="display: none;" onchange="previewMediaMultiple(this)">
                        </label>
                        <button type="button" class="composer-btn-tool" onclick="toggleElement('music-uploader')" title="Прикрепить аудио">
                            <span>🎵</span>
                            <span>Музыка</span>
                        </button>
                        <button type="button" class="composer-btn-tool" onclick="toggleElement('poll-builder')" title="Создать опрос">
                            <span>📊</span>
                            <span>Опрос</span>
                        </button>
                        <button type="button" class="composer-btn-tool" onclick="toggleElement('feeling-selector')" title="Настроение">
                            <span>✨</span>
                            <span>Статус</span>
                        </button>
                    </div>
                    <button type="submit" class="btn btn-primary">Опубликовать</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="card" style="text-align: center; padding: 28px;">
            <h3 style="font-size: 18px; margin-bottom: 8px;">Добро пожаловать в VladInc Social! 🌐</h3>
            <p style="color: var(--text-secondary); margin-bottom: 16px;">Войдите через Vlad ID, чтобы публиковать посты, слушать музыку, ставить реакции и общаться в экосистеме.</p>
            <div style="display: flex; gap: 12px; justify-content: center;">
                <a href="<?= url('login') ?>" class="btn btn-primary">Войти через Vlad ID</a>
                <a href="<?= url('register') ?>" class="btn btn-secondary">Зарегистрироваться</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. FEED FILTER TABS (ЧПУ) -->
    <div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
        <a href="<?= url('feed') ?>" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">Все посты</a>
        <?php if ($currentUser): ?>
            <a href="<?= url('feed/following') ?>" class="btn btn-sm <?= $filter === 'following' ? 'btn-primary' : 'btn-secondary' ?>">Подписки</a>
        <?php endif; ?>
        <a href="<?= url('feed/popular') ?>" class="btn btn-sm <?= $filter === 'popular' ? 'btn-primary' : 'btn-secondary' ?>">🔥 Популярное</a>
        <a href="<?= url('feed/media') ?>" class="btn btn-sm <?= $filter === 'media' ? 'btn-primary' : 'btn-secondary' ?>">📷 Только медиа</a>
    </div>

    <!-- 4. POSTS STREAM -->
    <div id="feed-stream" style="display: flex; flex-direction: column; gap: 18px;">
        <?php if (empty($posts)): ?>
            <div class="card" style="text-align: center; color: var(--text-muted); padding: 40px;">
                Пока нет публикаций. Будьте первым, кто опубликует запись!
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): 
                $reactionType = $likedPostMap[$post['id']] ?? null;
                $isLiked = !empty($reactionType);
                $isBookmarked = in_array((int)$post['id'], $bookmarkedPostIds, true);
                $carouselImages = $postMediaMap[$post['id']] ?? (!empty($post['media_url']) ? [$post['media_url']] : []);
            ?>
                <article class="card post-card" id="post-<?= $post['id'] ?>">
                    <!-- Header -->
                    <div class="post-header">
                        <div class="post-author-wrap">
                            <a href="<?= url('u/' . $post['username']) ?>">
                                <img src="<?= e(url($post['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" alt="Avatar" class="post-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($post['display_name']) ?>&background=3b82f6&color=fff'">
                            </a>
                            <div class="post-author-meta">
                                <div class="post-author-name">
                                    <a href="<?= url('u/' . $post['username']) ?>"><?= e($post['display_name']) ?></a>
                                    <?php if ($post['role'] === 'admin' || $post['role'] === 'verified'): ?>
                                        <span class="verified-badge" title="Верифицирован">✓</span>
                                    <?php endif; ?>
                                    <span class="level-badge">LVL <?= $post['level'] ?></span>
                                    <?php if (!empty($post['feeling'])): ?>
                                        <span class="author-feeling">&bull; <?= e($post['feeling']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="post-time">
                                    @<?= e($post['username']) ?> &bull; 
                                    <a href="<?= url('post/' . $post['id']) ?>" style="color: inherit;"><?= time_ago($post['created_at']) ?></a>
                                    <?php if (!empty($post['community_name'])): ?>
                                        &bull; в <a href="<?= url('c/' . $post['community_slug']) ?>" style="color: var(--accent-primary); font-weight: 600;"><?= e($post['community_name']) ?></a>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($post['is_pinned']): ?>
                            <span style="font-size: 12px; color: var(--accent-primary); font-weight: 700;">📌 Закреплено</span>
                        <?php endif; ?>
                    </div>

                    <!-- Content -->
                    <div class="post-content">
                        <?= parse_content($post['content']) ?>
                    </div>

                    <!-- Audio Player Attachment (VK Music) -->
                    <?php if (!empty($post['audio_url'])): ?>
                        <div class="post-audio-card">
                            <button type="button" class="audio-play-btn" onclick="playAudioTrack('<?= url($post['audio_url']) ?>', '<?= e(addslashes($post['audio_title'] ?: 'Аудиотрек')) ?>', '<?= e(addslashes($post['audio_artist'] ?: $post['display_name'])) ?>')">
                                ▶
                            </button>
                            <div class="audio-meta">
                                <div class="audio-title"><?= e($post['audio_title'] ?: 'Аудиотрек') ?></div>
                                <div class="audio-artist"><?= e($post['audio_artist'] ?: $post['display_name']) ?></div>
                            </div>
                            <span style="font-size: 20px;">🎵</span>
                        </div>
                    <?php endif; ?>

                    <!-- Quote Repost Box (Twitter Quote Tweet) -->
                    <?php if (!empty($post['quote_post_id']) && !empty($post['quote_content'])): ?>
                        <div class="quoted-post-card" onclick="window.location.href='<?= url('post/' . $post['quote_post_id']) ?>'">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <img src="<?= e(url($post['quote_avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 24px; height: 24px; border-radius: 50%;">
                                <strong style="font-size: 13px;"><?= e($post['quote_display_name']) ?></strong>
                                <span style="font-size: 11px; color: var(--text-muted);">@<?= e($post['quote_username']) ?></span>
                            </div>
                            <div style="font-size: 13px; color: var(--text-secondary);"><?= parse_content($post['quote_content']) ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Poll (Twitter) -->
                    <?php if (!empty($post['poll_question']) && !empty($post['poll_options'])): 
                        $options = json_decode($post['poll_options'], true) ?: [];
                        $votes = json_decode($post['poll_votes'], true) ?: [];
                        $totalVotes = count($votes);
                        $hasVoted = $currentUser && isset($votes[$currentUser['id']]);
                        $myVote = $hasVoted ? $votes[$currentUser['id']] : null;
                    ?>
                        <div class="poll-box" style="background: var(--bg-input); padding: 14px; border-radius: var(--radius-md); margin-top: 10px;">
                            <div style="font-weight: 700; font-size: 14px; margin-bottom: 10px;">📊 <?= e($post['poll_question']) ?></div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($options as $idx => $opt): 
                                    $optVotes = 0;
                                    foreach ($votes as $v) if ($v == $idx) $optVotes++;
                                    $pct = $totalVotes > 0 ? round(($optVotes / $totalVotes) * 100) : 0;
                                ?>
                                    <?php if ($hasVoted): ?>
                                        <div style="position: relative; background: var(--bg-surface); border-radius: 8px; padding: 10px 14px; overflow: hidden; border: 1px solid <?= ($myVote == $idx) ? 'var(--accent-primary)' : 'var(--border-color)' ?>;">
                                            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: <?= $pct ?>%; background: rgba(59, 130, 246, 0.25); z-index: 1;"></div>
                                            <div style="position: relative; z-index: 2; display: flex; justify-content: space-between; font-size: 13px; font-weight: 600;">
                                                <span><?= ($myVote == $idx) ? '✓ ' : '' ?><?= e($opt) ?></span>
                                                <span><?= $pct ?>% (<?= $optVotes ?>)</span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" style="width: 100%; justify-content: flex-start; text-align: left;" onclick="castVote(<?= $post['id'] ?>, <?= $idx ?>)">
                                            <?= e($opt) ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 8px; text-align: right;">Всего голосов: <?= $totalVotes ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Multi-Photo Carousel (Instagram) -->
                    <?php if (count($carouselImages) > 1): ?>
                        <div class="post-carousel" id="carousel-<?= $post['id'] ?>">
                            <div class="carousel-counter" id="carousel-counter-<?= $post['id'] ?>">1 / <?= count($carouselImages) ?></div>
                            <button type="button" class="carousel-nav-btn carousel-prev" onclick="prevSlide(<?= $post['id'] ?>)">‹</button>
                            <button type="button" class="carousel-nav-btn carousel-next" onclick="nextSlide(<?= $post['id'] ?>)">›</button>
                            <div class="carousel-track" id="carousel-track-<?= $post['id'] ?>" data-current-index="0">
                                <?php foreach ($carouselImages as $cImg): ?>
                                    <div class="carousel-slide">
                                        <img src="<?= e(url($cImg)) ?>" alt="Slide" loading="lazy">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="carousel-indicators" id="carousel-indicators-<?= $post['id'] ?>">
                                <?php foreach ($carouselImages as $idx => $cImg): ?>
                                    <div class="carousel-dot <?= $idx === 0 ? 'active' : '' ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif (count($carouselImages) === 1): ?>
                        <div class="post-media-wrap">
                            <a href="<?= url('post/' . $post['id']) ?>">
                                <img src="<?= e(url($carouselImages[0])) ?>" alt="Media" loading="lazy">
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Multi-Reaction & Action Footer -->
                    <div class="post-footer">
                        <div class="post-reactions">
                            <button type="button" class="react-btn <?= $isLiked ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                                <span><?= $isLiked ? '❤️' : '🤍' ?></span>
                                <span class="like-count"><?= $post['likes_count'] ?></span>
                            </button>

                            <button type="button" class="react-btn toggle-comments-btn" data-post-id="<?= $post['id'] ?>">
                                <span>💬</span>
                                <span class="comments-count-<?= $post['id'] ?>"><?= $post['comments_count'] ?></span>
                            </button>

                            <button type="button" class="react-btn" onclick="prepareQuotePost(<?= $post['id'] ?>, '<?= e(addslashes($post['display_name'])) ?>', '<?= e(addslashes(mb_strimwidth($post['content'], 0, 100, '...'))) ?>')" title="Процитировать запись">
                                <span>🔄 Цитата</span>
                            </button>

                            <button type="button" class="react-btn" onclick="toggleBookmark(<?= $post['id'] ?>, this)" title="В закладки">
                                <span><?= $isBookmarked ? '🔖' : '📑' ?></span>
                            </button>

                            <?php if ($currentUser && $currentUser['id'] !== $post['user_id']): ?>
                                <button type="button" class="react-btn" style="color: #fbbf24;" onclick="quickTipAuthor(<?= $post['user_id'] ?>, '<?= e($post['username']) ?>')" title="Отправить чаевые автору">
                                    <span>🪙 Чаевые</span>
                                </button>
                            <?php endif; ?>
                        </div>

                        <div style="font-size: 12px; color: var(--text-muted);">
                            👁️ <?= $post['views_count'] ?>
                        </div>
                    </div>

                    <!-- Inline Comments Box with Photo Comments -->
                    <div id="comments-box-<?= $post['id'] ?>" style="display: none; border-top: 1px solid var(--border-color); padding-top: 14px; margin-top: 6px;">
                        <div id="comments-list-<?= $post['id'] ?>" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px;">
                            <?php
                            $comments = DB::fetchAll("SELECT c.*, u.username, u.display_name, u.avatar, u.level FROM post_comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY c.id ASC LIMIT 10", [$post['id']]);
                            foreach ($comments as $comm): ?>
                                <div style="display: flex; gap: 10px; font-size: 13px; background: var(--bg-input); padding: 10px; border-radius: var(--radius-sm);">
                                    <img src="<?= e(url($comm['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 28px; height: 28px; border-radius: 50%;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($comm['display_name']) ?>&background=3b82f6&color=fff'">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 700; margin-bottom: 2px;">
                                            <a href="<?= url('u/' . $comm['username']) ?>"><?= e($comm['display_name']) ?></a>
                                            <span style="font-size: 11px; color: var(--text-muted); font-weight: normal; margin-left: 6px;"><?= time_ago($comm['created_at']) ?></span>
                                        </div>
                                        <div><?= parse_content($comm['content']) ?></div>
                                        <?php if (!empty($comm['media_url'])): ?>
                                            <div style="margin-top: 6px;">
                                                <a href="<?= e(url($comm['media_url'])) ?>" target="_blank">
                                                    <img src="<?= e(url($comm['media_url'])) ?>" style="max-width: 240px; max-height: 180px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-color);" loading="lazy">
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($currentUser): ?>
                            <form class="comment-form" data-post-id="<?= $post['id'] ?>" style="display: flex; gap: 8px; align-items: center;" enctype="multipart/form-data">
                                <input type="text" name="content" class="form-control" placeholder="Написать комментарий..." style="flex: 1; padding: 8px 12px; font-size: 13px;">
                                <label style="cursor: pointer; margin: 0; padding: 6px 10px; border-radius: 8px; background: var(--bg-surface); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 16px;" title="Прикрепить фото к комментарию">
                                    📷
                                    <input type="file" name="comment_media" accept="image/*" style="display: none;" onchange="if(this.files[0]) this.parentElement.style.borderColor='var(--accent-primary)';">
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm">Отправить</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<!-- RIGHT SIDEBAR -->
<aside class="right-sidebar">

    <?php if ($currentUser): ?>
        <div class="card widget-card">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <img src="<?= e(url($currentUser['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($currentUser['display_name']) ?>&background=3b82f6&color=fff'">
                <div>
                    <div style="font-weight: 700; font-size: 15px;"><?= e($currentUser['display_name']) ?></div>
                    <div style="font-size: 12px; color: var(--text-muted);">@<?= e($currentUser['username']) ?></div>
                </div>
            </div>

            <?php $prog = level_progress((int)$currentUser['xp']); ?>
            <div style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                    <span>Уровень <?= $prog['level'] ?></span>
                    <span style="color: #c084fc; font-weight: 700;"><?= $prog['current_xp'] ?> / <?= $prog['needed_xp'] ?> XP</span>
                </div>
                <div style="height: 6px; background: var(--bg-input); border-radius: 3px; overflow: hidden;">
                    <div style="width: <?= $prog['percentage'] ?>%; height: 100%; background: linear-gradient(90deg, #3b82f6, #8b5cf6);"></div>
                </div>
            </div>

            <button type="button" id="claim-bonus-btn" class="btn btn-secondary btn-sm" style="width: 100%;">
                🎁 Забрать ежедневный бонус (+50 🪙)
            </button>
        </div>
    <?php endif; ?>

    <!-- Developer REST API Box -->
    <div class="card widget-card" style="background: linear-gradient(135deg, #0f172a, #1e1b4b); border-color: #0ea5e9;">
        <div style="font-size: 12px; font-weight: 800; color: #38bdf8; text-transform: uppercase; margin-bottom: 6px;">Внешний REST API</div>
        <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px;">Интегрируйте ботов и внешние сайты через ключи доступа.</div>
        <a href="<?= url('developers') ?>" class="btn btn-secondary btn-sm" style="width: 100%; border-color: #0ea5e9; color: #38bdf8;">🔑 Получить API-ключ</a>
    </div>

    <!-- Trending Tags -->
    <div class="card widget-card">
        <div class="widget-title">🔥 Тренды экосистемы</div>
        <?php foreach ($trends as $t): ?>
            <div class="trend-item">
                <a href="<?= url('explore?q=' . urlencode($t['tag'])) ?>" class="trend-tag"><?= e($t['tag']) ?></a>
                <span class="trend-count"><?= $t['count'] ?> постов</span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Top Leaders -->
    <div class="card widget-card">
        <div class="widget-title">🏆 Лидеры VladInc</div>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($topUsers as $top): ?>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <img src="<?= e(url($top['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 32px; height: 32px; border-radius: 50%;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($top['display_name']) ?>&background=3b82f6&color=fff'">
                        <div>
                            <div style="font-size: 13px; font-weight: 700;">
                                <a href="<?= url('u/' . $top['username']) ?>"><?= e($top['display_name']) ?></a>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted);">Ур. <?= $top['level'] ?></div>
                        </div>
                    </div>
                    <span style="font-size: 12px; color: #fbbf24; font-weight: 700;">🪙 <?= format_coins($top['coins']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</aside>

<!-- CREATE STORY MODAL -->
<div id="create-story-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div class="card" style="max-width: 420px; margin: 100px auto; position: relative;">
        <button type="button" onclick="document.getElementById('create-story-modal').style.display='none'" style="position: absolute; right: 16px; top: 16px; background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">✕</button>
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 14px;">Опубликовать Историю (на 24 часа)</h3>
        <form id="story-form" enctype="multipart/form-data">
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Выберите фото (JPG/PNG/WEBP)</label>
                <input type="file" name="story_media" accept="image/*" class="form-control" required>
            </div>
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Подпись (необязательно)</label>
                <input type="text" name="story_caption" class="form-control" placeholder="Мой день...">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Опубликовать историю</button>
        </form>
    </div>
</div>

<!-- FULLSCREEN STORY VIEWER MODAL -->
<div id="story-viewer-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 3000; align-items: center; justify-content: center;">
    <div style="max-width: 420px; width: 100%; height: 90vh; background: #000; border-radius: 16px; position: relative; overflow: hidden; display: flex; flex-direction: column; margin: 4vh auto;">
        <button type="button" onclick="document.getElementById('story-viewer-modal').style.display='none'" style="position: absolute; right: 16px; top: 16px; background: rgba(0,0,0,0.5); border: none; font-size: 24px; color: #fff; border-radius: 50%; width: 36px; height: 36px; cursor: pointer; z-index: 10;">✕</button>
        <div style="position: absolute; left: 16px; top: 16px; color: #fff; font-weight: 700; font-size: 15px; text-shadow: 0 1px 4px rgba(0,0,0,0.8); z-index: 10;" id="story-author-label"></div>
        <img id="story-viewer-img" style="width: 100%; height: 100%; object-fit: contain;">
        <div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(transparent, rgba(0,0,0,0.8)); padding: 20px; color: #fff; font-size: 14px;" id="story-caption-label"></div>
    </div>
</div>

<script>
function toggleElement(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function previewMediaMultiple(input) {
    const preview = document.getElementById('media-preview');
    preview.innerHTML = '';
    if (input.files) {
        Array.from(input.files).slice(0, 10).forEach(file => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.width = '70px';
                img.style.height = '70px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.marginRight = '6px';
                preview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    }
}

function prepareQuotePost(postId, author, snippet) {
    document.getElementById('composer-quote-id').value = postId;
    const box = document.getElementById('quote-preview-box');
    box.innerHTML = `<div class="quoted-post-card" style="border-left: 3px solid var(--accent-primary);">
        <div style="display:flex; justify-content:space-between;">
            <strong>Цитата @${author}</strong>
            <button type="button" onclick="cancelQuote()" style="background:transparent; border:none; color:var(--text-muted); cursor:pointer;">✕</button>
        </div>
        <div style="font-size:12px; color:var(--text-secondary);">${snippet}</div>
    </div>`;
    box.style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelQuote() {
    document.getElementById('composer-quote-id').value = '';
    document.getElementById('quote-preview-box').style.display = 'none';
}

function viewStory(url, author, caption) {
    document.getElementById('story-viewer-img').src = url;
    document.getElementById('story-author-label').innerText = author;
    document.getElementById('story-caption-label').innerText = caption;
    document.getElementById('story-viewer-modal').style.display = 'flex';
}

const storyForm = document.getElementById('story-form');
if (storyForm) {
    storyForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(storyForm);
        try {
            const res = await fetch(baseUrl + '/api/index.php?action=create_story', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else showToast(data.error, 'error');
        } catch(e) {
            showToast('Ошибка публикации', 'error');
        }
    });
}

async function castVote(postId, optionIdx) {
    try {
        const res = await fetch(baseUrl + '/api/index.php?action=vote_poll', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId, option_index: optionIdx })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Ваш голос учтен! +1 VladCoin', 'success');
            setTimeout(() => location.reload(), 800);
        } else showToast(data.error, 'error');
    } catch(e) {
        showToast('Ошибка голосования', 'error');
    }
}

async function toggleBookmark(postId, btn) {
    try {
        const res = await fetch(baseUrl + '/api/index.php?action=toggle_bookmark', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId })
        });
        const data = await res.json();
        if (data.success) {
            btn.querySelector('span').innerText = data.is_bookmarked ? '🔖' : '📑';
            showToast(data.message, 'success');
        } else showToast(data.error, 'error');
    } catch(e) {
        showToast('Ошибка сети', 'error');
    }
}

async function quickTipAuthor(userId, username) {
    const amount = prompt("Сколько VladCoins отправить автору @" + username + "?", "10");
    if (!amount || isNaN(amount) || amount <= 0) return;
    try {
        const res = await fetch(baseUrl + '/api/index.php?action=transfer_coins', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ to_user: username, amount: parseInt(amount, 10), note: 'Чаевые за публикацию' })
        });
        const data = await res.json();
        if (data.success) showToast('🪙 Вы отправили ' + amount + ' VladCoins автору!', 'success');
        else showToast(data.error, 'error');
    } catch(e) {
        showToast('Сетевой сбой', 'error');
    }
}
</script>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
