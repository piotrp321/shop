<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Routes that exist only in the test environment (see config/routes.yaml).
 */
#[AsController]
final class TestErrorController
{
    #[Route('/_test/runtime-error', methods: ['GET'])]
    public function runtimeError(): Response
    {
        throw new \RuntimeException('secret detail');
    }

    #[Route('/_test/unavailable', methods: ['GET'])]
    public function unavailable(): Response
    {
        throw new ServiceUnavailableHttpException(30, 'down for maintenance');
    }

    #[Route('/_test/invalid', methods: ['GET'])]
    public function invalid(): Response
    {
        throw new ValidationFailedException('input', new ConstraintViolationList([new ConstraintViolation('Name is required.', null, [], 'input', 'name', null)]));
    }
}
