<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Push\WebPush;
use PHPUnit\Framework\TestCase;

/** Web Push encryption against the worked example in RFC 8291 §5, byte for byte. */
final class WebPushTest extends TestCase
{
    public function test_it_matches_the_rfc_8291_example(): void
    {
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            WebPush::unb64('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            WebPush::unb64('BTBZMqHH6r4Tts7J_aSIgg'),
            WebPush::unb64('DGv6ra1nlYgDCS1FRnbzlw'),
            [WebPush::unb64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), WebPush::unb64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')],
        );

        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', WebPush::b64($body));
    }

    public function test_generated_keys_have_the_right_sizes(): void
    {
        [$public, $private] = WebPush::generateKeys();
        $this->assertSame(65, strlen(WebPush::unb64($public)));
        $this->assertSame(32, strlen(WebPush::unb64($private)));
    }
}
