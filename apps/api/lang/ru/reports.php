<?php

return [
    'title' => 'Отчёт аналитики', 'subtitle' => 'Точные значения тренда SelfHandler и полнота данных.',
    'filename' => 'selfhandler-:metric-:from-:to', 'generated_at' => 'Создано (UTC)', 'metric' => 'Показатель',
    'range' => 'Выбранный период', 'granularity' => 'Группировка', 'aggregation' => 'Агрегация', 'unit' => 'Единица',
    'available_points' => 'Интервалы с данными', 'total_intervals' => 'Все интервалы', 'first' => 'Первое значение',
    'last' => 'Последнее значение', 'delta' => 'Изменение', 'slope' => 'Наклон за интервал', 'comparison' => 'Предыдущий период',
    'current_value' => 'Текущее значение', 'previous_value' => 'Предыдущее значение', 'absolute_delta' => 'Абсолютное изменение',
    'percentage_delta' => 'Относительное изменение (%)', 'period_start' => 'Начало интервала', 'period_end' => 'Конец интервала',
    'value' => 'Значение', 'state' => 'Состояние данных', 'samples' => 'Наблюдения', 'reason' => 'Ограничение данных',
    'evidence_note' => 'Отсутствующие и неполные данные не считаются нулём. Описательный отчёт не является медицинской или финансовой рекомендацией.',
    'states' => ['ready' => 'Доступно', 'empty' => 'Нет данных', 'incomplete' => 'Неполно'],
    'granularities' => ['daily' => 'День', 'weekly' => 'Неделя', 'monthly' => 'Месяц'],
    'aggregations' => ['sum' => 'Сумма', 'mean' => 'Среднее арифметическое', 'percentage' => 'Взвешенный процент', 'last' => 'Последнее наблюдение'],
    'units' => ['percent' => 'Проценты', 'minutes' => 'Минуты', 'count' => 'Количество', 'rating_5' => 'Оценка из 5', 'rating_10' => 'Оценка из 10', 'kilograms' => 'Килограммы'],
    'reasons' => ['missing_fx' => 'Нет курса обмена для :currency', 'missing_evidence' => 'Нет данных'],
    'metrics' => [
        'routines' => ['completion_rate' => 'Выполнение рутин'], 'sleep' => ['duration_minutes' => 'Продолжительность сна', 'quality' => 'Качество сна'],
        'workouts' => ['completed_sessions' => 'Завершённые тренировки', 'duration_minutes' => 'Продолжительность тренировок'],
        'nutrition' => ['calorie_target_adherence' => 'Соблюдение цели калорий'], 'supplements' => ['adherence' => 'Соблюдение приёма добавок'],
        'habits' => ['completion_rate' => 'Выполнение привычек'], 'planner' => ['completion_rate' => 'Выполнение плана'],
        'finance' => ['income' => 'Доход', 'expense' => 'Расход', 'net' => 'Чистый денежный поток'],
        'review' => ['energy' => 'Энергия', 'mood' => 'Настроение', 'stress' => 'Стресс', 'day_rating' => 'Оценка дня'],
        'body' => ['body_mass' => 'Масса тела'],
    ],
];
