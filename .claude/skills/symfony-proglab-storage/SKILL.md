---
name: symfony-proglab-storage
description: >-
  Décider ce qu'il advient d'un fichier téléversé une fois que le contrôleur l'a reçu :
  public sous public/ ou protégé derrière un contrôleur, Flysystem pour que le
  développement local et S3 en production exécutent le même code, une clé de stockage
  générée avec le nom de fichier original conservé en base de données, une suppression
  qui ne laisse aucun fichier orphelin, et une validation qui inspecte réellement les
  octets. Utilise ce skill dès que quelqu'un demande de permettre aux utilisateurs de
  téléverser un document, d'ajouter une photo de profil ou une couverture de livre,
  d'attacher un PDF ou une facture à un enregistrement, demande "où vont les fichiers",
  veut servir ou télécharger un fichier stocké, veut que seul le propriétaire voie un
  fichier, veut ajouter S3 ou du stockage objet ou installer Flysystem — et dès qu'un
  fichier est introuvable après un déploiement, qu'un upload échoue avec un 422 vide,
  que des fichiers s'accumulent après la suppression de leur enregistrement, ou que
  l'URL d'un fichier privé s'avère devinable.
---

# Stockage de fichiers

> **Niveau : à la demande** — s'applique dès que l'application accepte un fichier. À
> partir de là, la décision public/protégé est du socle ; Flysystem et le stockage
> objet sont ce que coûte un déploiement avec plus d'un serveur applicatif.

Où va un fichier téléversé, comment il en ressort, et ce que la base de données
conserve.

`symfony-proglab-http` couvre la réception de l'upload — `#[MapUploadedFile]`, les
fichiers à l'intérieur d'un DTO de payload, les contraintes exécutées à la frontière.
Ce skill commence une ligne plus loin, au moment où un contrôleur détient un
`UploadedFile` valide et où quelque chose doit décider quoi en faire.

## Pour commencer : un fichier sous `public/` n'a aucun contrôle d'accès

```php
// Toute règle d'autorisation du projet devient maintenant sans effet pour ce fichier.
$file->move($this->getParameter('kernel.project_dir').'/public/uploads', $file->getClientOriginalName());
```

`public/` est la racine documentaire du serveur web. FrankenPHP, nginx et Apache
servent ce qu'ils y trouvent sans jamais entrer dans PHP, donc `access_control`,
`#[IsGranted]` et tous les Voters du projet sont contournés — non pas déjoués, mais
simplement jamais invoqués. Une ligne de code court-circuite tout le chapitre sécurité.

Conserver le nom de fichier original aggrave les choses :
`/uploads/contrat-durand-2026.pdf` est une URL qu'un attaquant devine plutôt qu'il ne
trouve. Et `move()` préserve l'extension envoyée par le client, donc `evil.php` finit
dans la racine documentaire d'une machine configurée pour exécuter PHP.

Deux détails vérifiés dans `vendor/`, parce qu'ils changent *quelle* précaution
compte :

- **`UploadedFile::move()` se protège bien contre la traversée de chemin (path
  traversal).** `File::getName()` normalise `\` en `/` et ne garde que ce qui suit le
  dernier slash, donc un nom de fichier client `../../public/evil.php` est écrit sous
  la forme `evil.php`. La traversée n'est pas la faille. C'est l'*extension* qui
  survit qui l'est.
- **Flysystem rejette lui aussi la traversée**, en levant
  `League\Flysystem\PathTraversalDetected` pour une clé contenant `../`. Une clé de
  stockage relue depuis la base de données ne peut donc pas non plus s'échapper de sa
  racine.

## La décision qui précède tout le reste

| | Public | Protégé |
|---|---|---|
| Exemples | Couverture de livre, avatar, image de catalogue public | Facture, contrat, export d'un lecteur, tout ce qui appartient à un seul utilisateur |
| Où | `public/uploads/…` en dev, un bucket de stockage objet avec un CDN en production | Jamais sous `public/`. Un bucket privé, ou un répertoire hors de la racine documentaire |
| Servi par | Le serveur web ou le CDN, sans PHP | Un contrôleur qui vérifie l'autorisation, puis diffuse le fichier en streaming |
| URL | Stable, peut être mise en cache publiquement | Route avec un id, ou une URL signée à courte durée de vie |

Tout ce que tu ne peux pas placer avec certitude dans la colonne de gauche appartient
à celle de droite. Faire passer un fichier de public à protégé plus tard signifie
changer son URL partout et accepter que l'ancienne URL ait déjà été crawlée ;
l'inverse ne coûte rien.

## Le disque local exige un câblage délibéré sur la cible de déploiement de cette suite

`symfony-proglab-deployment` déploie avec Deployer : chaque release vit dans son
propre répertoire, et tout ce qui est écrit sur un chemin qui n'est pas dans les
`shared_dirs` de `deploy.php` démarre vide au déploiement suivant. Un répertoire
d'upload local oublié dans cette liste produit exactement le symptôme rapporté comme
"le fichier est introuvable après un déploiement" — l'écriture a réussi, mais dans
une release qui n'existe plus. Ajouter le répertoire à `shared_dirs` corrige le
problème sur **un seul serveur applicatif** ; cela ne le corrige pas pour un second
serveur, qui possède toujours sa propre copie, distincte, du même répertoire
« partagé ».

Donc : **le stockage objet (S3 ou un service compatible) est le choix par défaut pour
tout ce qui doit survivre à un déploiement ou être visible depuis plus d'un serveur
applicatif, et le disque local — câblé via `shared_dirs` — n'est un choix légitime que
pour un déploiement mono-serveur qui a décidé de se passer de la seconde garantie.**
La façon d'avoir les deux sans deux chemins de code est Flysystem.

```bash
composer require league/flysystem-bundle              # 3.7.x, pulls league/flysystem 3.x
composer require league/flysystem-async-aws-s3 async-aws/s3   # production adapter
composer require --dev league/flysystem-memory        # in-memory adapter for tests
```

Vérifié : aucun de ces paquets n'est installé dans le projet de référence — la recette
écrit `config/packages/flysystem.yaml` avec un unique stockage local, ce qui est un
point de départ et non la configuration finale. Configuration, autowiring et pièges :
`references/storing-files.md`.

Rejeté : `vich/uploader-bundle`. Il fonctionne, mais il pilote les uploads depuis des
événements de cycle de vie Doctrine sur l'entité, exactement le type de comportement
sur une entité que ce standard exclut (voir plus bas). Également rejeté : écrire
directement dans `%kernel.project_dir%` — c'est le même code que l'adaptateur local de
Flysystem, sans aucune échappatoire vers S3.

## Ce que détient l'entité, et qui supprime le fichier

L'entité détient une **clé de stockage** et les métadonnées d'affichage. Jamais les
octets, jamais un chemin absolu — un chemin absolu cesse d'être vrai dès que le
fichier migre vers S3.

```php
#[ORM\Column(length: 255, unique: true)]
private string $storageKey;          // 2026/08/rapport-de-lecture-bbf84f80546c6e68.pdf

