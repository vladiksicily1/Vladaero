<?php
/**
 * ShibaLingo - Leagues & Leaderboard (Feature #1)
 */

$pageTitle = 'Лиги и Рейтинг';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$leaders = $db->query("SELECT id, username, xp, streak, avatar, selected_skin FROM " . tbl('users') . " WHERE status = 'active' ORDER BY xp DESC LIMIT 20")->fetchAll();

$userRank = 1;
foreach ($leaders as $idx => $l) {
    if ($l['id'] == $user['id']) {
        $userRank = $idx + 1;
        break;
    }
}
?>

<div style="max-width: 750px; margin: 0 auto;">
    <!-- League Switcher / Visual Ladder -->
    <div style="display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;">
        <span class="badge-tag" style="background: #fed7aa; color: #9a3412; font-size: 0.85rem; font-weight: 800;">🥉 Бронза</span>
        <span class="badge-tag" style="background: #e2e8f0; color: #475569; font-size: 0.85rem; font-weight: 800;">🥈 Серебро</span>
        <span class="badge-tag" style="background: #fef08a; color: #854d0e; font-size: 0.9rem; font-weight: 900; border: 2px solid #ca8a04;">🥇 Золото (Текущая)</span>
        <span class="badge-tag" style="background: #e0f2fe; color: #0369a1; font-size: 0.85rem; font-weight: 800;">💎 Сапфир</span>
        <span class="badge-tag" style="background: #ffe4e6; color: #9f1239; font-size: 0.85rem; font-weight: 800;">🔴 Рубин</span>
        <span class="badge-tag" style="background: #f3e8ff; color: #6b21a8; font-size: 0.85rem; font-weight: 800;">👑 Бриллиант</span>
    </div>

    <!-- League Banner -->
    <div class="card-duo anim-bounce" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; border: none; box-shadow: 0 6px 0 #b45309; text-align: center; padding: 28px;">
        <div style="font-size: 3rem; margin-bottom: 8px;">🏆</div>
        <h1 style="font-size: 1.8rem; font-weight: 900; margin-bottom: 4px;">Золотая Лига Шибы</h1>
        <p style="font-size: 0.95rem; opacity: 0.9;">
            Топ-7 учеников недели переходят в Сапфировую Лигу! До конца турнира: <strong>2 дня 14 часов</strong>.
        </p>
    </div>

    <!-- Top 3 Podium -->
    <?php if (count($leaders) >= 3): ?>
        <div style="display: flex; justify-content: center; align-items: flex-end; gap: 16px; margin: 32px 0 24px 0;">
            <!-- 2nd Place -->
            <div style="flex: 1; text-align: center;">
                <div style="font-size: 2.2rem;">🥈</div>
                <div style="font-weight: 800; font-size: 1rem;"><?= e($leaders[1]['username']) ?></div>
                <div style="color: #eab308; font-weight: 800; font-size: 0.9rem;">⚡ <?= (int)$leaders[1]['xp'] ?> XP</div>
                <div style="background: #e2e8f0; height: 75px; border-radius: 16px 16px 0 0; margin-top: 10px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.4rem; color: #64748b;">
                    #2
                </div>
            </div>

            <!-- 1st Place -->
            <div style="flex: 1.2; text-align: center;">
                <div style="font-size: 3rem;">🥇</div>
                <div style="font-weight: 900; font-size: 1.15rem; color: var(--primary);"><?= e($leaders[0]['username']) ?></div>
                <div style="color: #eab308; font-weight: 800; font-size: 1rem;">⚡ <?= (int)$leaders[0]['xp'] ?> XP</div>
                <div style="background: #fef08a; height: 105px; border-radius: 16px 16px 0 0; margin-top: 10px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.8rem; color: #a16207;">
                    #1
                </div>
            </div>

            <!-- 3rd Place -->
            <div style="flex: 1; text-align: center;">
                <div style="font-size: 2.2rem;">🥉</div>
                <div style="font-weight: 800; font-size: 1rem;"><?= e($leaders[2]['username']) ?></div>
                <div style="color: #eab308; font-weight: 800; font-size: 0.9rem;">⚡ <?= (int)$leaders[2]['xp'] ?> XP</div>
                <div style="background: #fed7aa; height: 55px; border-radius: 16px 16px 0 0; margin-top: 10px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.4rem; color: #9a3412;">
                    #3
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Full Leaderboard List -->
    <div class="card-duo">
        <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px;">Таблица лидеров</h3>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($leaders as $rank => $lead): 
                $pos = $rank + 1;
                $isMe = ($lead['id'] == $user['id']);
            ?>
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-radius: 14px; background: <?= $isMe ? 'var(--primary-light)' : 'var(--bg-main)' ?>; border: 2px solid <?= $isMe ? 'var(--primary)' : 'transparent' ?>;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <span style="font-weight: 900; font-size: 1.1rem; width: 24px; color: <?= ($pos <= 3) ? 'var(--primary)' : 'var(--text-muted)' ?>;">
                            #<?= $pos ?>
                        </span>
                        <span style="font-size: 1.6rem;">🐕</span>
                        <div>
                            <span style="font-weight: 800; font-size: 1rem; color: <?= $isMe ? 'var(--primary-shadow)' : 'inherit' ?>;">
                                <?= e($lead['username']) ?> <?= $isMe ? ' (Вы)' : '' ?>
                            </span>
                            <div style="font-size: 0.8rem; color: var(--streak-color); font-weight: 700;">
                                🔥 <?= (int)$lead['streak'] ?> дней стрик
                            </div>
                        </div>
                    </div>
                    <div style="font-weight: 900; color: #eab308; font-size: 1.05rem;">
                        ⚡ <?= (int)$lead['xp'] ?> XP
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
