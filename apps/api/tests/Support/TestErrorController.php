<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\ProblemException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    #[Route('/_test/project-exception', methods: ['GET'])]
    public function projectException(): Response
    {
        throw new class('Product not found.') extends \RuntimeException implements NotFound {};
    }

    #[Route('/_test/project-conflict', methods: ['GET'])]
    public function projectConflict(): Response
    {
        throw new class('Slug is already taken.') extends \RuntimeException implements Conflict {};
    }

    #[Route('/_test/project-invalid', methods: ['GET'])]
    public function projectInvalid(): Response
    {
        throw new class('Price must be positive.') extends \RuntimeException implements InvalidInput {};
    }

    #[Route('/_test/project-bare', methods: ['GET'])]
    public function projectBare(): Response
    {
        throw new class('Something is wrong.') extends \RuntimeException implements ProblemException {};
    }

    #[Route('/_test/project-and-http', methods: ['GET'])]
    public function projectAndHttp(): Response
    {
        throw new class('Product not found.') extends \RuntimeException implements NotFound, HttpExceptionInterface {
            public function getStatusCode(): int
            {
                return 400;
            }

            /**
             * @return array<string, string>
             */
            public function getHeaders(): array
            {
                return ['X-Leak' => 'headers'];
            }
        };
    }

    #[Route('/_test/framework-exception', methods: ['GET'])]
    public function frameworkException(): Response
    {
        throw new BadRequestHttpException('internal secret');
    }

    #[Route('/_test/framework-wrapped-in-plain', methods: ['GET'])]
    public function frameworkWrappedInPlain(): Response
    {
        throw new \RuntimeException('wrapper', 0, new BadRequestHttpException('internal secret'));
    }

    #[Route('/_test/project-wrapped-in-framework', methods: ['GET'])]
    public function projectWrappedInFramework(): Response
    {
        $project = new class('Product not found.') extends \RuntimeException implements NotFound {};

        throw new BadRequestHttpException('internal secret', $project);
    }
}
