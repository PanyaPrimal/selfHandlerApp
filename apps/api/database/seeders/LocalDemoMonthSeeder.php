<?php

namespace Database\Seeders;

use App\Models\PlannedOccurrence;
use App\Models\RoutineActivity;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Explicit local-only fixture. Creates a NEW account atomically; never resets an account.
 * Domain writes pass through the application's authenticated HTTP kernel and validators.
 * SELFHANDLER_DEMO_PASSWORD is required; SELFHANDLER_DEMO_DATE optionally fixes the anchor.
 */
class LocalDemoMonthSeeder extends Seeder
{
    private string $token;

    private User $owner;

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    private int $requests = 0;

    public function run(): void
    {
        $connection = config('database.connections.'.config('database.default'));
        if (! app()->environment('local') || config('database.default') !== 'mysql'
            || $connection['host'] !== '127.0.0.1' || (string) $connection['port'] !== '13306'
            || $connection['database'] !== 'selfhandler_preview') {
            throw new RuntimeException('This fixture only runs against local selfhandler_preview on 127.0.0.1:13306.');
        }
        $password = getenv('SELFHANDLER_DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Provide SELFHANDLER_DEMO_PASSWORD (12+ characters).');
        }
        $email = 'demo@selfhandler.test';
        if (User::where('email', $email)->exists()) {
            throw new RuntimeException('The demo account already exists. Refusing to replace its data.');
        }
        $this->end = CarbonImmutable::parse(getenv('SELFHANDLER_DEMO_DATE') ?: 'today', 'Europe/Kyiv')->startOfDay();
        if ($this->end->isAfter(CarbonImmutable::now('Europe/Kyiv')->startOfDay())) {
            throw new RuntimeException('The demo anchor must not be in the future.');
        }
        $this->start = $this->end->startOfMonth()->subMonth();

