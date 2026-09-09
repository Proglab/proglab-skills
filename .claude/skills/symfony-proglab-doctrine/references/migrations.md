# Migrations

Une migration générée est un brouillon. Ce qu'il faut vérifier avant de l'exécuter, et ce
que le générateur ne peut pas savoir. Vérifié avec doctrine/migrations 3.9 et
doctrine-migrations-bundle 4.0.

## Sommaire

- [La boucle](#la-boucle)
- [Ce que le générateur fait réellement](#ce-que-le-générateur-fait-réellement)
- [Le renommage qui supprime une colonne](#le-renommage-qui-supprime-une-colonne)
- [Une colonne NOT NULL sur une table peuplée](#une-colonne-not-null-sur-une-table-peuplée)
- [Changements de type](#changements-de-type)
- [down()](#down)
- [Migrations de données](#migrations-de-données)
- [Transactions, et où elles n'existent pas](#transactions-et-où-elles-nexistent-pas)
- [Plateformes](#plateformes)
- [Grandes tables: ce qui verrouille vraiment](#grandes-tables-ce-qui-verrouille-vraiment)
- [Relire une migration](#relire-une-migration)
- [CI et déploiement](#ci-et-déploiement)

## La boucle

```bash
php bin/console make:migration            # ou doctrine:migrations:diff
$EDITOR migrations/VersionYYYYMMDDHHMMSS.php
php bin/console doctrine:migrations:migrate
php bin/console doctrine:schema:validate
```

L'étape d'édition n'est pas optionnelle ni une formalité. C'est là que la migration cesse
d'être un diff de deux schémas et devient un plan pour déplacer de vraies données.

## Ce que le générateur fait réellement

Il introspecte la base de données en direct, construit le schéma que la mapping décrit,
les compare, et émet le SQL qui transforme le premier en le second. C'est tout. Il n'a
accès ni :

- **aux lignes.** Il ne peut pas savoir qu'une colonne contient des données, donc il
  n'hésite jamais à en supprimer une.
- **à ton intention.** Une propriété renommée de `name` à `title` est, pour un diff de
  schéma, une colonne disparue et une colonne apparue.
- **à l'ordre de déploiement.** Il suppose que tout le changement arrive d'un coup.
- **à aucune plateforme autre que la tienne.** Le SQL est généré pour la base qu'il a
  introspectée. Si les développeurs utilisent SQLite et que la production utilise MySQL,
  le fichier dans `migrations/` est du SQL SQLite et ne s'exécutera pas en production.
  Génère les migrations sur le même moteur que celui déployé — c'est l'argument pratique
  le plus fort pour avoir MySQL en local plutôt que SQLite (`symfony-proglab-local-dev`).

## Le renommage qui supprime une colonne

```php
// généré
$this->addSql('ALTER TABLE book ADD title VARCHAR(255) NOT NULL');
$this->addSql('ALTER TABLE book DROP name');
```

C'est du SQL correct, ça passe la CI sur une base de test vide, et chaque titre en
production a disparu. Quand des lignes existent, le `NOT NULL` sur le `ADD` échoue
d'abord et transforme la perte de données en déploiement en échec — ce qui est de la
chance, pas un filet de sécurité, et ça ne s'applique pas au cas nullable.

Le correctif tient en une ligne :

```php
$this->addSql('ALTER TABLE book RENAME COLUMN name TO title');
```

Pareil pour une table renommée (`ALTER TABLE … RENAME TO …`). Chaque `DROP` dans une
migration générée mérite la question : est-ce vraiment parti, ou est-ce en train de se
déplacer ?

## Une colonne NOT NULL sur une table peuplée

```php
// généré — échoue dès que la table n'est plus vide
$this->addSql('ALTER TABLE book ADD isbn VARCHAR(20) NOT NULL');
```

Trois instructions, dans cet ordre :

```php
$this->addSql('ALTER TABLE book ADD isbn VARCHAR(20) DEFAULT NULL');
$this->addSql("UPDATE book SET isbn = '' WHERE isbn IS NULL");
$this->addSql('ALTER TABLE book MODIFY isbn VARCHAR(20) NOT NULL');
```

(`MODIFY`, pas `ALTER COLUMN … SET NOT NULL` — cette syntaxe est celle de PostgreSQL. Le
`MODIFY` de MySQL réécrit toute la définition de la colonne, donc recopie le type depuis
la mapping plutôt que de le deviner.)

Combler avec une valeur par défaut est une décision, pas un réflexe — parfois la réponse
honnête est que la colonne reste nullable jusqu'à ce que la donnée existe. Prends cette
décision explicitement ; ne laisse pas un déploiement en échec la prendre à ta place à
18h.

## Changements de type

Un changement qui élargit (`VARCHAR(20)` → `VARCHAR(255)`, `int` → `bigint`) est sûr. Un
changement qui rétrécit tronque ou lève une erreur selon la plateforme, et le générateur
l'émet sans commentaire. `text` → `varchar(255)`, `datetime` → `date`,
`decimal(10,2)` → `decimal(10,0)` perdent tous silencieusement de l'information sur au
moins un moteur.

Vérifie d'abord les données :

```sql
SELECT COUNT(*) FROM book WHERE LENGTH(isbn) > 20;
```

Si la réponse n'est pas zéro, la migration a besoin d'une étape de données avant le
changement de type.

## down()

Le générateur écrit l'inverse mécanique. C'est correct pour un ajout de colonne et faux
pour tout ce qui a détruit de l'information : le `down()` d'une colonne supprimée recrée
la colonne, vide.

Deux options honnêtes. Écrire un `down()` qui inverse réellement le changement quand
c'est possible, ou appeler `$this->throwIrreversibleMigrationException('…')` quand ce ne
l'est pas. Ce qui n'est pas honnête, c'est un `down()` qui paraît restaurer quelque chose
et restaure une coquille vide — quelqu'un l'exécutera pendant un incident et croira que ça
a marché.

## Migrations de données

`addSql()` accepte des paramètres liés, ce qui est la façon sûre d'écrire un
rétro-remplissage :

```php
public function up(Schema $schema): void
{
    $this->addSql(
        'UPDATE book SET status = :new WHERE status = :old',
        ['new' => 'in_progress', 'old' => 'reading'],
    );
}
```

Pour tout ce qui doit lire avant d'écrire, `$this->connection` est disponible (une
`Doctrine\DBAL\Connection`, protégée sur `AbstractMigration`) :

```php
foreach ($this->connection->iterateAssociative('SELECT id, isbn FROM book') as $row) {
    $this->addSql(
        'UPDATE book SET isbn = :isbn WHERE id = :id',
        ['isbn' => normalise($row['isbn']), 'id' => $row['id']],
    );
}
```

Utilise DBAL, pas l'entity manager. Une migration qui hydrate des entités dépend de la
mapping d'aujourd'hui, et elle cassera le jour où l'entité changera à nouveau — à ce
moment-là, la migration ne se rejoue plus sur une base fraîche, ce qui est la seule chose
qu'une migration doit toujours pouvoir faire.

Un rétro-remplissage sur des millions de lignes n'a pas sa place dans une migration : le
déploiement l'attend et la table peut être verrouillée pendant toute la durée. Livre le
changement de schéma dans la migration et le rétro-remplissage comme une commande console
avec `--force` et un traitement par lots (voir `symfony-proglab-console`).

## Transactions, et où elles n'existent pas

`AbstractMigration::isTransactional()` retourne `true` par défaut, et
`doctrine:migrations:migrate --all-or-nothing` enveloppe toute l'exécution dans une seule
transaction — mais **MySQL et MariaDB n'ont pas de DDL transactionnel.** Chaque
`ALTER TABLE` valide implicitement, indépendamment de la transaction que Doctrine
enveloppe autour, donc une migration qui échoue à sa troisième instruction laisse les deux
premières appliquées et la ligne de version non écrite — l'exécution suivante rejoue
depuis le début et échoue sur « column already exists ». Garde les migrations assez
petites pour qu'une application partielle soit diagnosticable, et attends-toi à devoir
réparer à la main. (PostgreSQL est le moteur où la transaction te protège vraiment : le
DDL y est transactionnel, donc un échec à mi-chemin ne laisse rien derrière lui — bon à
savoir si une migration de référence ou un extrait trouvé en ligne le suppose.)

**Elles ne sont pas indépendantes.** `DbalMigrator` appelle
`assertAllMigrationsAreTransactional()` *avant* d'ouvrir la transaction externe, donc sous
`--all-or-nothing`, une seule migration retournant `isTransactional(): false` lève
`MigrationConfigurationConflict::migrationIsNotTransactional` et **toute l'exécution est
annulée sans rien exécuter**. Garde ça en tête avant de sortir une migration de ce mode
(voir « Grandes tables », plus bas) : l'exclusion et le flag ne peuvent pas être livrés
dans la même invocation.

## Plateformes

DBAL 4 a renommé les classes de plateforme : `SQLitePlatform` (était `SqlitePlatform`),
avec `PostgreSQLPlatform`, `MySQLPlatform`, `MariaDBPlatform`, `SQLServerPlatform`,
`OraclePlatform` dans `Doctrine\DBAL\Platforms`. Une garde copiée depuis un ancien projet
plantera avec l'ancien nom.

```php
$this->abortIf(
    ! $this->connection->getDatabasePlatform() instanceof MySQLPlatform,
    'This migration is written for MySQL.',
);
```

Vaut la peine d'être ajouté quand une migration contient du SQL spécifique à la
plateforme — `ALGORITHM=INPLACE`, une fonction de colonne `JSON`, un index fulltext. Pas
la peine de l'ajouter à chaque fichier : c'est du bruit sur un projet avec un seul moteur,
et le vrai correctif pour une configuration mixte est d'arrêter d'en avoir une.

## Grandes tables: ce qui verrouille vraiment

Tout ce qui précède suppose que la table est assez petite pour qu'un verrou soit
invisible. Au-delà de quelques millions de lignes, il cesse d'être invisible, et le
déploiement qui prenait quatre secondes en préproduction met le site à l'arrêt.

La règle déjà énoncée dans ce standard — les migrations s'exécutent au déploiement, une
courte interruption est acceptable — tient jusqu'à ce point et pas au-delà. Reconnaître le
moment où on l'a dépassé est tout le travail de la relecture.

### Ce qui est bon marché et ce qui réécrit la table

| Opération | MySQL 8 (InnoDB) | PostgreSQL |
|---|---|---|
| `ADD COLUMN` nullable, sans défaut | Instantané (algorithme INSTANT) | Métadonnées seulement |
| `ADD COLUMN` avec une valeur par défaut constante | Instantané (algorithme INSTANT) | Métadonnées seulement depuis **PG 11** — réécriture complète avant |
| `ADD COLUMN` avec une valeur par défaut volatile (`NOW()`, `AUTO_INCREMENT`) | Copie complète | Réécriture complète |
| `MODIFY … NOT NULL` | Copie complète | Scan complet pour vérifier |
| `MODIFY` changeant le type | Copie complète, table verrouillée en écriture sauf si `ALGORITHM=INPLACE` s'applique | Réécriture, sauf quelques cas d'élargissement |
| `ADD INDEX` | En ligne (`INPLACE, LOCK=NONE`) depuis 5.6, mais toujours coûteux | Bloque les **écritures** pendant la durée sauf `CONCURRENTLY` |
| `DROP COLUMN` | Instantané | Métadonnées seulement |

Le générateur ne sait rien de tout ça. Il émet du SQL correct pour une base vide et n'a
aucune idée que la table contient quarante millions de lignes.

### DDL en ligne, et pourquoi il ne demande aucune gymnastique de transaction

InnoDB prend en charge `ALGORITHM=INPLACE, LOCK=NONE` pour beaucoup d'opérations
`ALTER TABLE` — l'ajout d'index en tête de liste — ce qui permet aux lectures et écritures
de continuer pendant la construction de l'index. Le générateur de Doctrine n'ajoute jamais
cette indication ; ajoute-la à la main pour une table en production :

```php
$this->addSql(
    'ALTER TABLE book ADD INDEX idx_book_status (status), ALGORITHM=INPLACE, LOCK=NONE'
);
```

Contrairement au `CREATE INDEX CONCURRENTLY` de PostgreSQL, ça ne demande aucune
gymnastique avec l'exclusion `isTransactional(): false` : MySQL n'a de toute façon pas de
DDL transactionnel (voir plus haut), donc envelopper cette instruction dans la transaction
de Doctrine ne change rien à son exécution. Ce qui est nécessaire en revanche, c'est la
discipline déjà énoncée pour tout DDL sur ce moteur — une opération par migration, pour
qu'un échec en cours d'exécution soit diagnosticable plutôt que semi-appliqué au milieu de
changements sans rapport.

L'indication est aussi un filet de sécurité en soi : si l'algorithme ou le mode de
verrouillage demandé n'est en réalité pas pris en charge pour ce changement précis, MySQL
**rejette l'instruction purement et simplement** —
`Error: 1846 ALGORITHM=INPLACE is not supported. Reason: … Try ALGORITHM=COPY.` — plutôt
que de retomber silencieusement sur une copie complète de la table. Lis la raison, décide
si un `ALGORITHM=COPY` bloquant est acceptable pour la taille de cette table, et retire
l'indication seulement à ce moment-là. Un `ALTER` en ligne de longue durée est visible
dans `SHOW PROCESSLIST` pendant son exécution.

### Quand la migration doit sortir du déploiement

À un moment donné, un changement ne peut plus s'exécuter pendant que le déploiement
attend. Le signal est simple : **si tu ne peux pas dire combien de temps ça prend, ça n'a
pas sa place dans le déploiement.**

Sépare-le en deux, ce qui est la forme expand/contract que `symfony-proglab-deployment`
décrit :

1. une migration qui ne fait qu'ajouter — colonne nullable, index construit avec
   `ALGORITHM=INPLACE, LOCK=NONE` — rapide, sûre, réversible, s'exécute avec le
   déploiement ;
2. une **commande console** pour les données elles-mêmes, par lots, reprenable, protégée
   par `--force`, exécutée quand quelqu'un surveille (voir `symfony-proglab-console`).

Un rétro-remplissage écrit comme une commande par lots a une propriété qu'une migration
n'a pas : tu peux l'arrêter, regarder ce qu'elle a fait, et redémarrer là où elle s'est
arrêtée. Une migration est tout-ou-rien par construction, ce qui est la mauvaise forme
pour huit millions de lignes.

Ensuite, à un déploiement ultérieur, l'étape de contraction : `SET NOT NULL`, suppression
de l'ancienne colonne. À ce moment-là, la donnée est déjà correcte, donc l'opération est
courte.

## Relire une migration

Lis-la comme un diff, avec ces questions :

| Cherche | Demande-toi |
|---|---|
| `DROP COLUMN` / `DROP TABLE` | C'est vraiment parti, ou renommé ? |
| `NOT NULL` sur un `ADD` | La table est-elle peuplée ? |
| Un changement de type | Les valeurs existantes tiennent-elles encore ? |
| `DROP INDEX` | Une requête en dépend-elle ? |
| `CREATE INDEX` manquant | Nouvelle clé étrangère ou colonne de filtre sans index |
| `down()` | Est-ce que ça inverse vraiment, ou est-ce que ça en a juste l'air ? |
| Nombre d'instructions | Y a-t-il de quoi verrouiller la table pendant le déploiement ? |
| Nombre de lignes de la table cible | Le connais-tu ? Si non, tu ne peux pas savoir combien de temps ça prend |
| `ADD INDEX` sur une table peuplée | A-t-elle besoin de `ALGORITHM=INPLACE, LOCK=NONE` ? |
| Un `UPDATE` sans `WHERE` limité | Combien de lignes, et le déploiement attend-il qu'elles soient toutes traitées ? |

Donne à la classe un vrai `getDescription()`. `doctrine:migrations:list` l'affiche, et
c'est le seul contexte que quiconque obtient quand une migration échoue en production dans
l'urgence.

`--dry-run` et `--write-sql` affichent le SQL sans l'exécuter — utile pour relire face à
une copie de la production, et pour donner quelque chose à lire à un DBA.

## CI et déploiement

```bash
php bin/console doctrine:migrations:up-to-date --fail-on-unregistered
php bin/console doctrine:schema:validate
```

La première échoue quand une migration existe que la base n'a pas encore vue — y compris
une ajoutée par une fusion, ce qui est exactement le cas que personne ne remarque. La
seconde échoue quand la mapping et la base ont divergé, ce qui arrive quand quelqu'un
édite une entité et oublie de générer la migration. Elle vérifie aussi, depuis ORM 3, que
les types de propriétés PHP correspondent à leurs types Doctrine ; `--skip-sync`,
`--skip-mapping` et `--skip-property-types` permettent de restreindre le contrôle quand un
seul des trois est voulu.

Exécute les migrations en CI **contre le schéma précédent**, pas contre un schéma
fraîchement créé. Une migration qui fonctionne sur une base vide et échoue sur de vraies
données est exactement le mode d'échec que ce document décrit, et seul un schéma de départ
réel le détecte.

Au déploiement, les migrations s'exécutent automatiquement et sans contrainte de
rétrocompatibilité — une courte interruption est acceptable si une migration est
incompatible avec le code en cours d'exécution. La séquence complète de déploiement et la
place des migrations dedans appartiennent à `symfony-proglab-deployment`.

Et la règle qui n'a aucune exception : **`doctrine:schema:update` ne s'exécute jamais
contre la production.** Elle répond à « fais correspondre cette base à la mapping » par
tous les moyens, y compris en supprimant ce qu'elle ne reconnaît pas, et elle ne laisse
aucune trace de ce qui s'est passé.
