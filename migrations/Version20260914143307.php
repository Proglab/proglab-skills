<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les jetons de compte (AD-15) et le jeton de sécurité de session (AD-9).
 *
 * Générée par `doctrine:migrations:diff`, puis relue à la main comme les trois
 * précédentes. Ce que la relecture a changé sur le brouillon du générateur :
 *
 * - **`` `user` `` remis entre accents graves dans `up()`.** Le générateur écrit
 *   `ALTER TABLE user ADD …` dans `up()` et ``ALTER TABLE `user` DROP …`` dans `down()`,
 *   pour la même table. `USER` est un mot que MySQL interprète aussi comme une fonction :
 *   la forme non échappée dépend de la version et du mode SQL du serveur client, et c'est
 *   précisément le genre de dépendance qu'un socle dérivé chez plusieurs clients n'a pas
 *   le droit d'avoir. Les deux sens sont donc écrits de la même façon ;
 * - **`ENGINE = InnoDB` ajouté**, que le générateur n'écrit pas ici. Ce n'est pas
 *   cosmétique : sur un serveur réglé avec `default_storage_engine = MyISAM`, les deux
 *   `ADD CONSTRAINT` ci-dessous seraient **acceptés puis ignorés en silence**, et un jeton
 *   pourrait survivre au compte qu'il désigne. La table porte de plus une garantie
 *   transactionnelle — le jeton et le message qui l'annonce sont écrits sous la même
 *   frontière (story 1.8, décision D-1) —, et MyISAM n'a pas de transactions du tout ;
 * - **le SQL passé en heredoc et indenté**, comme les trois migrations précédentes : une
 *   instruction `CREATE TABLE` d'une seule ligne de 500 caractères ne se relit pas, et une
 *   migration se relit ;
 * - **l'ordre de `down()` remis dans le sens inverse exact de `up()`** : le générateur
 *   retire les deux clés étrangères, supprime la table, puis retire la colonne — alors que
 *   la colonne est ajoutée en dernier. L'ordre tenait par accident ; il est remis dans
 *   celui qui se lit.
 *
 * **`security_token` naît à 1 et non à 0**, et la valeur par défaut est portée par la
 * colonne : les comptes déjà en base la reçoivent sans qu'aucun `UPDATE` ne soit écrit
 * ici, et une valeur initiale distincte du zéro de son type permet de distinguer
 * « jamais posé » de « posé à zéro » à la lecture.
 *
 * **Ce que `down()` détruit, et qu'il faut lire avant de l'exécuter :** les liens de
 * réinitialisation en cours, et la trace de ceux qui ont servi. Aucune donnée saisie par
 * un utilisateur — un jeton est un moyen, pas un contenu —, mais les liens déjà envoyés
 * cessent de fonctionner au moment où la table disparaît.
 *
 * Les noms d'index et de contraintes sont ceux que le générateur calcule : les renommer
 * ferait rouvrir un diff à chaque exécution de `doctrine:migrations:diff`.
 */
final class Version20260914143307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table des jetons de compte et ajoute le jeton de sécurité de session sur les comptes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE account_token (
                  id INT AUTO_INCREMENT NOT NULL,
                  purpose VARCHAR(32) NOT NULL,
                  token_hash VARCHAR(64) NOT NULL,
                  expires_at DATETIME NOT NULL,
                  used_at DATETIME DEFAULT NULL,
                  created_at DATETIME NOT NULL,
                  user_id INT NOT NULL,
                  intended_role_id INT DEFAULT NULL,
                  UNIQUE INDEX UNIQ_69B8784AB3BC57DA (token_hash),
                  INDEX IDX_69B8784AA76ED395 (user_id),
                  INDEX IDX_69B8784A97959901 (intended_role_id),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE account_token
                  ADD CONSTRAINT FK_69B8784AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE account_token
                  ADD CONSTRAINT FK_69B8784A97959901 FOREIGN KEY (intended_role_id) REFERENCES role (id)
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE `user` ADD security_token INT DEFAULT 1 NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE `user` DROP security_token
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE account_token DROP FOREIGN KEY FK_69B8784A97959901
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE account_token DROP FOREIGN KEY FK_69B8784AA76ED395
            SQL);

        $this->addSql(<<<'SQL'
                DROP TABLE account_token
            SQL);
    }
}
