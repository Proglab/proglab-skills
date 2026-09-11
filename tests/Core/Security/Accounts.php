<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Entity\Role;
use App\Core\Entity\User;
use App\Core\Enum\CoreRole;
use App\Core\Enum\SupportedLocale;
use App\Core\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * La fabrique de comptes des tests, partagée par les trois classes qui en ont besoin.
 *
 * `doctrine/doctrine-fixtures-bundle` n'est pas installé : les tests créent leurs lignes
 * à la main par l'`EntityManager`, exactement comme les `Language` de la story 1.5. Ce
 * qui est mutualisé ici, c'est le hachage — un compte de test dont le mot de passe
 * n'aurait pas traversé le hasher du projet prouverait la connexion sur un mécanisme que
 * la production n'utilise pas.
 *
 * Les rôles ne sont pas créés ici : ils viennent de la migration, et un test qui les
 * recréerait cesserait de vérifier qu'ils ont bien été semés.
 */
final readonly class Accounts
{
    /**
     * @param non-empty-string $email
     */
    public static function create(
        ContainerInterface $container,
        string $email,
        string $password,
        bool $enabled = true,
        SupportedLocale $language = SupportedLocale::Fr,
        CoreRole $role = CoreRole::User,
    ): User {
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $roles = $container->get(RoleRepository::class);

        $user = new User();
        $user->setEmail($email)
            ->setEnabled($enabled)
            ->setLanguage($language)
            ->setRole(self::role($roles, $role));

        $user->setPassword($hasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private static function role(RoleRepository $roles, CoreRole $code): Role
    {
        $role = $roles->findOneByCode($code);

        if (null === $role) {
            throw new RuntimeException(\sprintf('Le rôle du socle « %s » est absent de la base : la migration qui le sème n\'a pas été jouée.', $code->value));
        }

        return $role;
    }
}
