<?php

declare(strict_types=1);

/*
 * Copier vers src/Serializer/ProblemJsonEncoder.php et le committer.
 *
 * ── Pourquoi cette classe existe ────────────────────────────────────────────
 * Ne la supprime pas parce qu'elle a l'air de ne rien faire. Elle fait une
 * seule chose, et sans elle l'API rompt silencieusement son propre contrat.
 *
 * Symfony produit déjà des corps Problem Details RFC 7807 : ProblemNormalizer
 * transforme une ValidationFailedException — ce que lève #[MapRequestPayload]
 * — dans la bonne forme, sans aucun code.
 *
 * Le media type est le problème. Un client demandant correctement
 * `Accept: application/problem+json` résout le format de requête à `problem`.
 * Aucun encoder de Symfony ne supporte ce format, donc le serializer lève une
 * UnsupportedFormatException, SerializerErrorRenderer la capture comme une
 * NotEncodableValueException, et se replie sur le moteur de rendu d'erreurs
 * HTML.
 *
 * Résultat : le client qui demande correctement du Problem Details reçoit
 * une page HTML, tandis que celui qui demande `application/json` obtient le
 * bon corps sous le mauvais Content-Type. Enregistrer cet encoder corrige les
 * deux.
 *
 * L'autoconfiguration le tague. Il n'y a rien d'autre à câbler.
 *
 * Vérifié sur Symfony 8.1.
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
