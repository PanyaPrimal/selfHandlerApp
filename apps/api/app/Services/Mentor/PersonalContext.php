<?php

namespace App\Services\Mentor;

use App\Models\User;
use App\Services\Portability\PortabilitySchemaV1;
use App\ValueObjects\BodyMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Reuses the versioned, credential-free personal-data catalogue. */
class PersonalContext
{
    public function catalogue(): array
    {
        return array_map(fn ($definition): array => array_values(array_unique([
            'id', ...$definition['attributes'], ...array_keys($definition['references']),
        ])), PortabilitySchemaV1::tables());
    }

    public function read(User $user, array $arguments): array
    {
        $catalogue = $this->catalogue();
        $data = Validator::make($arguments, [
            'dataset' => ['required', Rule::in(array_keys($catalogue))],
            'query' => ['nullable', 'string', 'max:100'],
            'offset' => ['required', 'integer', 'min:0', 'max:10000'],
            'limit' => ['required', 'integer', 'min:1', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'date_field' => ['required_with:from,to', 'nullable', 'string'],
        ])->validate();
        $columns = $catalogue[$data['dataset']];
        $query = DB::table($data['dataset'])->where('user_id', $user->id);
        if ($data['query'] ?? '') {
            $searchable = array_intersect($columns, ['name', 'title', 'note', 'notes', 'description', 'went_well', 'improve_tomorrow']);
            if ($searchable === []) {
                return ['records' => [], 'reason' => 'This dataset has no searchable text field. Use IDs/dates.'];
            }
            $query->where(function ($query) use ($searchable, $data): void {
                foreach ($searchable as $column) {
                    $query->orWhere($column, 'like', '%'.$data['query'].'%');
                }
            });
        }
        if ($data['date_field'] ?? null) {
            Validator::make($data, ['date_field' => [Rule::in(array_values(array_filter($columns,
                fn ($column): bool => preg_match('/(_at|_on|_date|_month)$/', $column) === 1)))]])->validate();
            if ($data['from'] ?? null) {
                $query->where($data['date_field'], '>=', $data['from']);
            }
            if ($data['to'] ?? null) {
                $query->where($data['date_field'], '<', date('Y-m-d', strtotime($data['to'].' +1 day')));
            }
        }
        $count = (clone $query)->count();
        $records = $query->orderByDesc('id')->offset($data['offset'])->limit($data['limit'])->get($columns)
            ->map(fn ($row) => collect((array) $row)->map(fn ($value) => is_string($value) && mb_strlen($value) > 1200
                ? mb_substr($value, 0, 1200).' [truncated]' : $value)->all())->all();
        while (strlen(json_encode($records)) > 10000 && count($records) > 1) {
            array_pop($records);
        }

        return ['dataset' => $data['dataset'], 'matched' => $count, 'offset' => $data['offset'],
            'records' => $records, 'has_more' => $count > $data['offset'] + count($records)];
    }

    public function overview(User $user, string $memory): array
    {
        $profile = $user->ensureProfile();

        return [
            'name' => $user->name, 'today' => now($user->calendarTimezone())->toDateString(),
            'profile' => $profile->only(['timezone', 'locale', 'unit_system', 'base_currency', 'recommendation_tone',
                'date_of_birth', 'sex', 'height_meters', 'weight_grams', 'body_fat_percentage', 'baseline_activity']),
            'user_memory' => $memory,
            'modules' => ['planning' => 'Tasks, projects, goals, calendar blocks, routines and habits.',
                'health' => 'Sleep, food/recipes/meals, body measurements, supplements, workouts and training goals.',
                'finance' => 'Accounts, categories, ledger entries, budgets, debts, savings and planned payments. Amounts and currencies must not be guessed.',
                'reflection' => 'Daily, weekly and monthly reviews. Distinguish observations from recommendations.'],
            'datasets' => $this->catalogue(),
            'body_measurement_metrics' => BodyMetric::catalogue(),
            'units' => 'Body mass is stored in grams; lengths in metres; body fat is percent. Ledger amounts use account currency and decimal strings. Do not interpret canonical values as display units.',
            'freshness' => 'Server data only; unsynchronized device edits are not included.',
        ];
    }
}
