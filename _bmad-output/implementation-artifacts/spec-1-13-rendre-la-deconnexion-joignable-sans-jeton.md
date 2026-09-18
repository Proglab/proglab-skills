---
title: 'Rendre la déconnexion joignable sans jeton'
type: 'bugfix'
created: '2026-09-18'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '040ceb89b9a3aba72645bf04a745f645cbc343db'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** `/logout` répond **403** à toute requête qui ne vient pas d'un `logout_path()`
rendu dans la session courante — favori, lien recopié, page servie par un cache, session
expirée : précisément quand on veut se déconnecter. Et le jeton qui cause ce refus voyage
en query string d'un GET, donc dans les journaux d'accès et les `Referer`.

**Approche :** appliquer la seule exception nommée d'AD-18, déjà tranchée en amont
(`sprint-change-proposal-2026-09-14.md`, appliquée à AD-18, NFR-1 et AR-15). Retirer le
jeton, réécrire sur place la justification qui affirme le contraire, inverser le test qui
exigeait le 403, et laisser partir le helper de test qui n'existait que pour fabriquer
l'URL signée.

## Boundaries & Constraints

**Always :**

- L'exception est **nommée et justifiée dans `config/packages/security.yaml`** : ce que
  `SameSite=Lax` ferme déjà, ce qu'il laisse passer, pourquoi le résidu est accepté. Le
  commentaire qui invoque `<img src="…/logout">` disparaît.
- Le test qui exigeait le 403 est **inversé, pas supprimé** : la couverture reste.
- Les tests s'écrivent d'abord et se voient rouges.
- Aucun fichier du socle n'affirme plus « CSRF sans exception », les deux commentaires
  Twig compris.
- `a_missing_csrf_token_is_refused_like_any_other_failure()` reste **intact** : c'est le
  garde-fou contre la lecture « le CSRF s'assouplit ».

**Never :**

- Aucune autre écriture exemptée : pas de `stateless_token_ids`, pas de POST sur
  `/logout`, ni `clear_site_data` ni `target` touchés.
- Aucune édition de `epics.md:532` ni des specs 1.6 et 1.7 — archives de stories closes ;
  la trace du renversement vit dans AD-18 et ici (décidé en amont, §« Artefacts dérivés »).
- Aucune entrée de `deferred-work.md` modifiée ; aucune cible `make`, job CI ou testsuite
  ajoutés.
- Rien sur le lien de déconnexion : aucun template n'en rend encore un (story 2.3).

## I/O & Edge-Case Matrix

| Scénario | Entrée / état | Comportement attendu | Erreur |
|---|---|---|---|
| Déconnexion nue | session ouverte, `GET /logout` sans paramètre | session fermée, redirection vers `/` | jamais 403 |
| Lien rendu | `logout_path()`, pare-feu `main` | chemin sans query string | — |
| Visiteur anonyme | `GET /logout` sans session | redirection | jamais 403 ni 500 |
| Connexion | POST `/login` sans `_csrf_token` | refusée comme tout échec (422, message générique) | inchangé |

</frozen-after-approval>

## Code Map

- `config/packages/security.yaml:88-95` — siège du changement : `logout.enable_csrf: true`
  et les cinq lignes qui le justifient par l'attaque `<img>`. **Ne pas toucher `:55`**
  (`form_login.enable_csrf`).
- Mécanique côté vendor : sans `enable_csrf`, `MainConfiguration:231-241` ne pose aucun
  `csrf_token_manager`, donc `SecurityExtension:488-498` en passe `null` à
  `registerListener()` et `LogoutUrlGenerator` ne produit aucun paramètre. Le chemin étant
  un **nom de route** (`app_logout`), il passe par le routeur : ni pile de requêtes ni
  session requises.
- `tests/Core/Security/FirewallUrls.php` — **à supprimer en entier** ; ses trois appelants
  disparaissent ici.
- `tests/Core/Security/LoginTest.php:288-318` — les deux tests de déconnexion.
  `:232` `a_missing_csrf_token_is_refused_like_any_other_failure()` : intact.
