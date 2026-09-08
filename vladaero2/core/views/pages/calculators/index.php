<?php /** @var array $config */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🧮 Калькуляторы и конвертеры</h1>
        <div class="grid grid--3">
            <a href="<?= url('/calculators/speed') ?>" class="card card--center">
                <div class="card__body">
                    <div class="card__icon">🚀</div>
                    <h3 class="card__title">Конвертер скорости</h3>
                    <p class="card__desc">Узлы, км/ч, миль/ч, м/с, Мах</p>
                </div>
            </a>
            <a href="<?= url('/calculators/altitude') ?>" class="card card--center">
                <div class="card__body">
                    <div class="card__icon">⛰️</div>
                    <h3 class="card__title">Конвертер высоты</h3>
                    <p class="card__desc">Футы, метры, FL</p>
                </div>
            </a>
            <a href="<?= url('/calculators/fuel') ?>" class="card card--center">
                <div class="card__body">
                    <div class="card__icon">⛽</div>
                    <h3 class="card__title">Калькулятор топлива</h3>
                    <p class="card__desc">Расход, запас, галлоны/литры</p>
                </div>
            </a>
            <a href="<?= url('/calculators/weight') ?>" class="card card--center">
                <div class="card__body">
                    <div class="card__icon">⚖️</div>
                    <h3 class="card__title">Конвертер массы</h3>
                    <p class="card__desc">Кг/фунты</p>
                </div>
            </a>
            <a href="<?= url('/calculators/temperature') ?>" class="card card--center">
                <div class="card__body">
                    <div class="card__icon">🌡️</div>
                    <h3 class="card__title">Конвертер температуры</h3>
                    <p class="card__desc">°C / °F</p>
                </div>
            </a>
        </div>
    </div>
</section>
