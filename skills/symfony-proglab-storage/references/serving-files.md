# Servir un fichier stocké

URLs publiques, un contrôleur qui diffuse en streaming un fichier protégé, déléguer le
transfert au serveur web, URLs signées et pré-signées, et les en-têtes de cache qui
défont discrètement tout cela. Chaque comportement ci-dessous a été exécuté avec
Symfony 8.1.5 / PHP 8.4, `league/flysystem` 3.35 et `league/flysystem-async-aws-s3`
3.31. **`vendor/` fait foi, davantage que ce fichier.**

## Fichiers publics

Un fichier sous `public/` n'a besoin d'aucun PHP. `asset()` le résout :

```twig
<img src="{{ asset('uploads/covers/' ~ book.coverKey) }}" alt="">
```

Vérifié : avec AssetMapper installé, un chemin qui n'est pas un asset mappé retombe
sur le chemin de base — `asset('uploads/covers/x.jpg')` renvoie
`/uploads/covers/x.jpg`, et renvoie exactement la même chose pour un fichier qui
n'existe pas, sans aucune erreur. Un fichier supprimé donne une image cassée, jamais
une exception. C'est pourquoi le balayage dans `storing-files.md` ne supprime les
fichiers qu'*après* la disparition de la ligne, jamais avant.

Une fois le stockage devenu un bucket derrière un CDN, l'URL vient du stockage à la
place — `$covers->publicUrl($book->getCoverKey())`, avec deux pièges vérifiés :

- `publicUrl()` vit sur le `League\Flysystem\Filesystem` concret, pas sur
  `FilesystemOperator`, donc injecte
  `#[Autowire(service: 'book_covers.storage')] Filesystem`.
- Sans `public_url:` et sans support de l'adaptateur, cela lève
  `League\Flysystem\UnableToGeneratePublicUrl: … No generator was configured`. Sur
  l'adaptateur async-aws, le message se termine par
  `Client needs to be instance of SimpleS3Client` — `AsyncAws\S3\S3Client` suffit pour
  `temporaryUrl()` mais pas pour `publicUrl()`.

Préfère configurer `public_url:` par stockage : cela devient une variable
d'environnement, et le CDN peut alors être remplacé sans toucher au code.

## Fichiers protégés : un contrôleur qui autorise, puis diffuse en streaming

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
// … Response, Route, AbstractController

#[Route('/exports', name: 'export_')]
final class ReadingExportController extends AbstractController
{
    #[Route('/{id}/download', name: 'download', methods: ['GET'])]
    #[IsGranted('EXPORT_VIEW', subject: 'export')]
    public function download(ReadingExport $export, ReadingExportStorage $storage): Response
    {
        $response = new StreamedResponse(
            static function () use ($storage, $export): void {
                $out = fopen('php://output', 'w');
                stream_copy_to_stream($storage->readStream($export->getStorageKey()), $out);
            },
        );

        $response->headers->set('Content-Type', $export->getMimeType());
        $response->headers->set('Content-Length', (string) $export->getSizeInBytes());
        $response->headers->set('Content-Disposition', $storage->disposition($export));
        $response->setPrivate();

        return $response;
    }
}
```

Pourquoi cette forme :

- **`#[IsGranted]` avec `subject:`** — la réponse dépend de *cet* export précis, c'est
  donc un Voter, pas un rôle. La règle `access_control` correspondante sur `/exports`
  est le filet qui rattrape le jour où quelqu'un ajoute une action et oublie
  l'attribut. Écrire et tester le Voter relève de `symfony-proglab-security`.
- **`StreamedResponse` plutôt que `BinaryFileResponse`** — `BinaryFileResponse`
  nécessite un chemin local, qui cesse d'exister dès que le stockage devient S3. Le
  streaming depuis `readStream()` fonctionne pour les deux et ne charge jamais le
  fichier en mémoire.
- **Les en-têtes viennent de la base de données**, pas du fichier. Le `mimeType`
  stocké est le type détecté enregistré à l'upload ; le nom de fichier original est ce
  que l'utilisateur doit voir dans son dossier de téléchargements.
- **`setPrivate()`** — voir la section cache ci-dessous.

### `Content-Disposition`, et l'appel qui lève une exception

