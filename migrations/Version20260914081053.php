<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La table du transport Messenger : la file des emails, et la file d'échec.
 *
 * Elle existe parce que `auto_setup` est désactivé des deux côtés — dans le DSN
 * (`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`) et dans
 * `config/packages/messenger.yaml`. Sans cette migration, le premier message tombe sur
 * « Table messenger_messages does not exist », en CI comme au premier déploiement. C'est
 * le prix assumé de ne jamais laisser un transport émettre du DDL sur la connexion de
 * l'application.
 *
 * Générée par `doctrine:migrations:diff` — le `MessengerTransportDoctrineSchemaListener`
 * de DoctrineBundle ajoute la table au schéma que Doctrine calcule, donc le diff la
 * récupère comme n'importe quelle table d'entité. Relue à la main ensuite, comme les deux
 * migrations précédentes. Ce que la relecture a changé sur le brouillon du générateur :
 *
 * - **`ENGINE = InnoDB` ajouté**, alors que le générateur ne l'écrit pas ici : la table
 *   du transport ne déclare aucune option de moteur, contrairement aux tables d'entités
 *   de ce dépôt. Ce n'est pas cosmétique. Le transport Doctrine lit avec
 *   `FOR UPDATE SKIP LOCKED` et compte sur les transactions, et le critère central de la
 *   story 1.8 — un message n'est visible qu'après le commit de la transaction qui l'a
 *   produit — est une propriété transactionnelle. Sur un serveur client réglé avec
 *   `default_storage_engine = MyISAM`, la table serait créée sans transactions ni
 *   verrous de ligne : **aucune erreur ne serait levée**, et la garantie disparaîtrait en
 *   silence. C'est exactement le mode d'échec que la migration des comptes avait déjà
 *   rencontré sur `ADD CONSTRAINT` ;
 * - **le SQL passé en heredoc et indenté**, comme les deux migrations précédentes : une
 *   instruction `CREATE TABLE` d'une seule ligne de 400 caractères ne se relit pas, et
 *   une migration se relit ;
 * - **`down()` laissé en `DROP TABLE`**, qui est bien l'inverse exact : la table ne
 *   contient que des messages en attente, c'est-à-dire du travail que le retour arrière
 *   annule avec le code qui l'avait produit. Rien à reconstituer, aucune donnée saisie
 *   par un utilisateur. La conséquence est réelle et doit être lue : redescendre cette
 *   migration jette les messages non encore consommés, file d'échec comprise.
 *
 * Le nom d'index est celui que le transport déclare lui-même — un seul index composite
 * sur `(queue_name, available_at, delivered_at, id)`, qui est l'ordre exact de la
 * requête de dépilement. Le renommer ferait rouvrir un diff à chaque exécution de
 * `doctrine:migrations:diff`.
 */
final class Version20260914081053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table du transport Messenger, qui porte la file des emails et la file d\'échec.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE messenger_messages (
                  id BIGINT AUTO_INCREMENT NOT NULL,
                  body LONGTEXT NOT NULL,
                  headers LONGTEXT NOT NULL,
                  queue_name VARCHAR(190) NOT NULL,
                  created_at DATETIME NOT NULL,
                  available_at DATETIME NOT NULL,
                  delivered_at DATETIME DEFAULT NULL,
                  INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                DROP TABLE messenger_messages
            SQL);
    }
}