- `tests/Core/Security/LoginThrottlingTest.php:384` — second appelant de `logoutPath()` ;
  même namespace, donc aucun import à retirer.
- `tests/Core/Accessibility/AccessibilityFloorTest.php:8` (import) et `:845-878` — le bloc
  `neverReturns()` : dix lignes sur le rejeu signé, et un `if (!…->isRedirect())` qui
  devient mort. L'assertion de redirection reste.
- `templates/security/login.html.twig:133` et `templates/password/request.html.twig:108` —
  « AD-18, sans exception ».
- `sprint-change-proposal-2026-09-14.md` — la décision amont, **déjà appliquée** côté
  planification : rien à rééditer là-bas.

## Tasks & Acceptance

**Execution :**

- [x] `tests/Core/Security/LoginTest.php` — faire passer `logging_out_closes_the_session()`
      par `/logout` nu, et inverser `logging_out_without_its_csrf_token_is_refused()` en un
      test qui prouve que le lien rendu ne porte plus de jeton
      (`security.logout_url_generator`, pare-feu `main` nommé, aucune query string).
      **Écrits d'abord, vus rouges.**
- [x] `config/packages/security.yaml` — `enable_csrf: false` écrit en toutes lettres, et
      commentaire réécrit : l'exception nommée, ses trois raisons, le résidu assumé.
- [x] `tests/Core/Security/LoginThrottlingTest.php` — `/logout` nu.
- [x] `tests/Core/Accessibility/AccessibilityFloorTest.php` — retirer import, rejeu signé
      et commentaire ; le premier appel suffit.
- [x] `tests/Core/Security/FirewallUrls.php` — supprimer.
- [x] les deux templates — remplacer « AD-18, sans exception » par la formule qui nomme
      l'exception sans l'étendre.

**Acceptance Criteria :**

- Given un client qui n'a jamais rendu de `logout_path()`, when il fait `GET /logout` sans
  paramètre, then la session est fermée et la réponse redirige — jamais 403.
- Given le plancher d'accessibilité, when il découvre `app_logout`, then il obtient une
  redirection au premier appel, sans rejeu signé.
- Given `grep -rn "sans exception" config/ src/ templates/`, then plus aucune ligne ne
  rattache la formule à AD-18.
- Given `make qa`, then les six catégories passent sans cible ni job ajouté.

## Implementation Notes

**Le test inversé s'appelle `the_logout_link_the_application_renders_carries_no_token()`.**
Il demande `/logout` à `security.logout_url_generator` avec le pare-feu `main` nommé, et
affirme deux choses : aucune query string (`parse_url(…, PHP_URL_QUERY)` vaut `null`) et
le chemin nu `/logout` — soit exactement l'URL que `logging_out_closes_the_session()`
appelle. Les deux tests parlent donc de la même URL sans être jumeaux.

**Rouge observé avant la config.** `logging_out_closes_the_session()` a échoué en
assertion (« Failed asserting that the Response is redirected », 403 *Invalid CSRF
token*). Le test inversé a échoué en **erreur** plutôt qu'en assertion :
`SessionNotFoundException` levée depuis `LogoutUrlGenerator.php:86`, c'est-à-dire la ligne
`$csrfTokenManager->getToken(…)` que le changement supprime. Le standard de test préfère
un rouge d'assertion ; ici l'erreur vient précisément du mécanisme retiré, et la
vérification par mutation ci-dessous referme le doute.

**Vérification par mutation.** `enable_csrf` remis à `true`, cache de test vidé : les deux
tests de déconnexion repassent rouges, et les 22 tests du plancher d'accessibilité
échouent tous sur « la route « app_logout » (/logout) déclare `: never` mais ne répond pas
une redirection (403) ». Restauré à `false`, tout est vert.

**Un commentaire hors Code Map réécrit.** `config/packages/security.yaml:53-54` affirmait
« AD-18 ne souffre aucune exception » au-dessus de `form_login.enable_csrf`. La contrainte
« aucun fichier du socle n'affirme plus “CSRF sans exception” » l'emporte sur l'inventaire
du Code Map, qui ne listait que les deux commentaires Twig. La clé `:55` elle-même n'a pas
été touchée, conformément à l'interdiction.