`HeaderUtils::makeDisposition()` émet les deux formes d'en-tête — un `filename=` ASCII
pour les anciens clients et un `filename*=` RFC 5987 pour les autres. Construire
l'en-tête à la main est le moyen le plus sûr de voir les noms de fichiers ressortir
altérés, et de transformer un `"` dans un nom de fichier en injection d'en-tête.

Mais **cela lève une exception sur un nom de fichier non-ASCII si aucun repli n'est
passé** :

```php
HeaderUtils::makeDisposition('attachment', 'Facture été 2026.pdf');
// InvalidArgumentException: The filename fallback must only contain ASCII characters.
```

Le repli est par défaut le nom de fichier lui-même, qui est ensuite rejeté. Cela lève
aussi une exception sur un `%` dans le repli, et sur un `/` ou `\` dans l'un ou
l'autre. `BinaryFileResponse::setContentDisposition()` masque ce problème en calculant
un repli ASCII pour toi ; une `StreamedResponse` ne le fait pas, donc c'est le service
qui le construit :

```php
public function disposition(ReadingExport $export): string
{
    $name = $export->getOriginalName();
    $stem = $this->slugger->slug(pathinfo($name, \PATHINFO_FILENAME))->toString() ?: 'download';
    $extension = pathinfo($name, \PATHINFO_EXTENSION);

    return HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        $name,
        '' !== $extension ? $stem.'.'.$extension : $stem,
    );
}
```

Vérifié pour `Facture été 2026 (100%).pdf` :

```
attachment; filename=Facture-ete-2026-100.pdf; filename*=utf-8''Facture%20%C3%A9t%C3%A9%202026%20%28100%25%29.pdf
```

Le `?: 'download'` compte : un nom entièrement non-latin se slugifie en quelque
chose, mais un nom fait uniquement de ponctuation se slugifie en une chaîne vide, et
un `filename=` vide est un en-tête malformé.

`DISPOSITION_ATTACHMENT` force un téléchargement. `DISPOSITION_INLINE` s'affiche dans
le navigateur — acceptable pour un PDF ou une image que l'application contrôle,
dangereux pour tout ce qui a la forme HTML ou SVG, puisque cela s'exécute dans
l'origine propre de l'application.

### Quand le fichier est réellement local : `BinaryFileResponse`

```php
$response = new BinaryFileResponse($absolutePath, public: false);
$response->headers->set('Content-Type', $export->getMimeType());
$response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $export->getOriginalName());
$response->setPrivate();
```

Il gère gratuitement `Range`, `Content-Length`, `Last-Modified` et les réponses
conditionnelles. Deux comportements par défaut mesurés à surcharger :

- **`public` vaut `true` par défaut.** Un cache partagé est alors autorisé à stocker
  la facture d'un utilisateur et à la servir au suivant. Mesuré, après `prepare()` :

  | Construit comme | `Cache-Control` |
  |---|---|
  | `new BinaryFileResponse($path)` | `public` |
  | `new BinaryFileResponse($path, public: false)` | `private, must-revalidate` |
  | `… public: false` puis `setPrivate()` | `private` |

  `public: false` suffit à être sûr ; `setPrivate()` ne fait que retirer le
  `must-revalidate`. Ce qui n'est jamais sûr, c'est la valeur par défaut.
- **`Content-Type` est détecté à partir des octets**, pas de l'extension ni de ce que
  tu as stocké. Mesuré : un fichier texte nommé `x.pdf` a été servi comme
  `text/plain; charset=UTF-8`. Définis l'en-tête à partir de l'entité.

`deleteFileAfterSend(true)` convient pour un fichier généré uniquement pour cette
réponse (un export temporaire dans `sys_get_temp_dir()`) et constitue un bug de perte
de données sur un fichier stocké.

## Déléguer le transfert au serveur web

Diffuser des mégaoctets en streaming à travers PHP occupe un worker pendant toute la
durée du téléchargement. Quand le fichier est local et que le serveur web peut le
lire, `X-Sendfile` sort le transfert de PHP :

```yaml
# config/packages/framework.yaml
framework:
    trust_x_sendfile_type_header: true
