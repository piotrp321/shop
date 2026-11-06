<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Every authenticated request verifies an RS256 access token, and the functional tests sign one per request.
 * web-token converts the RSA JWK to PEM with brick/math before openssl runs; without the gmp extension that
 * conversion is pure PHP and costs about 0.2 s per verification and 0.6 s per signature instead of well under 1 ms.
 */
final class AccessTokenSignatureSpeedTest extends TestCase
{
    private const float LIMIT_SECONDS = 0.025;

    public function testAnRs256AccessTokenIsSignedAndVerifiedWithoutPurePhpBigIntegerMath(): void
    {
        $privateKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'speed-test']);
        $publicKey = $privateKey->toPublic();
        $algorithms = new AlgorithmManager([new RS256()]);

        $slowestSignature = 0.0;
        $slowestVerification = 0.0;
        for ($i = 0; $i < 3; ++$i) {
            $start = hrtime(true);
            $token = $this->sign($algorithms, $privateKey);
            $slowestSignature = max($slowestSignature, (hrtime(true) - $start) / 1e9);

            $start = hrtime(true);
            $verified = (new JWSVerifier($algorithms))->verifyWithKey((new CompactSerializer())->unserialize($token), $publicKey, 0);
            $slowestVerification = max($slowestVerification, (hrtime(true) - $start) / 1e9);

            self::assertTrue($verified);
        }

        $hint = ' (is the gmp extension loaded?)';
        self::assertLessThan(self::LIMIT_SECONDS, $slowestSignature, \sprintf('Signing took %.3f s%s.', $slowestSignature, $hint));
        self::assertLessThan(self::LIMIT_SECONDS, $slowestVerification, \sprintf('Verification took %.3f s%s.', $slowestVerification, $hint));
    }

    private function sign(AlgorithmManager $algorithms, JWK $key): string
    {
        $jws = (new JWSBuilder($algorithms))->create()
            ->withPayload('{"sub":"58276b79-2248-4817-8b28-5dbbb4f343ab"}')
            ->addSignature($key, ['alg' => 'RS256', 'kid' => 'speed-test'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }
}
