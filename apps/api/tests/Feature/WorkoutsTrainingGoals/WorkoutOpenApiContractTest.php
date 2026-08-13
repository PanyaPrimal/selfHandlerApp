<?php

namespace Tests\Feature\WorkoutsTrainingGoals;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

class WorkoutOpenApiContractTest extends WorkoutTestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/015-workouts-training-goals/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_contract_is_openapi_31_with_fifteen_unique_authenticated_operations(): void
    {
        $document = $this->document();
        $ids = [];
        foreach ($document['paths'] as $operations) {
            foreach ($operations as $method => $operation) {
                if ($method !== 'parameters') {
                    $ids[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(10, $document['paths']);
        $this->assertCount(15, $ids);
        $this->assertCount(15, array_unique($ids));
        $this->assertSame([['sanctum' => []]], $document['security']);
    }

    public function test_every_local_reference_resolves(): void
    {
        $document = $this->document();
        $references = [];
        $walk = function (mixed $value) use (&$walk, &$references): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === '$ref') {
                    $references[] = $child;
                }
                $walk($child);
            }
        };
        $walk($document);

        foreach ($references as $reference) {
            $this->assertStringStartsWith('#/', $reference);
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
        $this->assertGreaterThan(200, count($references));
    }

    public function test_every_mutation_and_domain_object_schema_is_closed(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach ([
            'CreateExerciseRequest', 'UpdateExerciseRequest', 'CreateWorkoutProgramRequest',
            'UpdateWorkoutProgramRequest', 'ReplaceProgramExercisesRequest', 'ProgramExerciseWrite',
            'ProgramEnduranceWrite', 'ProgramTimedWrite', 'PlannedWorkoutSessionRequest',
            'CreateManualWorkoutSessionRequest', 'UpdateWorkoutSessionRequest', 'StrengthSessionWrite',
            'SessionExerciseWrite', 'WorkoutSetWrite', 'EnduranceSessionWrite', 'TimedSessionWrite',
            'CreateTrainingGoalRequest', 'UpdateTrainingGoalRequest', 'Exercise', 'WorkoutProgram',
            'WorkoutSession', 'TrainingGoal', 'WorkoutSummary', 'WorkoutRecords', 'Progression',
        ] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], "{$name} must be closed.");
        }
    }

    public function test_documented_and_registered_feature_operations_match_exactly(): void
    {
        $documented = [];
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if ($method !== 'parameters') {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }

        $patterns = ['#^api/exercises(?:/|$)#', '#^api/workout-programs(?:/|$)#',
            '#^api/workouts(?:/|$)#', '#^api/training/goals(?:/|$)#'];
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! collect($patterns)->contains(fn (string $pattern): bool => preg_match($pattern, $route->uri()) === 1)) {
                continue;
            }
            $registered[] = strtoupper($route->methods()[0]).' /'.$route->uri();
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }

        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }

    public function test_existing_contracts_document_workout_summary_planner_source_and_notification_category(): void
    {
        $core = Yaml::parseFile(base_path('../../specs/001-core-daily-loop/contracts/openapi.yaml'));
        $planner = Yaml::parseFile(base_path('../../specs/009-planner-day/contracts/openapi.yaml'));
        $notifications = Yaml::parseFile(base_path('../../specs/011-in-app-notifications/contracts/openapi.yaml'));

        $this->assertArrayHasKey(
            'workouts',
            $core['components']['schemas']['TodayResponse']['properties']['module_summaries']['properties'],
        );
        $this->assertContains('workout', $planner['components']['schemas']['PlannerSource']['enum']);
        $this->assertContains('training_goal', $planner['components']['schemas']['PlannerSource']['enum']);
        $this->assertArrayHasKey(
            'workout',
            $notifications['components']['schemas']['NotificationSettings']['properties']['categories']['properties'],
        );
    }
}
