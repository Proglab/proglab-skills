# Assets HTTP

| Fichier | Va vers | Objectif |
|---|---|---|
| `ProblemJsonEncoder.php` | `src/Serializer/` | Fait en sorte que `Accept: application/problem+json` retourne réellement du Problem Details |

## Pourquoi l'encoder n'est pas optionnel

Cette suite impose RFC 7807 pour les erreurs d'API. Symfony produit déjà le bon corps —
`ProblemNormalizer` gère la `ValidationFailedException` que lève `#[MapRequestPayload]`
— mais **aucun encoder n'est enregistré pour le format `problem`**.

Donc un client qui demande correctement reçoit une page d'erreur HTML :

```
Accept: application/problem+json
  → format de requête `problem`
  → aucun encoder ne le supporte
  → UnsupportedFormatException
  → capturée comme NotEncodableValueException par SerializerErrorRenderer
  → moteur de rendu d'erreurs HTML
```

Six lignes corrigent ça. La classe porte sa propre explication dans un commentaire
d'en-tête, car le symptôme qu'elle évite est invisible à moins que quelqu'un ne teste le
header `Accept` — et un fichier qui a l'air inerte est un fichier que quelqu'un supprime
lors d'un nettoyage.

## Vérifier que ça fonctionne

```bash
curl -s -i -X POST https://localhost/api/books \
  -H 'Accept: application/problem+json' \
  -H 'Content-Type: application/json' \
  -d '{"title":""}'
```

Attends `422`, `Content-Type: application/problem+json`, et un corps avec `type`,
`title`, `status`, `detail` et `violations`. Une page HTML signifie que l'encoder n'est
pas enregistré.

Ça mérite une assertion dans un test fonctionnel, pour que le jour où quelqu'un supprime
la classe, la suite le signale au lieu que l'API régresse silencieusement.