**Ce que le `grep` laisse debout.** `src/Core/Service/EmailSender.php:146` et
`src/Core/Service/PasswordResetRequest.php:23` contiennent encore « sans exception », au
sens PHP du terme (« ne lève pas d'exception »). Aucun des deux ne rattache la formule à
AD-18 ; le critère d'acceptation est satisfait.

## Spec Change Log

## Review Triage Log

**Itération 1 — 7 points, tous appliqués.**

- `config/packages/framework.yaml` : `cookie_samesite: lax` écrit en toutes lettres sous
  `framework.session`, avec le commentaire disant que l'exception de déconnexion en dépend
  et qu'y toucher rouvre la décision d'architecture. Le commentaire de `security.yaml` ne
  renvoie plus à une absence.
- `tests/Core/Security/LoginTest.php` : nouveau test
  `the_session_cookie_keeps_the_samesite_policy_the_logout_exception_rests_on()` sur le
  paramètre `session.storage.options['cookie_samesite']`. C'est lui qui porte désormais la
  garantie que le test à 403 tenait.
- `security.yaml` : la fermeture est scopée au vecteur sous-ressource qu'elle nomme ;
  `Lax` « atténue le reste du CSRF sans le remplacer » (standard, `hardening.md:222`).
- `security.yaml` : le paragraphe « résidu » nomme le second résidu — le prefetch au survol
  de Turbo 8 (`importmap.php`) — et sa parade `data-turbo-prefetch="false"`, à poser sur le
  lien de la story 2.3.
- `security.yaml` : « juste au-dessus » retiré (`csrf_token_id` est à `:60`, la phrase à
  `:119`).
- `logging_out_closes_the_session()` : `assertResponseRedirects('/')` épingle la cible de
  la matrice ; un `logout.target` ajouté rougit désormais (vérifié par mutation).
- `the_logout_link_the_application_renders_carries_no_token()` : comparaison au chemin que
  le routeur génère pour `app_logout`, plus au littéral `/logout`.

Revue 1 (2026-09-18) — couches : blind-hunter, edge-case-hunter, verification-gap, proglab-conformance.

| # | Constat (source) | Verdict | Preuve | Route |
|---|---|---|---|---|
| R1 | Rien dans le dépôt ne tient `SameSite=Lax`, sur quoi l'exception repose désormais seule (blind, edge-case, verification-gap, conformance) | medium | `config/packages/framework.yaml` ne porte que `secret` et `session: true` ; le commentaire de `security.yaml:95-99` le cite comme preuve et le lecteur y trouve une absence. `debug:container --parameter=session.storage.options` rend bien `cookie_samesite: lax`, mais par défaut du framework. Aucun test sous `tests/` ne mentionne `samesite`. Poser `cookie_samesite: none` laisse les 501 tests verts. | patch |
| R2 | Le test à 403 supprimé n'est remplacé par rien de comportemental : la couverture « contrefaçon » est devenue de la prose (blind, edge-case ×2, conformance) | medium | Même défaut que R1 : la garantie a changé de porteur (jeton → attribut de cookie) et le nouveau porteur n'est tenu par aucune assertion. Un test « requête sans cookie de session » ne prouverait rien — le serveur n'applique pas `SameSite`, le navigateur si. Le seul test honnête côté serveur est celui de R1. | patch (groupé avec R1) |
| R3 | « la contrefaçon est close sans jeton » affirme une fermeture plus large que le standard du projet ne l'accorde (conformance) | low | `.claude/skills/symfony-proglab-security/references/hardening.md:222` : « `SameSite=Lax` […] atténue le risque sans le remplacer ». La phrase est juste pour la sous-ressource — le cas `<img>` qu'elle nomme — mais se lit comme une fermeture générale. Correction de portée, pas de fond : AD-18 dit la même chose et nomme déjà le résidu. | patch |
| R4 | « Le résidu, assumé » omet le prefetch au survol de Turbo 8 (blind, edge-case) | medium | `importmap.php:33-35` épingle `@hotwired/turbo` 8.0.23, dont le prefetch au survol est actif par défaut. Aucun template ne rend de lien de déconnexion aujourd'hui ; le premier — la coque de la story 2.3 — fermera la session au survol de l'entrée de menu. Le danger est antérieur au changement (un lien signé se prefetchait aussi), mais le paragraphe qui prétend inventorier le résidu est neuf, et incomplet. | patch |
| R5 | `logging_out_closes_the_session()` n'épingle pas la cible de la redirection (blind) | low | La matrice gelée de la spec dit « redirection vers `/` » ; l'assertion n'exige qu'une redirection. `logout.target` vaut le défaut du framework, et les tests voisins du même fichier écrivent `assertResponseRedirects('/')`. Correction directe. | patch |
| R6 | `assertSame('/logout', $path)` recopie un chemin et subsume l'assertion au-dessus (blind) | low | Le docblock du test dit « jamais un chemin recopié » deux lignes plus haut, et c'est ce que le docblock de `FirewallUrls::signed()` mettait en garde. Une chaîne égale à `/logout` n'a par construction aucune query string : la seconde assertion ne peut pas rougir seule. Correction directe. | patch |
| R7 | « comme `csrf_token_id` juste au-dessus » (blind) | low | `csrf_token_id` est à `:60`, la phrase à `:116` — 56 lignes, avec tout le bloc `login_throttling` entre les deux. Correction directe. | patch |
| R8 | Le nom de pare-feu `'main'` est un littéral nu dont le raisonnement est mort avec `FirewallUrls` (blind) | false | Le docblock du nouveau test le porte déjà : « Le pare-feu y est **nommé** plutôt que déduit du token courant, qui n'existe pas hors requête. » Seule la note « Epic 6 posera un second pare-feu » a disparu, et ce n'est pas un défaut. | rejeté |
| R9 | Aucun test n'affirme qu'un `GET /logout` anonyme redirige au lieu de refuser (blind) | false | `AccessibilityFloorTest::pages()` crée un client et ne se connecte jamais ; la branche `neverReturns()` visite `/logout` et exige une redirection. La vérification par mutation l'a vu : `enable_csrf` remis à `true`, les 22 tests du plancher rougissent sur « ne répond pas une redirection (403) ». | rejeté |
| R10 | `/logout` n'est pas dans `access_control`, alors que les trois chemins publics y sont d'avance (blind) | low | Vrai, mais se déconnecter en anonyme est un no-op, et une redirection vers `/login` resterait une redirection. Le fichier refuse explicitement « une règle qui ne défend rien n'est vérifiée par rien » : le correctif ajouterait exactement cela. | rejeté |
| R11 | Un navigateur qui n'applique pas `SameSite` (webview, client ancien) rouvre la contrefaçon ; garde `Sec-Fetch-Site` proposé (edge-case) | low | Le résidu — une session fermée sans rien détruire ni divulguer — est explicitement assumé par AD-18. Le correctif ajoute un garde-fou serveur pour un cas que rien ne démontre atteignable, ce que la règle de routage exclut. | rejeté |
| R12 | `sprint-status.yaml` porte `in-progress` alors que la checklist est cochée (blind) | false | État transitoire : l'étape 5 du workflow synchronise le suivi. Identique au R15 de la story 1.12, rejeté pour la même raison. | rejeté |

## Design Notes

**`enable_csrf: false` plutôt que la clé retirée.** Le défaut du framework est déjà « pas
de jeton » : la ligne ne change rien, elle rend l'exception greppable au point de
décision. C'est la convention que ce fichier applique déjà à `csrf_token_id: authenticate`.

**Le test inversé change de sujet.** Inversé mot pour mot, il serait le jumeau de
`logging_out_closes_the_session()`. Ce qu'il doit garder, c'est l'autre moitié du
renversement : l'URL que l'application fabrique ne transporte plus rien à fuir.

## Verification

**Commands :**

- `php vendor/bin/phpunit --filter LoginTest` — rouge avant la config, vert après
- `php vendor/bin/phpunit` — suite entière verte
- `make qa` — six catégories vertes
- `grep -rn "sans exception" config/ src/ templates/` — rien qui invoque AD-18
