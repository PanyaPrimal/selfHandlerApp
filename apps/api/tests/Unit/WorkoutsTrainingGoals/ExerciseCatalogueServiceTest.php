<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Services\ExerciseCatalogueService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class ExerciseCatalogueServiceTest extends WorkoutTestCase
{
    public function test_catalogue_returns_builtins_and_only_current_users_custom_rows_in_stable_order(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $this->createCustomExercise($owner, ['name' => 'Z press']);
        $this->createCustomExercise($other, ['name' => 'Private curl']);

        $rows = app(ExerciseCatalogueService::class)->visible($owner, 'active');

        $this->assertCount(7, $rows);
        $this->assertTrue($rows->contains('system_key', 'squat'));
        $this->assertTrue($rows->contains('name', 'Z press'));
        $this->assertFalse($rows->contains('name', 'Private curl'));
    }

    public function test_builtins_are_immutable_and_archived_custom_exercises_remain_referenceable(): void
    {
        $owner = $this->createUser();
        $service = app(ExerciseCatalogueService::class);
        $builtIn = $this->builtInExercise();
        $custom = $this->createCustomExercise($owner);

        try {
            $service->update($builtIn, $owner, ['name' => 'Hijacked']);
            $this->fail('Built-in mutation must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('exercise', $exception->errors());
        }

        $updated = $service->update($custom, $owner, ['is_archived' => true]);
        $program = $this->createProgram($owner);
        $this->addPrescription($program, $updated);

        $this->assertTrue($updated->is_archived);
        $this->assertSame($updated->id, $program->fresh('exercises.exercise')->exercises->first()->exercise->id);
        $this->assertFalse($service->visible($owner, 'active')->contains('id', $updated->id));
        $this->assertTrue($service->visible($owner, 'archived')->contains('id', $updated->id));
    }

    public function test_foreign_custom_reference_is_never_accessible(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreign = $this->createCustomExercise($other);

        $this->expectException(ValidationException::class);
        app(ExerciseCatalogueService::class)->assertAccessible($foreign->id, $owner);
    }
}
