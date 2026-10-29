<?php

declare(strict_types=1);

use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Signing key of the test access tokens, new for every run and never written to disk. The test kernel validates
// tokens with its public half (OIDC_TEST_JWKS, see config/packages/test/security.yaml); TestTokenFactory signs with
// the private half.
$testSigningKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-signing-key']);
$_SERVER['OIDC_TEST_JWKS'] = $_ENV['OIDC_TEST_JWKS'] = json_encode(new JWKSet([$testSigningKey->toPublic()]), \JSON_THROW_ON_ERROR);
$_SERVER['OIDC_TEST_PRIVATE_JWK'] = $_ENV['OIDC_TEST_PRIVATE_JWK'] = json_encode($testSigningKey, \JSON_THROW_ON_ERROR);
unset($testSigningKey);
