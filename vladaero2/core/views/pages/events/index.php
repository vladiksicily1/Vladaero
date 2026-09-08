<?php
/** @var array $events */
/** @var int $month */
/** @var int $year */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📅 Авиационные события</h1>

        <div class="month-nav">
            <?php
            $prev = $month - 1;
            $prevYear = $year;
            if ($prev < 1) { $prev = 12; $prevYear--; }
            $next = $month + 1;
            $nextYear = $year;
            if ($next > 12) { $next = 1; $nextYear++; }
            $monthNames = ['', 'Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
            ?>
            <a href="?month=<?= $prev ?>&year=<?= $prevYear ?>" class="btn btn--outline">← <?= $monthNames[$prev] ?></a>
            <h2><?= $monthNames[$month] ?> <?= $year ?></h2>
            <a href="?month=<?= $next ?>&year=<?= $nextYear ?>" class="btn btn--outline"><?= $monthNames[$next] ?> →</a>
        </div>

        <?php if (empty($events)): ?>
            <div class="empty-state">
                <p>Нет событий на этот период</p>
            </div>
        <?php else: ?>
            <div class="events-list">
                <?php foreach ($events as $event): ?>
                    <a href="<?= url('/events/' . e($event['slug'])) ?>" class="card card--event">
                        <div class="card__date">
                            <span class="card__date-day"><?= date('d', strtotime($event['start_date'])) ?></span>
                            <span class="card__date-month"><?= date('M', strtotime($event['start_date'])) ?></span>
                        </div>
                        <div class="card__body">
                            <h3 class="card__title"><?= e($event['name']) ?></h3>
                            <?php if (!empty($event['airport_name'])): ?>
                                <span class="card__spec">📍 <?= e($event['airport_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($event['description'])): ?>
                                <p class="card__desc"><?= e(mb_substr($event['description'], 0, 120)) ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
