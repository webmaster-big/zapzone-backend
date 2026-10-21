<?php

namespace Tests\Unit;

use App\Models\WaiverProfile;
use App\Services\EventRegistrationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EventRegistrationKeysTest extends TestCase
{
    public static function names(): array
    {
        return [
            'apostrophe' => ["Sean O'Brien", 'seanobrien'],
            'spacing and case' => ['  SEAN   obrien ', 'seanobrien'],
            'accents kept' => ['Élodie Durand', 'élodiedurand'],
            'hyphen' => ['Mary-Kate Olsen', 'marykateolsen'],
            'digits kept' => ['Jane Doe 2', 'janedoe2'],
            'non latin' => ['李 小龙', '李小龙'],
        ];
    }

    #[DataProvider('names')]
    public function test_name_keys_ignore_case_spacing_and_punctuation(string $name, string $expected): void
    {
        $this->assertSame($expected, EventRegistrationService::nameKey($name));
    }

    public static function emails(): array
    {
        return [
            'case and spaces' => ['  Ana.Diaz@Example.COM ', 'ana.diaz@example.com'],
            'trailing punctuation' => ['ana.diaz@example.com.', 'ana.diaz@example.com'],
            'invisible characters' => ["ana\u{200B}.diaz@example.com", 'ana.diaz@example.com'],
            'plus addressing kept' => ['ana+halloween@example.com', 'ana+halloween@example.com'],
            'gmail dots kept' => ['a.n.a@gmail.com', 'a.n.a@gmail.com'],
        ];
    }

    #[DataProvider('emails')]
    public function test_email_keys_are_lowercased_and_scrubbed_but_never_folded(string $email, string $expected): void
    {
        $this->assertSame($expected, EventRegistrationService::emailKey($email));
    }

    public static function phones(): array
    {
        return [
            'brackets' => ['(734) 555-0142', '+17345550142'],
            'dots' => ['734.555.0142', '+17345550142'],
            'leading one' => ['1 734 555 0142', '+17345550142'],
            'plus one' => ['+1 (734) 555-0142', '+17345550142'],
            'extension dropped' => ['734-555-0142 x22', '+17345550142'],
            'foreign with separated code' => ['+47 922 33 445', '+4792233445'],
            'foreign' => ['+44 20 7946 0958', '+442079460958'],
            'too short' => ['555-0142', null],
            'ambiguous foreign' => ['+4792233445', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_keys_match_every_spelling_of_one_number(string $typed, ?string $expected): void
    {
        $this->assertSame($expected, EventRegistrationService::phoneKey(WaiverProfile::storedPhoneFor($typed)));
    }
}
