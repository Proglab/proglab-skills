<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les rôles et les comptes, et les trois rôles du socle semés.
 *
 * Relue à la main après génération, comme le standard l'exige, et sur le précédent posé
 * par la migration des langues. Ce que la relecture a changé sur le brouillon du
 * générateur :
 *
 * - **le semis des trois rôles**, que `doctrine:migrations:diff` ne peut pas deviner — il
 *   compare deux schémas, jamais deux contenus. « Les trois rôles du socle existent comme
 *   rôles nommés » est un critère de la story, donc il vit ici, exactement comme les trois
 *   langues de la story 1.5 ;
 * - **les codes et les libellés écrits en toutes lettres** plutôt que lus depuis
 *   `App\Core\Enum\CoreRole`. Une migration est l'enregistrement de ce qui s'est passé à
 *   une date : la relire à travers le code d'aujourd'hui la ferait changer de sens le jour
 *   où l'enum gagne ou perd un cas. `UserPersistenceTest` tient la coïncidence entre
 *   l'enum et les lignes semées ;
 * - **`ENGINE = InnoDB` conservé tel que le générateur l'écrit**, et non raboté au titre
 *   du « c'est le défaut ». Il ne l'est que si `default_storage_engine` le dit : sur un
 *   serveur client réglé autrement, MyISAM **accepte** l'`ADD CONSTRAINT` puis l'ignore
 *   silencieusement, et « un utilisateur porte exactement un rôle » cesse d'être tenu par
 *   la base sans qu'aucune erreur ne soit levée ;
 * - **l'ordre `down()` corrigé** : le générateur supprime `role` avant `user`, alors que
 *   c'est `user` qui porte la clé étrangère. La contrainte est bien retirée d'abord, donc
 *   l'ordre tenait par accident ; il est remis dans le sens qui se lit.
 *
 * Les libellés semés sont **français**, langue source du socle. Ce ne sont pas des clés de
 * traduction : un rôle est une donnée qu'un administrateur renomme depuis l'écran de
 * l'Epic 2, et c'est le `code` — pas le libellé — que le code retrouve.
 *
 * `user` est entre accents graves : c'est un mot réservé de MySQL 8.
 */
final class Version20260911130140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables des rôles et des comptes, et sème les trois rôles du socle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE role (
                  id INT AUTO_INCREMENT NOT NULL,
                  code VARCHAR(32) DEFAULT NULL,
                  name VARCHAR(100) NOT NULL,
                  UNIQUE INDEX UNIQ_57698A6A77153098 (code),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE `user` (
                  id INT AUTO_INCREMENT NOT NULL,
                  email VARCHAR(180) NOT NULL,
                  password VARCHAR(255) NOT NULL,
                  enabled TINYINT NOT NULL,
                  language VARCHAR(5) NOT NULL,
                  role_id INT NOT NULL,
                  UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
                  INDEX IDX_8D93D649D60322AC (role_id),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE `user`
                  ADD CONSTRAINT FK_8D93D649D60322AC FOREIGN KEY (role_id) REFERENCES role (id)
            SQL);

        // L'ordre d'insertion suit l'ordre d'affichage de l'enum — du plus large au plus
        // étroit. Aucune permission n'est attachée : elles arrivent avec FR-7 à l'Epic 2.
        $this->addSql(<<<'SQL'
                INSERT INTO role (code, name) VALUES
                  ('super_admin', 'Super admin'),
                  ('admin', 'Admin'),
                  ('user', 'User')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649D60322AC
            SQL);

        $this->addSql(<<<'SQL'
                DROP TABLE `user`
            SQL);

        $this->addSql(<<<'SQL'
                DROP TABLE role
            SQL);
    }
}
