<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DatabaseDoesNotExist;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Le repository des comptes — et rien de plus que ce que le socle demande réellement.
 *
 * Le provider `entity` de Symfony charge par propriété (`email`) et n'a besoin d'aucune
 * méthode d'ici : la connexion n'en a donc jamais ouvert. La story 1.9 est la première à
 * poser une vraie question — « qui porte cette adresse ? » — depuis un cas d'usage, et
 * c'est cette question qui vit ci-dessous.
 *
 * La story 1.11 en ajoute deux autres, et elles sont d'une autre nature : elles ne
 * cherchent pas un compte, elles interrogent **la base sur elle-même** — la table des
 * comptes existe-t-elle, la connexion est-elle utilisable. **Elles vivent ici parce que le
 * repository est le seul adaptateur du socle vers la base, et cela resterait vrai sans
 * deptrac** : l'introspection de schéma et l'état d'une connexion sont de la mécanique de
 * persistance, pas des faits métier. La lecture métier, elle, est déjà ailleurs —
 * `App\Core\Service\DerivativeInitializer::state()` compose les deux faits en un
 * `DerivativeState`, et c'est lui qui décide ce qu'ils veulent dire.
 *
 * Les déplacer vers `Service/` exigerait d'ouvrir `Doctrine` à la couche `Service`,
 * c'est-à-dire la régression exacte que le contrat existe pour empêcher.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    /**
     * Le code d'erreur MySQL « Unknown database », et pourquoi il est écrit ici.
     *
     * DBAL ne mappe **pas** une base absente sur `DatabaseDoesNotExist` avec `pdo_mysql` :
     * vérifié sur DBAL 4.4 contre MySQL 8.4, l'erreur 1049 est convertie en
     * `ConnectionException` par `Driver\API\MySQL\ExceptionConverter` — seule l'erreur 1008
     * (« can't drop database ») obtient `DatabaseDoesNotExist`. N'attraper que cette
     * dernière, comme le décrivait la spec de la story, laisserait `app:init` sortir sur
     * une trace au tout premier scénario de sa matrice, celui d'un clone frais.
     *
     * Le code est donc comparé explicitement plutôt que d'avaler toute
     * `ConnectionException` : un serveur éteint, un mot de passe faux ou un hôte inconnu
     * doivent continuer de remonter tels quels, et surtout pas se déguiser en « dérivé non
     * initialisé » — ce qui ferait annoncer un état faux avant d'échouer ailleurs.
     */
    private const int MYSQL_UNKNOWN_DATABASE = 1049;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Le compte qui porte cette adresse, actif ou non.
     *
     * **L'état n'est pas filtré ici, et c'est délibéré.** La règle « un compte désactivé
     * ne reprend pas son accès seul » appartient à `App\Core\Service\PasswordResetRequest`,
     * qui doit répondre la même chose à une adresse désactivée et à une adresse inconnue.
     * Une requête qui ne rendrait que les comptes actifs rendrait les deux cas
     * indiscernables **pour l'appelant aussi** — donc impossibles à traiter différemment
     * le jour où l'un des deux devra l'être, et invisibles dans un journal d'audit.
     *
     * La méthode est écrite plutôt que laissée au `__call` magique de Doctrine : celui-là
     * rend `object|null` et PHPStan ne peut rien en dire.
     */
    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * La table des comptes est-elle atteignable ?
     *
     * « Non » recouvre deux situations que la story fusionne volontairement — la base
     * n'existe pas, ou elle existe sans schéma : `doctrine:database:create
     * --if-not-exists` est un no-op sur la seconde, donc les distinguer n'achèterait rien.
     *
     * **Le nom de table est lu sur les métadonnées**, jamais écrit en dur : la table des
     * comptes s'appelle `` `user` `` — un mot réservé, donc échappé dans le mapping — et
     * recopier ce nom ici le figerait une seconde fois, à un endroit qu'aucun renommage
     * d'entité ne suivrait. `getTableName()` rend le nom **déjà déquoté**, qui est
     * exactement ce que l'introspection attend.
     */
    public function hasAccountTable(): bool
    {
        $connection = $this->getEntityManager()->getConnection();

        try {
            return $connection->createSchemaManager()->tableExists($this->getClassMetadata()->getTableName());
        } catch (DatabaseDoesNotExist) {
            return false;
        } catch (ConnectionException $exception) {
            if (self::MYSQL_UNKNOWN_DATABASE !== $exception->getCode()) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * Repart sur une connexion neuve, après qu'un autre acteur du même processus a touché
     * au schéma.
     *
     * **Sans cela, la toute première `flush()` d'un dérivé fraîchement initialisé échoue**
     * — mesuré, pas supposé. `doctrine:migrations:migrate` enveloppe chaque version dans
     * une transaction ; sur MySQL, le DDL déclenche un *implicit commit* qui détruit
     * transaction et points de sauvegarde sans que DBAL en soit informé. Le compteur de
     * profondeur de transaction reste donc à 4 après quatre migrations (vérifié par
     * `getTransactionNestingLevel()`), et l'`EntityManager` croit écrire dans une
     * transaction imbriquée : « SAVEPOINT DOCTRINE_4 does not exist ».
     *
     * `close()` remet ce compteur à zéro et lâche la connexion sous-jacente ; la requête
     * suivante en ouvre une neuve. Rien n'est perdu : il n'y a par construction aucune
     * écriture en cours au moment où la commande appelle ceci.
     *
     * Ne concerne que le chemin console de la story 1.11 — jouer des migrations et écrire
     * dans la foulée, au sein d'un même processus, n'arrive nulle part ailleurs.
     */
    public function reopenConnection(): void
    {
        $this->getEntityManager()->getConnection()->close();
    }

    /**
     * Combien de comptes porte ce dérivé.
     *
     * Un booléen suffirait à l'état, mais pas au message : le refus sur une base peuplée
     * doit nommer *combien* de comptes il a trouvés — c'est ce qui distingue une base de
     * recette repeuplée du dérivé de production. Une seule question posée à la base, donc,
     * plutôt qu'un `has…` suivi d'un `count…`.
     *
     * Les comptes désactivés sont comptés : un dérivé dont le seul compte est désactivé
     * n'est pas vierge.
     */
    public function countAccounts(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
