<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads the JSON body of a discount code write into its request class and validates it, like #[MapRequestPayload]
 * with strict types (415 for another format, 400 for JSON that does not parse, 422 with the violations), but called
 * by the controller: so the change of a code checks that the code exists (404) before its body (spec „API”: 401,
 * 403, 404, 422, 409), and a missing key is a Polish violation at its field (MissingKey) instead of the framework's
 * English text of a missing constructor argument. The values are taken as JSON gives them, never cast; keys of no
 * field are ignored, and a JSON value that is no object has none of the keys.
 */
final readonly class DiscountCodeBodyReader
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @template T of CreateDiscountCodeRequest|ReviseDiscountCodeRequest
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function read(Request $request, string $class): object
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException();
        }
        $content = $request->getContent();
        try {
            $data = '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException();
        }
        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            $data = [];
        }

        $arguments = [];
        foreach (new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[$parameter->name] = \array_key_exists($parameter->name, $data) ? $data[$parameter->name] : new MissingKey();
        }
        $body = new $class(...$arguments);

        $violations = $this->validator->validate($body);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException(previous: new ValidationFailedException($body, $violations));
        }

        return $body;
    }
}
