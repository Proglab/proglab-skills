<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La première migration du dépôt : la table des langues, et ses trois lignes.
 *
 * Relue à la main après génération, comme le standard l'exige. Ce que la relecture a
 * changé sur le brouillon du générateur :
 *
 * - **le semis**, que `doctrine:migrations:diff` ne peut pas deviner — il compare deux
 *   schémas, jamais deux contenus. « Les trois langues sont actives à l'installation »
 *   est un critère de la story, donc il vit ici : c'est la seule chose qu'un clone frais
 *   exécute avant de répondre à sa première requête ;
 * - **les codes écrits en toutes lettres** plutôt que lus depuis
 *   `App\Core\Enum\SupportedLocale`. Une migration est un enregistrement de ce qui s'est
 *   passé à une date : la relire à travers du code d'aujourd'hui la ferait changer de
 *   sens le jour où l'enum gagne ou perd un cas. `CatalogParityTest` tient la
 *   coïncidence entre l'enum et les lignes semées, et c'est lui qui signalera l'écart ;
 * - **`down()` laissé en `DROP TABLE`**, qui est bien l'inverse exact de cette
 *   migration-ci : elle crée la table *et* son contenu, donc le retour arrière emporte
 *   les deux. Rien à reconstituer, aucune donnée saisie par un utilisateur.
 *
 * `INSERT` et non `INSERT IGNORE` : la table vient d'être créée dans la même migration,
 * donc il ne peut rien y avoir à ignorer. Un `IGNORE` masquerait ici une vraie erreur.
 */
final class Version20260911074811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table des langues et y sème les trois langues supportées, toutes actives.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE language (
                  id INT AUTO_INCREMENT NOT NULL,
                  code VARCHAR(5) NOT NULL,
                  enabled TINYINT NOT NULL,
                  UNIQUE INDEX UNIQ_D4DB71B577153098 (code),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        // L'ordre d'insertion suit l'ordre de repli de l'enum — français, anglais,
        // néerlandais. Rien ne s'appuie dessus (l'ordre de repli est imposé en PHP par
        // le repository, jamais par un `ORDER BY`), mais une table lue à la main en dit
        // plus quand elle raconte la même histoire que le code.
        $this->addSql(<<<'SQL'
                INSERT INTO language (code, enabled) VALUES ('fr', 1), ('en', 1), ('nl', 1)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                DROP TABLE language
            SQL);
    }
}
