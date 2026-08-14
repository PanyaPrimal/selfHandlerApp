<?php

return [
    'title' => 'Звіт аналітики', 'subtitle' => 'Точні значення тренду SelfHandler і повнота даних.',
    'filename' => 'selfhandler-:metric-:from-:to', 'generated_at' => 'Створено (UTC)', 'metric' => 'Показник',
    'range' => 'Обраний період', 'granularity' => 'Групування', 'aggregation' => 'Агрегація', 'unit' => 'Одиниця',
    'available_points' => 'Інтервали з даними', 'total_intervals' => 'Усі інтервали', 'first' => 'Перше значення',
    'last' => 'Останнє значення', 'delta' => 'Зміна', 'slope' => 'Нахил за інтервал', 'comparison' => 'Попередній період',
    'current_value' => 'Поточне значення', 'previous_value' => 'Попереднє значення', 'absolute_delta' => 'Абсолютна зміна',
    'percentage_delta' => 'Відносна зміна (%)', 'period_start' => 'Початок інтервалу', 'period_end' => 'Кінець інтервалу',
    'value' => 'Значення', 'state' => 'Стан даних', 'samples' => 'Спостереження', 'reason' => 'Обмеження даних',
    'evidence_note' => 'Відсутні та неповні дані не вважаються нулем. Описовий звіт не є медичною чи фінансовою рекомендацією.',
    'states' => ['ready' => 'Доступно', 'empty' => 'Немає даних', 'incomplete' => 'Неповно'],
    'granularities' => ['daily' => 'День', 'weekly' => 'Тиждень', 'monthly' => 'Місяць'],
    'aggregations' => ['sum' => 'Сума', 'mean' => 'Середнє арифметичне', 'percentage' => 'Зважений відсоток', 'last' => 'Останнє спостереження'],
    'units' => ['percent' => 'Відсотки', 'minutes' => 'Хвилини', 'count' => 'Кількість', 'rating_5' => 'Оцінка з 5', 'rating_10' => 'Оцінка з 10', 'kilograms' => 'Кілограми'],
    'reasons' => ['missing_fx' => 'Немає курсу обміну для :currency', 'missing_evidence' => 'Немає даних'],
    'metrics' => [
        'routines' => ['completion_rate' => 'Виконання рутин'], 'sleep' => ['duration_minutes' => 'Тривалість сну', 'quality' => 'Якість сну'],
        'workouts' => ['completed_sessions' => 'Завершені тренування', 'duration_minutes' => 'Тривалість тренувань'],
        'nutrition' => ['calorie_target_adherence' => 'Дотримання цілі калорій'], 'supplements' => ['adherence' => 'Дотримання прийому добавок'],
        'habits' => ['completion_rate' => 'Виконання звичок'], 'planner' => ['completion_rate' => 'Виконання плану'],
        'finance' => ['income' => 'Дохід', 'expense' => 'Витрата', 'net' => 'Чистий грошовий потік'],
        'review' => ['energy' => 'Енергія', 'mood' => 'Настрій', 'stress' => 'Стрес', 'day_rating' => 'Оцінка дня'],
        'body' => ['body_mass' => 'Маса тіла'],
    ],
];
