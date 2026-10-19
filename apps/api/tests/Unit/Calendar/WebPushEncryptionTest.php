<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Notification\Channel\WebPushEncryption as E;
use PHPUnit\Framework\TestCase;

/**
 * Checked against the example in RFC 8291 section 5.
 */
final class WebPushEncryptionTest extends TestCase
{
    public function testMatchesTheRfcExample(): void
    {
        $plaintext = 'When I grow up, I want to be a watermelon';
        $serverPublic = E::base64UrlDecode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $serverPrivate = E::base64UrlDecode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
        $userPublic = E::base64UrlDecode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
        $auth = E::base64UrlDecode('BTBZMqHH6r4Tts7J_aSIgg');
        $salt = E::base64UrlDecode('DGv6ra1nlYgDCS1FRnbzlw');

        $message = E::encrypt($plaintext, $userPublic, $auth, E::privateKeyPem($serverPrivate, $serverPublic), $salt);

        self::assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            E::base64UrlEncode($message),
        );
    }
}
