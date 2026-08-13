<?php

namespace Tests\Feature\Supplements;

class SupplementLocalizationTest extends SupplementTestCase
{
    public function test_domain_validation_uses_the_requested_supported_locale(): void
    {
        $cases = [
            'en' => 'Choose an exact amount and a unit compatible with this stock.',
            'ru' => 'Укажите точное количество и единицу, совместимую с этим запасом.',
            'uk' => 'Укажіть точну кількість та одиницю, сумісну з цим запасом.',
        ];

        foreach ($cases as $locale => $message) {
            $owner = $this->createUser("{$locale}@example.test");
            $owner->ensureProfile()->update(['locale' => $locale === 'en' ? 'en-GB' : $locale.'-UA']);
            $this->actingAs($owner)->withHeader('Accept-Language', 'en-GB')
                ->postJson('/api/supplements', [
                    'name' => 'Invalid', 'category' => 'vitamin', 'form' => 'capsule',
                    'stock_unit' => 'piece', 'preferred_display_unit' => 'mg',
                    'usual_dose_quantity' => '1', 'package_quantity' => null,
                    'restock_lead_days' => 7, 'note' => null,
                ])->assertUnprocessable()
                ->assertJsonPath('errors.preferred_display_unit.0', $message);
        }
    }
}
