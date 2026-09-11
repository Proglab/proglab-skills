<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\Language;
use App\Core\Enum\SupportedLocale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * La seule requête dont le socle a besoin sur les langues : celles qui sont actives.
 *
 * Pas `final`, comme tout repository de ce standard : un test unitaire de service doit
 * pouvoir la doubler.
 *
 * @extends ServiceEntityRepository<Language>
 */
class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    /**
     * Les langues actives, dans l'ordre de repli de l'enum.
     *
     * La projection ne remonte que la colonne `code` : l'appelant n'a besoin de rien
     * d'autre, et hydrater des entités pour lire un enum ferait payer l'identity map et
     * le change tracking à chaque requête HTTP.
     *
     * L'ordre est imposé **en PHP** et non par un `ORDER BY` : l'ordre de repli est celui
     * de l'enum, pas celui d'une colonne. Un `ORDER BY code` donnerait l'ordre
     * alphabétique — en, fr, nl — et le repli changerait de langue sans que personne
     * n'ait touché à l'enum.
     *
     * @return list<SupportedLocale>
     */
    public function findActiveCodes(): array
    {
        /** @var list<array{code: SupportedLocale}> $rows */
        $rows = $this->createQueryBuilder('language')
            ->select('language.code')
            ->andWhere('language.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getResult();

        $active = array_column($rows, 'code');

        return array_values(array_filter(
            SupportedLocale::cases(),
            static fn (SupportedLocale $locale): bool => \in_array($locale, $active, true),
        ));
    }
}
