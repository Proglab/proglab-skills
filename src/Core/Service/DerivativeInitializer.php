<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Dto\Input\FirstSuperAdminInput;
use App\Core\Entity\User;
use App\Core\Enum\CoreRole;
use App\Core\Enum\DerivativeState;
use App\Core\Enum\SupportedLocale;
use App\Core\Exception\InitializationFailed;
use App\Core\Repository\RoleRepository;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Dans quel état est ce dérivé, et ai-je le droit d'y créer le premier Super admin ? ».
 *
 * **Toutes les règles de la story 1.11 vivent ici, et aucune ailleurs** (AC 4).
 * `App\Core\Command\InitCommand` lit l'état que ce service rend, enchaîne deux commandes
 * Doctrine, pose trois questions et traduit le résultat en code de sortie — elle ne
 * décide de rien. Ce n'est pas une question d'ordre : une règle laissée dans un
 * `__invoke()` ne s'exerce qu'en démarrant une console et en relisant du texte dans un
 * buffer, là où les quatre méthodes ci-dessous se testent en appelant une méthode.
 *
 * **Ce service ne sème rien.** Les trois rôles du socle sont insérés par la migration
 * `Version20260911130140`, qui est l'unique source de vérité du schéma comme de ces
 * lignes. `verifyCoreRoles()` **vérifie** leur présence et échoue bruyamment ; recréer le
 * rôle manquant donnerait deux endroits qui prétendent le définir, et le jour où ils
 * divergeraient personne ne saurait lequel a raison.
 *
 * **Ce service ne joue pas les migrations non plus.** Piloter `DependencyFactory`,
 * `PlanCalculator` et `Migrator` à la main, ce serait réimplémenter
 * `doctrine:migrations:migrate` et le maintenir contre l'amont ; et un service ne peut de
 * toute façon pas voir `Doctrine\*` (`deptrac.yaml`). Enchaîner les commandes existantes
 * est de la plomberie console, au même titre que la mise en forme de la sortie — c'est
 * donc la commande qui la porte.
 */