```

Mesuré avec ce réglage activé et une requête portant `X-Sendfile-Type: X-Sendfile` :
la réponse portait `X-Sendfile: /absolute/path/to/file` et un **corps de zéro octet**.
La vérification d'autorisation s'est toujours exécutée — seuls les octets ont été
délégués.

Trois conditions, qui doivent toutes être remplies :

- Le réglage est activé. Il vaut par défaut la variable d'environnement
  `SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER`, c'est-à-dire désactivé.
- **Le serveur web envoie `X-Sendfile-Type` sur la requête.** Symfony ne détecte pas
  le serveur, il réagit à cet en-tête. Si le serveur ne l'envoie pas, rien ne se passe
  et rien ne t'avertit.
- Pour nginx (`X-Sendfile-Type: X-Accel-Redirect`), la requête doit aussi porter
  `X-Accel-Mapping`, traduisant le préfixe du système de fichiers en un emplacement
  interne ; sans cela `prepare()` lève une `LogicException`. Cet emplacement doit être
  `internal;` dans la configuration nginx, sinon tu viens de publier le répertoire.

Ne fais confiance à cet en-tête que venant de ton propre reverse proxy : un client
capable de définir lui-même `X-Sendfile-Type` pourrait lire des chemins arbitraires.
C'est une optimisation propre au disque local — sur du stockage objet, l'équivalent
est une URL pré-signée, ci-dessous.

## URLs signées

Pour un lien qui doit fonctionner en dehors d'une session — un téléchargement envoyé
par e-mail, une cible de webhook, une `<img>` dans un client mail — Symfony signe
lui-même l'URL.

```php
use Symfony\Component\HttpKernel\Attribute\IsSignatureValid;