#[ORM\Column(length: 255)]
private string $originalName;        // Rapport de lecture (final).pdf

#[ORM\Column(length: 100)]
private string $mimeType;

#[ORM\Column]
private int $sizeInBytes;
```

Le nom original est conservé pour l'affichage et pour l'en-tête `Content-Disposition`.
Ce n'est jamais ce qui est écrit sur le disque.

**Supprimer l'entité ne supprime pas le fichier.** Quelque chose doit s'en charger, et
dans ce standard, c'est le service :

- le service qui retire l'entité retire aussi le fichier, dans cet ordre — retirer et
  `flush()` d'abord, supprimer le fichier ensuite. Un fichier supprimé avant un
  `flush()` qui échoue ensuite laisse une ligne pointant vers rien, ce qui est pire
  qu'un fichier orphelin ;
- ce que cet ordre laisse derrière lui — un `flush()` réussi suivi d'une suppression
  de fichier échouée — est balayé par une commande planifiée qui compare les clés
  stockées à la base de données.

**Un callback de cycle de vie Doctrine sur l'entité n'est pas la réponse ici**, même
si c'est la première idée qui vient. Les entités de ce standard sont des porteurs de
données au format maker, sans comportement, précisément pour que chaque règle vive
dans un service où elle peut être testée unitairement et où lire le service raconte
tout le flux. Un hook `postRemove` se déclenche depuis `flush()`, loin du code qui a
demandé la suppression, et ne peut pas être testé sans base de données. Le balayage
planifié est le filet de sécurité, pas le mécanisme.

Code concret pour les deux, plus la requête de balayage des orphelins :
`references/storing-files.md`. La mécanique propre à la commande (dry-run par défaut,
verrouillage) relève de `symfony-proglab-console` ; sa planification relève de
`symfony-proglab-async`.

## Une validation qui inspecte le fichier plutôt que de faire confiance au client

L'extension et le `Content-Type` envoyé par le navigateur sont tous deux contrôlés par
l'attaquant. Mesuré sur un script PHP renommé `invoice.pdf` et annoncé comme
`application/pdf` :

| Contrainte | Résultat |
|---|---|
| `#[Assert\File(maxSize: '1M')]` | **accepté** |
| `#[Assert\File(extensions: ['pdf'])]` | rejeté — `The mime type of the file is invalid ("text/x-php")` |
| `#[Assert\File(mimeTypes: ['application/pdf'])]` | rejeté — même type détecté |

`extensions:` est l'option à privilégier : elle vérifie l'extension *et* dérive les
types de média correspondants depuis `symfony/mime`, puis vérifie le type détecté par
rapport à eux. `UploadedFile::getMimeType()` relit le fichier avec `finfo` ;
`getClientMimeType()` renvoie ce que le navigateur a déclaré, et a renvoyé
`application/pdf` pour ce script.

