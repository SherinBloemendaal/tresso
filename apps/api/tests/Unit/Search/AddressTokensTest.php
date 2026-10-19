<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\AddressTokens;
use PHPUnit\Framework\TestCase;

final class AddressTokensTest extends TestCase
{
    public function testIndexesTheAddressItsPartsAndTheName(): void
    {
        self::assertSame(
            ['anna.devries@mail.finance.example', 'anna.devries', 'anna', 'devries', 'mail.finance.example', 'finance.example', 'jönsson'],
            AddressTokens::forAddress('Anna.DeVries@Mail.Finance.example', 'Jönsson'),
        );
    }

    public function testNameWordsAreSplitOnPunctuation(): void
    {
        self::assertSame(['kim@company.example', 'kim', 'company.example', 'visser', 'k'], AddressTokens::forAddress('kim@company.example', 'Visser, K.'));
    }

    public function testAQueryValueIsOneTokenOrTheWordsOfAName(): void
    {
        self::assertSame(['anna@finance.example'], AddressTokens::forQuery('Anna@Finance.example'));
        self::assertSame(['finance.example'], AddressTokens::forQuery('@finance.example'));
        self::assertSame(['anna'], AddressTokens::forQuery('Anna'));
        self::assertSame(['anna', 'de', 'vries'], AddressTokens::forQuery('Anna de Vries'));
        self::assertSame([], AddressTokens::forQuery('@'));
    }

    public function testEveryQueryTokenIsAnIndexedToken(): void
    {
        $indexed = AddressTokens::forAddress('anna@finance.example', 'Anna de Vries');

        foreach (['anna@finance.example', '@finance.example', 'anna', 'Anna de Vries', 'VRIES'] as $value) {
            foreach (AddressTokens::forQuery($value) as $token) {
                self::assertContains($token, $indexed, $value);
            }
        }
    }
}