final readonly class DerivativeInitializer
{
    /**
     * La migration qui sème les trois rôles du socle.
     *
     * Écrite ici et nulle part ailleurs : c'est ce nom que l'opérateur voit quand un rôle
     * manque, et le message doit lui dire quoi jouer. Une migration ne se renomme pas —
     * son nom est son horodatage —, donc cette constante ne se périme que si la migration
     * est supprimée, ce qui casserait bien plus que ce message.
     */
    public const string ROLE_SEEDING_MIGRATION = 'Version20260911130140';

    public function __construct(
        private UserRepository $users,
        private RoleRepository $roles,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * L'état du dérivé, en trois valeurs et pas une de plus.
     *
     * L'ordre des deux questions n'est pas interchangeable : compter les comptes d'une
     * table qui n'existe pas lèverait une exception Doctrine au lieu de rendre un état.
     */
    public function state(): DerivativeState
    {
        if (!$this->users->hasAccountTable()) {
            return DerivativeState::Uninitialized;
        }

        return 0 === $this->users->countAccounts()
            ? DerivativeState::Empty
            : DerivativeState::Initialized;
    }

    /**
     * Combien de comptes porte ce dérivé — la donnée que le refus doit nommer.
     *
     * Ne s'appelle que sur un dérivé dont la table des comptes existe, c'est-à-dire après
     * que `state()` a rendu autre chose qu'`Uninitialized`.
     */
    public function accountCount(): int
    {
        return $this->users->countAccounts();
    }

    /**
     * Le schéma vient d'être posé par quelqu'un d'autre dans ce processus — reprends une
     * connexion neuve.
     *
     * La commande enchaîne `doctrine:migrations:migrate` puis écrit un compte, dans le même
     * PHP. Ce que cela laisse derrière est décrit en détail sur
     * `UserRepository::reopenConnection()` : un compteur de transaction faussé par
     * l'*implicit commit* de MySQL, qui fait échouer la première `flush()`.
     *
     * Ce service n'a pas le droit de voir Doctrine, et n'en a pas besoin : il demande, et
     * le repository fait.
     */
    public function reopenAfterSchemaChange(): void
    {
        $this->users->reopenConnection();
    }

    /**
     * Les trois rôles du socle sont-ils là ?
     *
     * Les trois, et pas seulement le Super admin : la story promet « les rôles par défaut
     * chargés », et un dérivé qui démarrerait avec un seul des trois casserait la gestion
     * des comptes de l'Epic 2 sans que rien ne l'ait dit. L'ordre de `CoreRole::cases()`
     * est celui de la migration, donc le premier manquant nommé est aussi le premier que
     * l'insertion aurait dû poser.
     *
     * @throws InitializationFailed quand l'un des trois manque
     */
    public function verifyCoreRoles(): void
    {
        foreach (CoreRole::cases() as $code) {
            if (null === $this->roles->findOneByCode($code)) {
                throw InitializationFailed::coreRoleMissing($code->value, self::ROLE_SEEDING_MIGRATION);
            }
        }
    }

    /**
     * Le refus sur un dérivé déjà peuplé — le défaut de la commande.
     *
     * **Le danger n'est pas la perte de données, c'est la création silencieuse d'un compte
     * à tous les droits sur un dérivé en service.** C'est pourquoi le patron du skill
     * console est inversé ici : le défaut sûr n'est pas « rapporter sans agir » mais
     * « agir sur une base vide, refuser sur une base peuplée ». `--force` est l'issue de
     * secours quand plus personne ne peut se connecter, et il crée alors un Super admin
     * **supplémentaire** — il ne remplace ni ne désactive personne.
     *
     * @throws InitializationFailed quand le dérivé porte déjà un compte et que `$force` est faux
     */
    public function guardAgainstExistingAccounts(bool $force): void
    {
        if ($force || DerivativeState::Initialized !== $this->state()) {
            return;
        }

        throw InitializationFailed::alreadyInitialized($this->accountCount());
    }

    /**
     * Crée le premier Super admin : actif, en français, avec le rôle du socle.
     *
     * Le DTO arrive **déjà validé** — c'est la commande qui appelle le validateur et
     * repose la question tant qu'il refuse, exactement comme un contrôleur rerend un
     * formulaire. Ce que ce service vérifie en propre, c'est ce qu'aucune contrainte ne
     * peut voir depuis un DTO : que le rôle existe, et que l'adresse est libre.
     *
     * **L'unicité est vérifiée ici plutôt que laissée à l'index**, parce qu'une
     * `UniqueConstraintViolationException` remontée jusqu'à la console rendrait une trace
     * là où l'opérateur attend une phrase et une adresse à corriger.
     *
     * La langue est `Fr` — le défaut de l'entité, réénoncé pour que la ligne créée par la
     * console soit identique à celle qu'un autre chemin créerait. Le socle est trilingue,
     * mais le français en est la langue source, et le premier compte d'un dérivé qu'on
     * installe n'a personne pour lui en demander une autre.
     *
     * @throws InitializationFailed quand le rôle du socle manque, ou que l'adresse est déjà prise
     */
    public function createFirstSuperAdmin(FirstSuperAdminInput $input): User
    {
        $role = $this->roles->findOneByCode(CoreRole::SuperAdmin);

        if (null === $role) {
            throw InitializationFailed::coreRoleMissing(CoreRole::SuperAdmin->value, self::ROLE_SEEDING_MIGRATION);
        }

        $email = $input->email;

        // `NotBlank` sur le DTO le garantit à l'exécution, et `User::setEmail()` le promet
        // à Symfony en `non-empty-string` : c'est cette promesse-là que l'assertion relie à
        // la contrainte. Elle disparaît en production (`zend.assertions=-1`) — elle dit un
        // invariant, elle ne le défend pas.
        \assert('' !== $email, 'FirstSuperAdminInput arrive validé : NotBlank interdit une adresse vide.');

        if (null !== $this->users->findOneByEmail($email)) {
            throw InitializationFailed::emailAlreadyTaken($email);
        }

        $user = new User();
        $user->setEmail($email)
            ->setEnabled(true)
            ->setLanguage(SupportedLocale::Fr)
            ->setRole($role);

        $user->setPassword($this->hasher->hashPassword($user, $input->password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