**`#[Assert\Image]` accepte le SVG par défaut.** Vérifié : un SVG contenant
`<script>alert(1)</script>` passe `#[Assert\Image(maxWidth: 2000)]`, car `Image`
définit par défaut `mimeTypes` à `image/*` quand ni `mimeTypes` ni `extensions` n'est
défini, et `image/svg+xml` correspond à ce joker. Servi en ligne depuis `public/`,
c'est une XSS stockée. Écris `#[Assert\Image(extensions: ['jpg', 'png', 'webp'])]` à
moins que le SVG ne soit réellement souhaité, et si c'est le cas, sers-le en pièce
jointe.

### Les limites propres à PHP interviennent avant même que Symfony ne voie quoi que ce soit

Deux limites différentes avec deux modes d'échec très différents, toutes deux
mesurées :

| Situation | Ce que fait PHP | Ce que voit l'utilisateur |
|---|---|---|
| Plus grand que `upload_max_filesize` | Entrée `$_FILES` présente avec `error = UPLOAD_ERR_INI_SIZE`, `$_POST` intact | Un vrai message de validation — `Assert\File` rapporte `uploadIniSizeErrorMessage` |
| Plus grand que `post_max_size` | **`$_POST` et `$_FILES` sont tous deux vides**, seul `CONTENT_LENGTH` survit | Un 422 nu sans message, ou « le jeton CSRF est invalide » |

Le second cas est le rapport de bug qui se lit « l'upload ne fait rien ». Un argument
`#[MapUploadedFile]` non nullable sans fichier se résout en `null`, et le resolver
lève `HttpException::fromStatusCode(422)` avec un message vide — rien dans la réponse
n'indique qu'une limite a été atteinte. Règle `post_max_size` au-dessus de
`upload_max_filesize`, elle-même au-dessus du plus grand `maxSize` de l'application,
et détecte le cas explicitement quand cela compte :
`$request->headers->get('Content-Length') > 0 && !$request->request->count() && !$request->files->count()`.

## Quand quelque chose ne fonctionne pas

| Symptôme | Cause |
|---|---|
| Le fichier renvoie une 404 après un déploiement | Écrit dans un répertoire de release absent des `shared_dirs` de `deploy.php`. Ajoute-le, ou migre vers le stockage objet |
| Fonctionne sur une requête, 404 sur la suivante | Plusieurs réplicas, disque local sur chacun |
| Un fichier privé est accessible sans connexion | Il est sous `public/`. Aucun `access_control` ne changera cela |
| L'upload « ne fait rien », 422 vide ou erreur CSRF | Corps supérieur à `post_max_size` ; la requête est arrivée vide |
| « The file is too large » alors que le fichier est petit | `maxSize` est comparé à une taille déjà tronquée par PHP — vérifie d'abord `upload_max_filesize` |
| Un fichier `.php` ou `.svg` a été accepté | `maxSize` seul, ou `Assert\Image` sans `extensions:` |
| Les fichiers s'accumulent dans le bucket | Rien ne les supprime à la suppression de l'entité, et aucun balayage n'existe |
| Un téléchargement renvoie le mauvais `Content-Type` | `BinaryFileResponse` détecte le *contenu*, pas le type MIME stocké — définis l'en-tête |
| Un téléchargement protégé est mis en cache par un proxy | `new BinaryFileResponse(...)` a `public: true` par défaut |

## Hors périmètre

- **Recevoir l'upload** — `#[MapUploadedFile]`, les fichiers dans un DTO de payload,
  les repli de version : `symfony-proglab-http`, `references/input-mapping.md`.
- **Traitement d'image** — redimensionnement, miniatures, conversion de format. Non
  couvert par cette suite ; cela nécessite une bibliothèque (`intervention/image`,
  `liip/imagine-bundle`) et une décision sur le fait de faire ce travail dans un
  handler Messenger. Dis-le plutôt que d'improviser.
- **Voters** — comment en écrire et en tester un : `symfony-proglab-security`. Ce
  skill dit seulement où l'appeler.
- **La commande de balayage elle-même** — dry-run/`--force`, verrouillage, test de
  fumée : `symfony-proglab-console`. Sa planification : `symfony-proglab-async`.

## Gestion des versions

Flysystem n'est pas un composant Symfony et suit ses propres versions ; les faits
présentés ici ont été vérifiés avec `league/flysystem` 3.35 et
`league/flysystem-bundle` 3.7, installés aux côtés de Symfony 8.1. `#[IsSignatureValid]`
nécessite Symfony 7.4 et `Symfony\Component\HttpFoundation\UriSigner` nécessite 6.4 —
les replis sont dans `references/serving-files.md`. Tout le reste ici (`Assert\File`,
`Assert\Image`, `BinaryFileResponse`, `UploadedFile`) est disponible de 6.4 à 8.x.
**`vendor/` fait foi, davantage que ce fichier.**

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/storing-files.md` | Configurer Flysystem, nommer le fichier stocké, ce que détient l'entité, supprimer sans orphelins, tester un upload sans toucher au stockage réel |
| `references/serving-files.md` | Faire ressortir le fichier : URLs publiques, un contrôleur en streaming, `X-Sendfile` / `X-Accel-Redirect`, URLs signées et URLs pré-signées S3, en-têtes de cache |
