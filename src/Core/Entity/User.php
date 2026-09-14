<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Enum\SupportedLocale;
use Deprecated;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un compte du socle : une adresse, un mot de passe haché, un état, un rôle, une langue.
 *
 * Entité au format maker : des colonnes et le contrat minimal que la sécurité exige,
 * aucune règle. Ce qu'« actif » implique, ce qu'un rôle permet, ce qui déconnecte une
 * session — rien de tout cela ne vit ici.
 *
 * **`getRoles()` rend `['ROLE_USER']` en dur, et c'est AD-8 qui l'impose.** Le rôle
 * métier est une relation vers `Role` ; le projeter en `ROLE_SUPER_ADMIN` rouvrirait
 * précisément la porte qu'AD-8 ferme — un `#[IsGranted('ROLE_ADMIN')]` quelque part, et
 * la grille de permissions de l'Epic 2 devient contournable. Le seul rôle Symfony qu'un
 * compte porte est celui qui distingue un visiteur d'un utilisateur connecté.
 *
 * **La langue est une colonne enum, pas une relation** (décision du 2026-09-11) : AD-14
 * l'emporte sur l'ERD du spine. `SupportedLocale` reste la seule autorité sur les langues
 * supportées, la table `language` garde son unique rôle — dire lesquelles sont offertes —
 * et aucun chargement d'utilisateur ne porte de jointure. Une langue désactivée que porte
 * encore un compte se replie au rendu, comme n'importe quel autre repli de la story 1.5.
 *
 * **Le jeton de sécurité de session et `isEqualTo()` sont arrivés avec la story 1.9**,
 * celle qui change un mot de passe — c'est ce que le paragraphe précédent annonçait
 * (AD-9). `isEqualTo()` est **la seule méthode de comportement admise sur une entité de ce
 * socle**, et l'écart est énoncé plutôt que dissimulé : c'est `EquatableInterface` qui
 * l'impose, et l'interface est interrogée par `ContextListener` sur l'objet désérialisé de
 * la session — il n'y a aucun service à interroger à cet instant, donc aucune façon de
 * porter la règle ailleurs.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * L'identifiant de connexion. Typé `non-empty-string` : `getUserIdentifier()` le
     * promet ainsi à Symfony — c'est lui qui se retrouve en session et dans chaque token,
     * et une chaîne vide y serait un compte anonyme indistinguable.
     *
     * @var non-empty-string
     */
    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /**
     * Le hachage, jamais le mot de passe. `password_hashers: auto` choisit l'algorithme
     * et le préfixe le dit ; aucun algorithme n'est nommé en dur nulle part.
     */
    #[ORM\Column]
    private string $password;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Role $role;

    #[ORM\Column(length: 5, enumType: SupportedLocale::class)]
    private SupportedLocale $language = SupportedLocale::Fr;

    /**
     * Le jeton de sécurité de session (AD-9) — un compteur, rien de plus.
     *
     * Il est incrémenté par tout ce qui doit fermer les sessions ouvertes d'un compte :
     * le changement de mot de passe (story 1.9), la désactivation (Epic 2), la
     * réinitialisation du second facteur (Epic 5). `isEqualTo()` en fait le critère de
     * ré-authentification ; le reste du mécanisme est natif.
     *
     * Il commence à 1 et non à 0 : une colonne dont la valeur initiale est la valeur
     * « vide » de son type ne permet pas de distinguer « jamais posé » de « posé à zéro »
     * le jour où quelqu'un lira la table à la main.
     *
     * Jamais visible, jamais modifiable depuis une interface : ce n'est pas un réglage,
     * c'est une conséquence.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $securityToken = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return non-empty-string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @param non-empty-string $email
     */
    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * L'identifiant visuel qui représente cet utilisateur — c'est lui qui se retrouve
     * dans la session et dans chaque token, donc il doit être stable et unique.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getLanguage(): SupportedLocale
    {
        return $this->language;
    }

    public function setLanguage(SupportedLocale $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getSecurityToken(): int
    {
        return $this->securityToken;
    }

    public function setSecurityToken(int $securityToken): static
    {
        $this->securityToken = $securityToken;

        return $this;
    }

    /**
     * **La seule méthode de comportement de cette entité, et l'écart est assumé** (AD-9).
     *
     * `ContextListener` recharge l'utilisateur depuis le provider à chaque requête, puis
     * compare l'objet **désérialisé de la session** au **fraîchement chargé**. Dès que
     * cette interface est implémentée, elle devient le **seul** critère : la comparaison
     * native des hachages de mot de passe n'est plus consultée du tout
     * (`ContextListener::hasUserChanged()`). Les trois lignes ci-dessous doivent donc
     * couvrir au moins ce que le défaut couvrait.
     *
     * Pourquoi la règle ne peut pas vivre dans un service : à cet instant il n'y a ni
     * conteneur atteignable depuis l'objet, ni point d'extension — l'interface est
     * interrogée sur l'instance elle-même. Le déplacer demanderait de remplacer le
     * `ContextListener` natif, c'est-à-dire de réécrire le mécanisme pour respecter la
     * forme d'une règle qui tient en trois comparaisons.
     *
     * Ce qui est comparé, et pourquoi chaque ligne y est :
     *
     * - **la classe**, parce qu'un provider qui rendrait un autre type d'utilisateur pour
     *   le même identifiant ne doit pas passer pour le même compte ;
     * - **l'identifiant**, qui est l'email : le changer est un changement d'identité, et
     *   la session qui porte l'ancien n'a plus de titre à rester ouverte ;
     * - **le jeton de sécurité**, qui est le mécanisme d'AD-9. C'est lui qui ferme les
     *   sessions à la réinitialisation du mot de passe — et, quand les stories suivantes
     *   l'incrémenteront, à la désactivation d'un compte et à la réinitialisation du
     *   second facteur, deux changements qui ne touchent aucun hachage.
     *
     * Le hachage du mot de passe n'est **pas** comparé, et ce n'est pas un oubli : le
     * jeton de sécurité est incrémenté par le même cas d'usage, dans la même transaction
     * (`App\Core\Service\PasswordReset`). Comparer les deux ferait dépendre la fermeture
     * de session d'une redondance qu'un futur appelant pourrait rompre en croyant bien
     * faire.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->email === $user->getUserIdentifier()
            && $this->securityToken === $user->getSecurityToken();
    }

    /**
     * Vide, et l'attribut le dit au framework.
     *
     * `UserInterface::eraseCredentials()` est déprécié depuis Symfony 7.3 et disparaît en
     * 8.0 ; il reste déclaré par l'interface en 7.4, donc la méthode doit exister.
     * `AuthenticatorManager` déclenche une dépréciation sur toute implémentation **qui ne
     * porte pas `#[\Deprecated]`** — c'est la façon dont on lui signale qu'elle est vide
     * exprès. Aucun secret en clair n'est gardé sur cet objet : il n'y a rien à effacer.
     */
    #[Deprecated]
    public function eraseCredentials(): void
    {
    }
}