#[Route('/exports/{id}/download', name: 'export_download', methods: ['GET'])]
#[IsSignatureValid]
public function download(ReadingExport $export): Response
```

```php
// Dans le service qui construit le lien :
$url = $this->uriSigner->sign(
    $this->urlGenerator->generate('export_download', ['id' => $export->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
    new \DateInterval('PT10M'),
);
```

Comportement mesuré sur Symfony 8.1 :

| Requête | Statut |
|---|---|
| Correctement signée, non expirée | `200` |
| Aucun `_hash` du tout | `404` (`UnsignedUriException`) |
| `_hash` présent mais faux, ou tout paramètre altéré | `404` (`UnverifiedSignedUriException`) |
| Signature correcte, `_expiration` dans le passé | `403` (`ExpiredSignedUriException`) |

Les codes 404/403 viennent de `#[WithHttpStatus]` sur les classes d'exception
elles-mêmes, ajouté dans Symfony 7.4 aux côtés de `#[IsSignatureValid]`. **Aucun
mapping `framework.exceptions` n'est nécessaire** — une ancienne note affirmant que
cela remonte en 500 ne tient plus ; les attributs se trouvent dans
`vendor/symfony/http-foundation/Exception/`. Et un 404 plutôt qu'un 403 pour une
signature invalide est le bon choix par défaut : cela ne confirme pas l'existence de
la ressource.

**Une signature n'est pas une autorisation.** Elle prouve que le lien a été émis par
cette application et n'a pas été altéré ; elle ne dit rien sur qui le détient. Garde
donc l'expiration en minutes, pas en jours ; conserve le Voter sur la route
authentifiée par session et ne signe que des liens vers ce à quoi le destinataire a
déjà droit — un lien signé est une permission accordée à quiconque le mail est
transféré. L'URL, expiration comprise, atterrit aussi dans les journaux d'accès et les
en-têtes `Referer`.

### Replis de version

| Fonctionnalité | Depuis | Écrire à la place |
|---|---|---|
| `#[IsSignatureValid]`, et `#[WithHttpStatus]` sur les exceptions | 7.4 | Injecter `UriSigner`, vérifier en haut de l'action, lever soi-même le 404/403 |
| `UriSigner::verify()` (lève une exception) | 7.3 | `check()` / `checkRequest()`, qui renvoient un `bool` — lever soi-même le 404 |
| `$expiration` sur `sign()` | 7.1 | Signer une URL qui porte déjà son propre paramètre d'expiration, et le vérifier dans l'action |
| `Symfony\Component\HttpFoundation\UriSigner` | 6.4 | `Symfony\Component\HttpKernel\UriSigner` — dépréciée en 6.4, supprimée en 8.0 |

L'id du service est `uri_signer`, autowiré par type, et il est initialisé à partir de
`kernel.secret`. Faire tourner `APP_SECRET` invalide toute URL signée en circulation —
ce qui est une fonctionnalité quand un lien fuite, et une panne quand personne ne s'y
attendait.

## URLs pré-signées sur du stockage objet

Quand le fichier est dans un bucket, le téléchargement protégé le moins coûteux est
celui que l'application ne touche jamais : une URL que S3 honorera pendant quelques
minutes.

```php
// #[Autowire(service: 'reading_exports.storage')] private Filesystem $exports
$url = $this->exports->temporaryUrl($export->getStorageKey(), new \DateTimeImmutable('+10 minutes'));
```

Vérifié avec `league/flysystem-async-aws-s3` : le résultat est une URL
`…?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=600&…&X-Amz-Signature=…`, et la
signature est calculée localement — pas d'aller-retour réseau, donc c'est testable
hors ligne.

`temporaryUrl()` se trouve sur le `Filesystem` concret et nécessite un adaptateur
implémentant `TemporaryUrlGenerator`. `AsyncAwsS3Adapter` le fait ; l'adaptateur local
non, et lève `UnableToGenerateTemporaryUrl: … No generator was configured`. Cette
asymétrie est l'argument en faveur de garder le contrôleur en streaming comme
l'unique chemin de code, et de ne recourir aux URLs pré-signées que là où le volume
justifie un second chemin.

**Autorise avant d'émettre l'URL.** Le contrôleur qui la génère exécute toujours le
Voter ; ce que l'URL pré-signée retire, ce sont les octets qui traversent PHP, pas la
vérification.

## En-têtes de cache

| Situation | En-tête | Pourquoi |
|---|---|---|
| Couverture publique derrière un CDN | `public, max-age=…, immutable` | La clé contient des octets aléatoires, donc le contenu d'une URL ne change jamais |
| Tout ce qui est protégé | `private` (et `no-store` si cela ne doit jamais toucher le disque) | Un cache partagé conservant le fichier d'un utilisateur le servira à un autre |

`#[Cache(public: true)]` sur une action de téléchargement est une fuite qui attend son
proxy. Note aussi l'interaction mesurée dans `symfony-proglab-performance` : une fois
qu'une session a été démarrée, `SessionListener` réécrit la réponse en `private` quoi
qu'il arrive. Pratique, mais jamais la protection sur laquelle compter.

## Table des symptômes

| Symptôme | Cause |
|---|---|
| Téléchargement servi comme `text/plain` ou `application/octet-stream` | `Content-Type` non défini ; `BinaryFileResponse` a détecté les octets |
| Le nom de fichier dans le navigateur est altéré ou tronqué à un espace | `Content-Disposition` construit à la main. Utilise `HeaderUtils::makeDisposition()` |
| `InvalidArgumentException: The filename fallback must only contain ASCII characters` | `makeDisposition()` avec un nom non-ASCII et sans troisième argument |
| Un utilisateur reçoit le fichier d'un autre | Un cache partagé a stocké une réponse `public` — `BinaryFileResponse` a `public: true` par défaut |
| Workers PHP saturés pendant les téléchargements | Streaming à travers PHP. `X-Sendfile` en local, URLs pré-signées sur S3 |
| `X-Sendfile` configuré mais le corps est quand même envoyé | `trust_x_sendfile_type_header` désactivé, ou le serveur web n'envoie pas `X-Sendfile-Type` |
| `LogicException: The "X-Accel-Mapping" header must be set` | `X-Accel-Redirect` nginx sans l'en-tête de mapping |
| Toutes les URLs signées passent soudain en 404 | `APP_SECRET` a changé |
| L'URL signée renvoie un 403 | Signature correcte, expirée — régénère-la, n'allonge pas la fenêtre par défaut |
| L'URL signée fonctionne pour la mauvaise personne | Attendu. Une signature authentifie le lien, pas son détenteur |
| `UnableToGeneratePublicUrl … No generator was configured` | Pas de `public_url:` sur le stockage, et l'adaptateur ne peut pas en produire un |
