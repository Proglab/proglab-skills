<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Enum\AccountTokenPurpose;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un jeton à usage unique adressé à un compte — l'entité partagée d'AD-15.
 *
 * Entité au format maker : des colonnes, aucune règle. Ce qu'« vivant » veut dire se
 * décide dans `App\Core\Repository\AccountTokenRepository` ; ce qui le consomme vit dans
 * `App\Core\Service\PasswordReset`.
 *
 * **La base ne garde que l'empreinte.** Le jeton en clair est tiré par
 * `App\Core\Service\PasswordResetRequest`, part dans l'URL de l'email, et n'est jamais
 * écrit ici : `tokenHash` porte son SHA-256, la colonne est unique, et la recherche est
 * une égalité indexée. Le choix de SHA-256 plutôt que du hacheur de mots de passe est
 * délibéré — 32 octets tirés au hasard ne sont pas devinables par dictionnaire, donc rien
 * ne justifie de ralentir leur vérification, et un hachage lent obligerait à relire tous
 * les jetons vivants pour les comparer un par un.
 *
 * **Ce que cette story livre vide, et pourquoi c'est quand même là.** `purpose` et
 * `intendedRole` sont exigés par AD-15 : l'invitation de la story 2.6 réutilise cette
 * entité telle quelle, et la découvrir incomplète à ce moment-là imposerait une seconde
 * migration sur une table déjà en production chez un dérivé. `intendedRole` reste donc
 * `null` sur tout ce que le socle émet aujourd'hui.
 *
 * **Première entité du dépôt à porter des dates**, et elles sont toutes
 * `\DateTimeImmutable` : une date d'expiration qu'un appelant pourrait modifier par
 * inadvertance est une date d'expiration qui ne veut plus rien dire.
 *
 * **Pas de `repositoryClass:`**, comme les trois entités précédentes : le ruleset `Entity`
 * de `deptrac.yaml` n'autorise pas `Repository`, et le contrat de couches l'emporte sur la
 * forme du maker. Le socle injecte `App\Core\Repository\AccountTokenRepository` par son
 * type ; `$entityManager->getRepository()` rendrait un `EntityRepository` générique.
 */
#[ORM\Entity]
class AccountToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 32, enumType: AccountTokenPurpose::class)]
    private AccountTokenPurpose $purpose;

    /**
     * L'empreinte SHA-256 du jeton, en hexadécimal — 64 caractères, toujours.
     *
     * La colonne est unique parce que la recherche passe par elle : c'est l'index qui
     * rend « retrouver la ligne d'un jeton » une égalité et non un balayage.
     */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    /**
     * La date à laquelle le jeton a été **consommé par son destinataire**, et rien
     * d'autre.
     *
     * Un jeton rendu caduc par une demande plus récente n'est pas marqué ici : il est
     * supprimé (`AccountTokenRepository::invalidateLiveFor()`). Écrire une date d'usage
     * sur un lien que personne n'a ouvert mentirait à l'écran que l'Epic 2 posera.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /**
     * Le rôle que le compte recevra quand ce jeton sera consommé.
     *
     * Livré vide et inutilisé : c'est l'invitation de la story 2.6 qui le remplira (FR-4).
     * AD-15 l'exige dans le modèle dès maintenant — voir le docblock de la classe.
     */
    #[ORM\ManyToOne]
    private ?Role $intendedRole = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPurpose(): AccountTokenPurpose
    {
        return $this->purpose;
    }

    public function setPurpose(AccountTokenPurpose $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getIntendedRole(): ?Role
    {
        return $this->intendedRole;
    }

    public function setIntendedRole(?Role $intendedRole): static
    {
        $this->intendedRole = $intendedRole;

        return $this;
    }
}
