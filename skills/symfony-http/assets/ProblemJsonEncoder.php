<?php

declare(strict_types=1);

/*
 * Copy to src/Serializer/ProblemJsonEncoder.php and commit it.
 *
 * ── Why this class exists ───────────────────────────────────────────────────
 * Do not delete it because it looks like it does nothing. It does one thing,
 * and without it the API silently breaks its own contract.
 *
 * Symfony already produces RFC 7807 Problem Details bodies: ProblemNormalizer
 * turns a ValidationFailedException — what #[MapRequestPayload] throws — into
 * the right shape, with no code.
 *
 * The media type is the problem. A client asking correctly for
 * `Accept: application/problem+json` resolves the request format to `problem`.
 * No encoder in Symfony supports that format, so the serializer throws
 * UnsupportedFormatException, SerializerErrorRenderer catches it as a
 * NotEncodableValueException, and falls back to the HTML error renderer.
 *
 * The result: the client that asks properly for Problem Details receives an
 * HTML page, while the one asking for `application/json` gets the right body
 * under the wrong Content-Type. Registering this encoder fixes both.
 *
 * Autoconfiguration tags it. There is nothing else to wire.
 *
 * Verified against Symfony 8.1.
 */

namespace App\Serializer;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class ProblemJsonEncoder implements EncoderInterface
{
    public const FORMAT = 'problem';

    public function __construct(
        private readonly JsonEncoder $inner = new JsonEncoder(),
    ) {
    }

    public function supportsEncoding(string $format): bool
    {
        return self::FORMAT === $format;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function encode(mixed $data, string $format, array $context = []): string
    {
        return $this->inner->encode($data, JsonEncoder::FORMAT, $context);
    }
}
