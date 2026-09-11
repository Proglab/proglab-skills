<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Enum\SupportedLocale;
use Deprecated;
use Doctrine\ORM\Mapping as ORM;
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
 * Pas de jeton de sécurité de session (AD-9) ni d'`EquatableInterface` : rien dans cette
 * story ne les incrémenterait, et une mécanique que rien ne déclenche n'est vérifiée par
 * rien. Ils arrivent avec la première story qui désactive un compte ou change un mot de
 * passe.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
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