        try {
            DB::transaction(function () use ($email, $password): void {
                $this->clock($this->start);
                $this->owner = User::create(['name' => 'Демо — Александр', 'email' => $email, 'password' => $password]);
                $this->owner->ensureProfile()->update([
                    'timezone' => 'Europe/Kyiv', 'locale' => 'ru-UA', 'unit_system' => 'metric',
                    'base_currency' => 'UAH', 'date_of_birth' => '1993-05-18', 'sex' => 'male',
                    'height_meters' => 1.80, 'weight_grams' => 82000, 'body_fat_percentage' => 21,
                    'baseline_activity' => 'moderate',
                ]);
                $this->owner->ensureNotificationSettings();
                $this->token = $this->owner->createToken('Local demo population', ['mobile'])->plainTextToken;
                $this->seedWorkspace();
                $this->owner->tokens()->where('name', 'Local demo population')->delete();
            });
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Auth::forgetGuards();
        }
        $counts = [];
        foreach (Schema::getTables() as $table) {
            if (Schema::hasColumn($table['name'], 'user_id')) {
                $count = DB::table($table['name'])->where('user_id', $this->owner->id)->count();
                if ($count) {
                    $counts[$table['name']] = $count;
                }
            }
        }
        $this->command?->line(json_encode([
            'email' => $email, 'user_id' => $this->owner->id,
            'from' => $this->start->toDateString(), 'to' => $this->end->toDateString(),
            'api_writes' => $this->requests, 'counts' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function clock(CarbonImmutable $day): void
    {
        // All daily facts are before this local time, including today's partial day.
        $time = $day->setTime(18, 0)->utc();
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    private function api(string $method, string $path, array $data = []): array
    {
        Auth::forgetGuards();
        $request = Request::create('/api'.$path, $method, [], [], [], [
            'HTTP_HOST' => '127.0.0.1:18112', 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], $method === 'GET' ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException($method.' '.$path.' '.$response->getStatusCode().' '.json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $this->requests++;

        return $body['data'] ?? $body;
    }

    private function seedWorkspace(): void
    {
        $from = $this->start->toDateString();
        $future = $this->end->addMonths(2)->toDateString();
        $goal = $this->api('POST', '/goals', [
            'name' => 'Спокойный и активный месяц', 'description' => 'Демонстрационный план: регулярный сон, движение и порядок в делах.',
            'target_date' => $future,
        ]);
        $bodyGoal = $this->api('POST', '/body/goals', [
            'name' => 'Отслеживать форму', 'metric' => 'body_mass', 'direction' => 'lose',
            'starting_value' => 82000, 'target_value' => 79000, 'target_date' => $future,
            'milestones' => [['target_value' => 80500, 'target_date' => $this->end->toDateString()], ['target_value' => 79000, 'target_date' => $future]],
        ]);
        $projects = [];
        foreach (['Рабочий проект', 'Дом и бытовые дела', 'Здоровье и обучение'] as $name) {
            $projects[] = $this->api('POST', '/storage/projects', ['name' => $name, 'description' => 'Демонстрационный проект с выполненными и открытыми задачами.']);
        }
        $routines = [];
        foreach ([['Утро без спешки', 'morning', '07:30', ['Стакан воды', 'Короткая разминка', 'Три главных дела']],
            ['Спокойный вечер', 'evening', '21:30', ['Подвести итоги', 'Подготовить вещи', 'Почитать книгу']]] as [$name, $period, $time, $names]) {
            $routine = $this->api('POST', '/routines', [
                'name' => $name, 'day_period' => $period, 'schedule_type' => 'daily', 'preferred_time' => $time, 'starts_on' => $from,
            ]);
            $this->api('PUT', '/routines/'.$routine['id'].'/activities', ['activities' => array_map(
                fn ($name, $index) => ['name' => $name, 'sort_order' => $index], $names, array_keys($names),
            )]);
            $this->api('POST', '/goals/'.$goal['id'].'/routines/'.$routine['id']);
            $routine['activities'] = RoutineActivity::where('routine_id', $routine['id'])->orderBy('sort_order')->get()->toArray();
            $routines[] = $routine;
        }
        $habits = [];
        foreach ([
            ['name' => 'Читать 20 минут', 'kind' => 'habit', 'mode' => 'yes_no', 'preferred_time' => '17:00'],
            ['name' => 'Прогулка и шаги', 'kind' => 'habit', 'mode' => 'numeric', 'target_value' => 8000, 'unit' => 'шагов', 'preferred_time' => '16:30'],
            ['name' => 'Без бесцельного скроллинга', 'kind' => 'anti_habit', 'mode' => 'abstinence', 'preferred_time' => '17:30'],
            ['name' => 'Практика английского', 'kind' => 'habit', 'mode' => 'yes_no', 'schedule_type' => 'weekly_target', 'weekly_target' => 3, 'preferred_time' => '12:00'],
        ] as $habit) {
            $habits[] = $this->api('POST', '/habits', ['schedule_type' => 'daily', 'starts_on' => $from, 'goal_id' => $goal['id'], ...$habit]);
        }
        $sleep = $this->api('POST', '/sleep/plans', [
            'name' => 'Основной сон', 'planned_bed_time' => '23:00', 'planned_wake_time' => '07:00',
            'schedule_type' => 'daily', 'starts_on' => $from,
        ]);
        $workouts = $this->workouts($from, $future);
        $nutrition = $this->nutrition($bodyGoal['id']);
        $supplements = $this->supplements($goal['id'], $from);
        $finance = $this->finance($from, $future);

        for ($day = $this->start, $i = 0; $day->lte($this->end); $day = $day->addDay(), $i++) {
            $this->clock($day);
            $date = $day->toDateString();
            $today = $day->equalTo($this->end);
            $this->api('PUT', '/routine-selections/'.$date, [
                'morning_routine_id' => $routines[0]['id'], 'evening_routine_id' => $routines[1]['id'],
            ]);
            foreach ($routines as $r => $routine) {
                if ($today && $r === 1) {
                    continue;
                }
                foreach ($routine['activities'] as $a => $activity) {
                    $this->api('PUT', '/routines/'.$routine['id'].'/activities/'.$activity['id'].'/logs/'.$date, [
                        'status' => ($i + $a) % 11 === 0 ? 'skipped' : 'done',
                    ]);
                }
            }
            foreach ($habits as $h => $habit) {
                if (($h === 3 && ! in_array($day->isoWeekday(), [1, 3, 5], true)) || ($today && $h > 1)) {
                    continue;
                }
                $data = match ($h) {
                    1 => ['outcome' => 'recorded', 'value' => 6100 + (($i * 431) % 5200)],
                    2 => ['outcome' => $i % 8 === 0 ? 'relapse' : 'protected'],
                    default => ['outcome' => $i % 7 === 0 ? 'not_done' : 'done'],
                };
                $this->api('PUT', '/habits/'.$habit['id'].'/logs/'.$date, [...$data, 'occurred_time' => '17:00']);
            }
            if (! $today) {
                $this->clock($day->addDay());
                $this->api('PUT', '/sleep/plans/'.$sleep['id'].'/logs/'.$date, [
                    'actual_bed_date' => $date, 'actual_bed_time' => '23:'.str_pad((string) (($i % 4) * 10), 2, '0', STR_PAD_LEFT),
                    'actual_wake_date' => $day->addDay()->toDateString(), 'actual_wake_time' => '07:'.str_pad((string) (($i % 3) * 10), 2, '0', STR_PAD_LEFT),
                    'quality' => 6 + ($i % 4), 'note' => $i % 7 === 0 ? 'Заснул позже обычного' : 'Спокойный сон',
                ]);
                $this->clock($day);
            }
            $this->api('PUT', '/body/measurements', [
                'metric' => 'body_mass', 'measured_on' => $date, 'value' => 82000 - $i * 45 + ($i % 3) * 70,
                'note' => 'Утреннее измерение — демонстрационные данные',
            ]);
            if ($i % 7 === 0) {
                $this->api('PUT', '/body/measurements', ['metric' => 'waist', 'measured_on' => $date, 'value' => 0.91 - $i * 0.0004]);
            }
            $this->dailyMeals($nutrition, $date, $i, $today);
            // Daily target snapshots also power historical nutrition analytics.
            $this->api('GET', '/nutrition/days/'.$date);
            $this->dailyWorkout($workouts, $day, $i, $today);
            foreach ($supplements as $s => $course) {
                if ($today && $s === 1) {
                    continue;
                }
                $occurrence = PlannedOccurrence::where('user_id', $this->owner->id)
                    ->whereHas('recurringRule', fn ($query) => $query->where('owner_type', 'supplement_course')->where('owner_id', $course['id']))
                    ->where('occurrence_date', $date)->firstOrFail();
                $skipped = ($i + $s) % 10 === 0;
                $this->api('PUT', '/supplement-occurrences/'.$occurrence->id.'/intake', [
                    'outcome' => $skipped ? 'skipped' : 'taken', 'dose_quantity' => null, 'dose_display_unit' => null,
                    'taken_time' => $skipped ? null : '08:30', 'note' => $skipped ? 'Пропуск в демонстрационном сценарии' : null,
                ]);
            }
            $this->api('POST', '/finance/transactions', [
                'idempotency_key' => 'demo-daily-food-'.$date, 'kind' => 'expense',
                'account_id' => $finance['card']['id'], 'category_id' => $finance['food']['id'],
                'amount' => (string) (220 + ($i * 37) % 350), 'occurred_on' => $date, 'note' => 'Продукты и обед', 'tag' => 'демо',
            ]);
            if ($day->isWeekday()) {
                $this->api('POST', '/planner/time-blocks', [
                    'title' => 'Сосредоточенная работа', 'block_date' => $date, 'starts_at' => '09:30', 'ends_at' => '11:30',
                    'note' => 'Один приоритет без переключений',
                ]);
            }
            $this->api('POST', '/storage/items', [
                'title' => ['Подготовить короткий отчёт', 'Разобрать документы', 'Повторить урок английского', 'Запланировать покупки', 'Закончить небольшой этап проекта'][$i % 5].' · '.$day->format('d.m'),
                'type' => 'task', 'status' => $today ? 'active' : ($i % 8 === 0 ? 'active' : 'done'),
                'priority' => $i % 6 === 0 ? 'high' : 'normal', 'due_on' => $date,
                'project_id' => $projects[$i % 3]['id'], 'tags' => [$i % 2 === 0 ? 'фокус' : 'личное'],
            ]);
            if (! $today) {
                $this->api('PUT', '/daily-reviews/'.$date, [
                    'mood' => 6 + ($i % 4), 'energy' => 6 + (($i + 1) % 4), 'stress' => 2 + $i % 4, 'day_rating' => 6 + $i % 4,
                    'went_well' => ['Сохранил фокус на главной задаче', 'Нашёл время для прогулки', 'Закрыл дела без спешки'][$i % 3],
                    'improve_tomorrow' => ['Начать день без телефона', 'Оставить больше времени на отдых', 'Сделать перерыв после обеда'][$i % 3],
                    'notes' => 'Синтетический пример дневного обзора.',
                ]);
            }
            if ($day->isSunday()) {
                $this->api('PUT', '/periodic-reviews/weekly/'.$date, [
                    'period_rating' => 7 + $i % 3, 'worked_well' => 'Регулярный сон и утренний план помогали держать ритм.',
                    'did_not_work' => 'Некоторые небольшие задачи откладывались.', 'learned' => 'Реалистичный план легче выполнить.',
                    'next_focus' => 'Сохранить время для движения и завершить один важный этап.',
                ]);
            }
            if ($i % 7 === 0) {
                $this->command?->line('Prepared '.$date);
            }
        }
        $this->clock($this->end);
        $this->finishFinance($finance);
        $this->api('PUT', '/periodic-reviews/monthly/'.$from, [
            'period_rating' => 8, 'worked_well' => 'Появился понятный ритм: сон, питание, тренировки и регулярный обзор.',
            'did_not_work' => 'Бюджет развлечений местами превышен, есть несколько просроченных задач.',
            'learned' => 'Лучше оставлять запас времени и денег.', 'next_focus' => 'Закончить рабочий этап и увеличить резерв.',
            'notes' => 'Демонстрационный месяц для просмотра всех разделов приложения.',
        ]);
        foreach ([
            ['Идея: короткое путешествие на выходные', 'idea', 'inbox'],
            ['Разобраться с домашним освещением', 'idea', 'inbox'],
            ['Купить новые кроссовки', 'purchase', 'active'],
            ['Обновить рабочее кресло', 'purchase', 'active'],
            ['Вернуться к отложенному курсу', 'task', 'dropped'],
        ] as [$title, $type, $status]) {
            $this->api('POST', '/storage/items', [
                'title' => $title, 'type' => $type, 'status' => $status, 'project_id' => $projects[1]['id'],
                ...($type === 'purchase' ? ['estimated_amount' => '3500', 'estimated_currency_code' => 'UAH'] : []),
            ]);
        }
        $parent = $this->api('POST', '/storage/items', ['title' => 'Подготовить демо проекта', 'status' => 'active', 'priority' => 'high', 'project_id' => $projects[0]['id'], 'due_on' => $this->end->addDays(3)->toDateString()]);
        foreach (['Собрать примеры', 'Проверить основные сценарии', 'Записать обратную связь'] as $i => $title) {
            $this->api('POST', '/storage/items', ['title' => $title, 'parent_id' => $parent['id'], 'project_id' => $projects[0]['id'], 'status' => $i === 0 ? 'done' : 'active']);
        }
        for ($i = 0; $i <= 7; $i++) {
            $date = $this->end->addDays($i)->toDateString();
            $this->api('POST', '/planner/time-blocks', [
                'title' => $i % 2 === 0 ? 'Прогулка и личное время' : 'Работа над личным проектом',
                'block_date' => $date, 'starts_at' => '18:30', 'ends_at' => '19:30',
            ]);
        }
        $this->api('PUT', '/mentor/settings', [
            'enabled' => false, 'auth_mode' => 'chatgpt', 'memory' => 'Демонстрационный аккаунт. Примеры данных синтетические; цель — исследовать интерфейс.',
            'monthly_token_limit' => 500000,
        ]);
        $this->seedMentorExamples($this->owner);
    }

    public function seedMentorExamples(User $owner): void
    {
        if (! app()->environment('local') || config('database.connections.mysql.database') !== 'selfhandler_preview'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || (string) config('database.connections.mysql.port') !== '13306'
            || $owner->email !== 'demo@selfhandler.test') {
            throw new RuntimeException('Mentor examples are restricted to the local demo account.');
        }
        foreach ([
            ['2026-10-08', 'Демо: как посмотреть итоги недели?', 'Демонстрационный ответ, заранее записанный для просмотра интерфейса. Откройте недельный обзор: там собраны привычки, сон, тренировки, питание и расходы.'],
            ['2026-10-19', 'Демо: что находится в финансовом разделе?', 'Демонстрационный ответ. В тестовых данных есть карта, наличные, сбережения, частично погашенный долг и накопление на поездку. Можно посмотреть бюджет и историю операций.'],
            ['2026-11-02', 'Демо: как начать работу с приложением?', 'Демонстрационный ответ. На главной оставлены незавершённые действия, в задачах есть проекты и покупки, а в аналитике — история с 1 октября. Эти сообщения не получены от подключённой модели.'],
        ] as [$date, $question, $answer]) {
            if (! DB::table('mentor_turns')->where('user_id', $owner->id)->where('question', $question)->exists()) {
                DB::table('mentor_turns')->insert([
                    'user_id' => $owner->id, 'operation_id' => (string) Str::uuid(), 'status' => 'completed',
                    'provider' => 'demo', 'model' => 'demo-example', 'question' => $question, 'answer' => $answer,
                    'sources' => '[]', 'actions' => '[]', 'estimated_usd' => 0,
                    'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00',
                ]);
            }
        }
    }

    private function workouts(string $from, string $future): array
    {
        $exercises = [];
        foreach ([['Приседания', 'legs'], ['Жим гантелей', 'chest'], ['Тяга гантели', 'back']] as [$name, $muscle]) {
            $exercises[] = $this->api('POST', '/exercises', ['name' => $name, 'muscle_group' => $muscle, 'equipment' => 'Гантели', 'exercise_type' => 'strength']);
        }
        $strength = $this->api('POST', '/workout-programs', [
            'name' => 'Силовая тренировка всего тела', 'workout_type' => 'strength', 'intensity' => 'moderate',
            'planned_duration_seconds' => 2700, 'schedule_type' => 'weekdays', 'weekdays' => ['MO', 'WE', 'FR'],
            'preferred_time' => '17:00', 'starts_on' => $from,
        ]);
        $this->api('PUT', '/workout-programs/'.$strength['id'].'/exercises', ['exercises' => array_map(
            fn ($exercise, $i) => ['exercise_id' => $exercise['id'], 'sort_order' => $i, 'target_sets' => 3, 'target_reps' => 10, 'starting_weight_kg' => 10 + 5 * $i, 'increment_kg' => 1, 'successes_required' => 3], $exercises, array_keys($exercises),
        )]);
        $run = $this->api('POST', '/workout-programs', [
            'name' => 'Лёгкий бег', 'workout_type' => 'cardio', 'intensity' => 'light', 'planned_duration_seconds' => 1800,
            'schedule_type' => 'weekdays', 'weekdays' => ['TU', 'SA'], 'preferred_time' => '08:00', 'starts_on' => $from,
            'endurance' => ['activity' => 'running', 'run_type' => 'easy', 'target_distance_m' => 5000],
        ]);
        $this->api('POST', '/training/goals', ['name' => 'Пробежать 8 километров', 'kind' => 'distance', 'activity' => 'running', 'target_value' => 8000, 'target_date' => $future]);
        $this->api('POST', '/training/goals', ['name' => 'Тренироваться регулярно', 'kind' => 'consistency', 'workout_program_id' => $strength['id'], 'target_value' => 20, 'target_date' => $future]);

        return compact('exercises', 'strength', 'run');
    }

    private function dailyWorkout(array $workouts, CarbonImmutable $day, int $i, bool $today): void
    {
        if ($today) {
            return;
        }
        $date = $day->toDateString();
        if (in_array($day->isoWeekday(), [1, 3, 5], true)) {
            $skipped = $i % 13 === 0;
            $this->api('PUT', '/workout-programs/'.$workouts['strength']['id'].'/sessions/'.$date, [
                'outcome' => $skipped ? 'skipped' : 'completed',
                ...($skipped ? ['note' => 'День восстановления'] : [
                    'started_time' => '16:00', 'duration_seconds' => 2500 + $i * 10,
                    'strength' => ['mode' => 'detailed', 'exercises' => array_map(
                        fn ($exercise, $e) => ['exercise_id' => $exercise['id'], 'sort_order' => $e, 'sets' => array_map(
                            fn ($set) => ['set_order' => $e * 3 + $set, 'weight_kg' => 10 + 5 * $e + intdiv($i, 10), 'reps' => 10, 'rest_seconds' => 60], [0, 1, 2],
                        )], $workouts['exercises'], array_keys($workouts['exercises']),
                    )],
                ]),
            ]);
        } elseif (in_array($day->isoWeekday(), [2, 6], true)) {
            $this->api('PUT', '/workout-programs/'.$workouts['run']['id'].'/sessions/'.$date, [
                'outcome' => 'completed', 'started_time' => '08:00', 'duration_seconds' => 1700 + $i * 8,
                'endurance' => ['activity' => 'running', 'run_type' => 'easy', 'distance_m' => 4200 + $i * 40, 'average_heart_rate' => 135 + $i % 8, 'energy_kcal' => 350 + $i * 2],
            ]);
        } else {
            $this->api('POST', '/workouts', ['name' => 'Мягкая растяжка', 'workout_type' => 'flexibility', 'performed_on' => $date, 'started_time' => '17:00', 'duration_seconds' => 900, 'timed' => ['activity_name' => 'Мобилизация и растяжка']]);
        }
    }

    private function nutrition(int $bodyGoal): array
    {
        $foods = [];
        foreach ([
            ['Овсяные хлопья', 370, 13, 7, 62], ['Банан', 89, 1.1, 0.3, 23], ['Йогурт натуральный', 62, 5, 3, 4],
            ['Курица запечённая', 165, 31, 3.6, 0], ['Рис готовый', 130, 2.7, 0.3, 28], ['Овощной салат', 35, 1.5, 0.3, 6],
            ['Рыба запечённая', 190, 24, 10, 0], ['Гречка готовая', 110, 4, 1, 21], ['Творог', 120, 18, 5, 3],
            ['Яблоко', 52, 0.3, 0.2, 14], ['Орехи', 620, 20, 54, 16],
        ] as [$name, $kcal, $protein, $fat, $carbs]) {
            $foods[] = $this->api('POST', '/nutrition/foods', [
                'name' => $name, 'basis_unit' => 'gram', 'is_beverage' => false, 'calories_per_100' => $kcal,
                'protein_per_100' => $protein, 'fat_per_100' => $fat, 'carbs_per_100' => $carbs,
                'quality_score' => 80, 'hydration_ratio' => 0,
            ]);
        }
        $water = $this->api('POST', '/nutrition/foods', ['name' => 'Питьевая вода', 'basis_unit' => 'millilitre', 'is_beverage' => true, 'calories_per_100' => 0, 'protein_per_100' => 0, 'fat_per_100' => 0, 'carbs_per_100' => 0, 'quality_score' => null, 'hydration_ratio' => 1]);
        $recipe = $this->api('POST', '/nutrition/recipes', [
            'name' => 'Овсянка с бананом и йогуртом', 'description' => 'Демонстрационный рецепт завтрака.',
            'components' => [
                ['food_item_id' => $foods[0]['id'], 'quantity_grams' => 70],
                ['food_item_id' => $foods[1]['id'], 'quantity_grams' => 120],
                ['food_item_id' => $foods[2]['id'], 'quantity_grams' => 150],
            ],
        ]);
        $this->api('PUT', '/nutrition/settings', ['body_goal_id' => $bodyGoal, 'protein_percent' => 25, 'fat_percent' => 30, 'carbs_percent' => 45, 'water_override_ml' => 2300]);

        return compact('foods', 'water', 'recipe');
    }

    private function dailyMeals(array $nutrition, string $date, int $i, bool $today): void
    {
        $f = $nutrition['foods'];
        $entry = fn ($index, $grams) => ['food_item_id' => $f[$index]['id'], 'recipe_id' => null, 'quantity' => $grams];
        $meals = [
            ['Завтрак', 'breakfast', '08:15', [['food_item_id' => null, 'recipe_id' => $nutrition['recipe']['id'], 'quantity' => 320 + $i % 4 * 10]]],
            ['Обед', 'lunch', '13:00', [$entry(3, 170), $entry(4, 220), $entry(5, 180)]],
            ['Полдник', 'snack', '16:00', [$entry(9, 180), $entry(10, 30)]],
            ['Ужин', 'dinner', '17:30', [$entry($i % 2 === 0 ? 6 : 8, 200), $entry(7, 210), $entry(5, 160)]],
            ['Вода за день', 'custom', '17:40', [['food_item_id' => $nutrition['water']['id'], 'recipe_id' => null, 'quantity' => 1700 + $i % 5 * 150]]],
        ];
        foreach ($meals as $m => [$name, $category, $time, $entries]) {
            if ($today && $m === 3) {
                continue;
            }
            $this->api('POST', '/nutrition/meals', [
                'consumed_on' => $date, 'name' => $name, 'category' => $category, 'consumed_at_local' => $time,
                'note' => null, 'submission_key' => (string) Str::uuid(), 'entries' => $entries,
            ]);
        }
    }

    private function supplements(int $goal, string $from): array
    {
        $courses = [];
        foreach (['Демо-комплекс A', 'Демо-комплекс B'] as $i => $name) {
            $supplement = $this->api('POST', '/supplements', [
                'name' => $name, 'category' => 'other', 'form' => 'capsule', 'stock_unit' => 'piece',
                'preferred_display_unit' => 'piece', 'usual_dose_quantity' => '1', 'package_quantity' => '60',
                'restock_lead_days' => 7, 'note' => 'Вымышленный продукт для тестирования интерфейса.',
            ]);
            $this->api('POST', '/supplements/'.$supplement['id'].'/stock-movements', ['kind' => 'restock', 'quantity' => $i === 0 ? '60' : '35', 'display_unit' => 'piece', 'effective_on' => $from, 'reason' => null, 'note' => 'Начальный демонстрационный запас']);
            $courses[] = $this->api('POST', '/supplement-courses', [
                'supplement_id' => $supplement['id'], 'goal_id' => $goal, 'name' => 'Пример курса '.($i + 1),
                'dose_quantity' => '1', 'dose_display_unit' => 'piece', 'starts_on' => $from, 'duration_days' => 90, 'is_active' => true,
                'schedule' => ['frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
                    'slots' => [['slot' => 'morning', 'time' => '08:30', 'intake_context' => 'with_food']]],
            ]);
        }

        return $courses;
    }

    private function finance(string $from, string $future): array
    {
        $card = $this->api('POST', '/finance/accounts', ['name' => 'Основная карта', 'type' => 'card', 'currency' => 'UAH', 'opening_balance' => '45000', 'opening_date' => $from]);
        $cash = $this->api('POST', '/finance/accounts', ['name' => 'Наличные', 'type' => 'cash', 'currency' => 'UAH', 'opening_balance' => '3000', 'opening_date' => $from]);
        $saving = $this->api('POST', '/finance/accounts', ['name' => 'Сбережения', 'type' => 'savings', 'currency' => 'UAH', 'opening_balance' => '10000', 'opening_date' => $from]);
        $categories = [];
        foreach ([['Продукты', 'expense'], ['Дом и связь', 'expense'], ['Отдых', 'expense'], ['Транспорт', 'expense'], ['Доход от работы', 'income'], ['Возврат долга', 'expense']] as [$name, $direction]) {
            $categories[] = $this->api('POST', '/finance/categories', ['name' => $name, 'direction' => $direction]);
        }
        [$food, $home, $fun, $transport, $income, $repayment] = $categories;
        foreach (array_unique([$this->start->format('Y-m'), $this->end->format('Y-m')]) as $month) {
            foreach ([[$food, '14000'], [$home, '6000'], [$fun, '2500'], [$transport, '2000']] as [$category, $limit]) {
                $this->api('POST', '/finance/budgets', ['month' => $month, 'category_id' => $category['id'], 'limit_amount' => $limit, 'currency' => 'UAH']);
            }
        }
        $this->api('POST', '/finance/recurring-operations', [
            'name' => 'Интернет и связь', 'direction' => 'expense', 'account_id' => $card['id'], 'category_id' => $home['id'],
            'amount' => '650', 'mandatory' => true, 'starts_on' => $from, 'ends_on' => null,
            'interval_months' => 1, 'month_days' => [5], 'reminder_time' => '10:00',
        ]);
        $counterparty = $this->api('POST', '/finance/counterparties', ['name' => 'Демо: Алексей', 'kind' => 'person', 'note' => 'Вымышленный контакт']);
        $debt = $this->api('POST', '/finance/debts', [
            'name' => 'Возврат за совместную поездку', 'counterparty_id' => $counterparty['id'], 'direction' => 'owe', 'repayment_mode' => 'flexible',
            'original_amount' => '6000', 'currency' => 'UAH', 'originated_on' => $from, 'deadline' => $future,
            'account_id' => $card['id'], 'category_id' => $repayment['id'], 'purchase_item_id' => null, 'schedule' => null, 'note' => 'Учебный пример долга',
        ]);
        $fund = $this->api('POST', '/finance/saving-funds', [
            'name' => 'Поездка в горы', 'fund_type' => 'regular', 'storage_mode' => 'virtual', 'account_id' => $saving['id'],
            'funding_account_id' => null, 'category_id' => null, 'currency' => 'UAH', 'target_mode' => 'explicit',
            'target_amount' => '20000', 'deadline' => $future, 'note' => 'Учебный пример накопления',
            'rule' => ['top_up_mode' => 'none', 'fixed_amount' => null, 'income_percent' => null, 'expense_months' => null, 'build_months' => null, 'starts_on' => null, 'monthday' => null, 'reminder_time' => null],
        ]);
        $this->api('POST', '/finance/goals', ['name' => 'Собрать бюджет поездки', 'description' => 'Демонстрационная финансовая цель', 'target_date' => $future, 'kind' => 'save', 'saving_fund_id' => $fund['id'], 'debt_id' => null, 'milestones' => [['target_value' => '10000', 'target_date' => $this->end->addMonth()->toDateString()]]]);

        return compact('card', 'cash', 'saving', 'food', 'home', 'fun', 'transport', 'income', 'repayment', 'debt', 'fund');
    }

    private function finishFinance(array $finance): void
    {
        foreach ([2, 16] as $offset) {
            $this->api('POST', '/finance/transactions', ['idempotency_key' => 'demo-salary-'.$offset, 'kind' => 'income', 'account_id' => $finance['card']['id'], 'category_id' => $finance['income']['id'], 'amount' => '23000', 'occurred_on' => $this->start->addDays($offset)->toDateString(), 'note' => 'Демонстрационный доход']);
        }
        foreach ([4, 11, 18, 25] as $i => $offset) {
            $date = $this->start->addDays($offset)->toDateString();
            foreach ([['fun', 700 + $i * 150, 'Кино, кафе и встречи'], ['transport', 350, 'Городской транспорт']] as [$category, $amount, $note]) {
                $this->api('POST', '/finance/transactions', ['idempotency_key' => 'demo-'.$category.'-'.$offset, 'kind' => 'expense', 'account_id' => $finance['card']['id'], 'category_id' => $finance[$category]['id'], 'amount' => (string) $amount, 'occurred_on' => $date, 'note' => $note]);
            }
            $this->api('POST', '/finance/saving-funds/'.$finance['fund']['id'].'/movements', [
                'action' => 'top_up', 'amount' => '1000', 'counterparty_account_id' => null,
                'occurred_on' => $date, 'idempotency_key' => 'demo-saving-'.$offset, 'note' => 'Пополнение резерва',
            ]);
        }
        $this->api('POST', '/finance/debts/'.$finance['debt']['id'].'/payments', [
            'planned_occurrence_id' => null, 'amount' => '2000', 'account_id' => $finance['card']['id'], 'category_id' => $finance['repayment']['id'],
            'occurred_on' => $this->start->addDays(20)->toDateString(), 'idempotency_key' => 'demo-debt-partial-payment', 'note' => 'Частичный возврат',
        ]);
        $this->api('POST', '/finance/transfers', [
            'idempotency_key' => 'demo-transfer-to-savings', 'source_account_id' => $finance['card']['id'], 'destination_account_id' => $finance['saving']['id'],
            'source_amount' => '5000', 'destination_amount' => '5000', 'occurred_on' => $this->start->addDays(17)->toDateString(), 'note' => 'Перевод на накопления',
        ]);
    }
}
