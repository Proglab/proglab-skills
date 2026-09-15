---
title: "Lever le jeton CSRF sur la déconnexion"
workflow: bmad-correct-course
date: '2026-09-14'
project: proglab-skills
status: 'approuvé et appliqué'
approved: '2026-09-14'
applied_on_branch: 'correct-course-csrf-deconnexion'
mode: incremental
scope_classification: 'moderate'
triggering_story: '1.6 — Se connecter avec son email et son mot de passe (done)'
artifacts_impacted:
  - '_bmad-output/planning-artifacts/architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md'
  - '_bmad-output/planning-artifacts/epics.md'
  - '_bmad-output/planning-artifacts/prds/prd-proglab-skills-2026-09-09/prd.md'
  - '_bmad-output/specs/spec-proglab-skills/criteres-acceptation.md'
  - '_bmad-output/implementation-artifacts/sprint-status.yaml'
---

# Proposition de changement de sprint — Lever le jeton CSRF sur la déconnexion

## 1. Résumé de la question

**Problème.** `/logout` répond **403** à toute requête qui ne provient pas d'un
`logout_path()` rendu dans la session courante. Une déconnexion demandée depuis un favori,
un lien recopié, une page servie par un cache ou une session expirée échoue — c'est-à-dire
précisément dans les situations où l'on veut se déconnecter.

**Origine.** Ce n'est pas un défaut d'implémentation. C'est le **constat n°1 de la revue de
la story 1.6** (`medium`, routé en `patch`), qui a ajouté `logout.enable_csrf: true` au nom
d'AD-18 — « CSRF obligatoire sur toute écriture, sans exception » — en invoquant l'attaque
`<img src="https://…/logout">` posée sur un site tiers. La revue avait enregistré le conflit
que ce patch créait avec son propre constat n°18 (« 1 contre 18 ») et l'avait tranché en
faveur du jeton.

**Catégorie.** Compréhension incomplète d'une exigence d'origine. Ni limite technique, ni
pivot, ni exigence nouvelle : AD-18 a été appliqué à un cas dont l'arbitrage n'avait pesé ni
la politique de cookie du socle, ni le coût de transporter un jeton dans l'URL d'un GET.

### Preuves

| Preuve | Où |
|---|---|
| `enable_csrf: true` et sa justification par `<img src>` | `config/packages/security.yaml:88-95` |
| `cookie_samesite` non surchargé → défaut `lax` (`FrameworkBundle/DependencyInjection/Configuration.php:788`). Un `<img>` est une sous-ressource : le cookie de session n'y est pas envoyé — **l'attaque citée pour justifier le jeton ne fonctionne déjà pas** | `config/packages/framework.yaml:6` |
| Le jeton voyage en query string d'un GET → journaux d'accès, en-têtes `Referer` | `logout_path()` |
| Un helper de test de 78 lignes n'existe que pour reconstituer cette URL | `tests/Core/Security/FirewallUrls.php` |
| Le plancher d'accessibilité doit rejouer la requête signée pour ne pas mesurer le CSRF au lieu de la route | `tests/Core/Accessibility/AccessibilityFloorTest.php:783-790` |

### Ce qui reste vrai contre le changement

Sous `SameSite=Lax`, une **navigation de premier niveau** piégée — un lien, une redirection —
emporte bien le cookie. Sans jeton, un tiers peut donc forcer une déconnexion. L'effet est
une nuisance : rien n'est détruit, rien ne fuit, l'utilisateur se reconnecte. Ce résidu est
assumé, et il est écrit dans AD-18 plutôt que passé sous silence.

## 2. Analyse d'impact

### Impact epic

- **Epic 1** reste livrable tel quel. Aucune story bloquée, aucun critère d'epic invalidé.
- **Aucun changement de périmètre d'epic.** Le changement tient dans une story ajoutée et
  une règle transverse reformulée.
- **Story 2.3** (coque de l'application, menu du compte portant « Se déconnecter ») est la
  seule story future qui consomme la déconnexion. `logout_path()` continue de fonctionner
  sans jeton : **aucun impact mécanique**. Contrainte d'ordre : la correction doit atterrir
  avant 2.3, sinon la coque est bâtie sur une convention (« jamais `path('app_logout')` »)
  qui n'aura plus de raison d'être.
- **Story 1.12** (guide de dérivation) documentera la convention si elle est écrite après.
- **Epic 6** (`/api` sans état) n'a pas de déconnexion.
- Aucun epic rendu obsolète, aucun nouvel epic nécessaire, aucun reséquencement.

### Conflits d'artefacts

| Artefact | Emplacement | Nature |
|---|---|---|
| ARCHITECTURE-SPINE | AD-18, `Prevents` et `Rule` (l. 401-412) | **Siège du changement** — « sans exception » |
| epics.md | NFR-1 (l. 79) | Seule occurrence de « sans exception » côté epics |
| epics.md | AR-15 (l. 115) | Résumé d'AD-18 dans l'inventaire |
| prd.md | NFR Sécurité (l. 584-586) | Affirme une couverture totale sans dire « sans exception » |
| criteres-acceptation.md | Exigences transverses (l. 186) | Risque de **critère d'acceptation faux** |
| DESIGN.md / EXPERIENCE.md / SPEC.md | — | **Aucun conflit.** Le CSRF n'y apparaît que pour le POST des Dialogs destructifs ; SPEC.md ne contient pas le mot |

### Impact technique

- `config/packages/security.yaml` — la clé `logout.enable_csrf` et huit lignes de
  commentaire qui affirment aujourd'hui le contraire de la nouvelle règle.
- `tests/Core/Security/LoginTest.php:307-317` — `logging_out_without_its_csrf_token_is_refused()`
  est à **inverser**, pas à supprimer : l'assertion change de sens, la couverture reste.
- `tests/Core/Security/FirewallUrls.php` — perd son dernier appelant.
- `tests/Core/Accessibility/AccessibilityFloorTest.php` — la logique tient déjà (le premier
  appel redirigera) ; seul le commentaire de dix lignes sur le rejeu signé devient faux.

### Artefacts dérivés — à ne pas éditer

`_bmad-output/implementation-artifacts/epic-1-context.md:31` reprend la formule mais est
recompilé par `bmad-build` dès qu'un fichier de `planning-artifacts/` est plus récent. Les
specs gelés des stories 1.6 et 1.7 sont des archives : la trace du renversement vit dans
AD-18 et dans la story 1.13, pas dans une réécriture d'historique.

## 3. Approche recommandée

**Option retenue : ajustement direct.**

| Option | Verdict | Effort | Risque |
|---|---|---|---|
| **1 · Ajustement direct** — reformuler AD-18 et ses reprises, une story pour le code et les tests | **Retenue** | Faible (~30 min agent) | Faible |
| 2 · Rollback de la story 1.6 | Écartée — 1.6 est mergée depuis quatre stories et porte toute l'authentification ; le patch CSRF en est une ligne | — | — |
| 3 · Révision du MVP | Sans objet — aucun objectif produit n'est en cause | — | — |

**Décisions du product owner prises pendant le run :**

1. **AD-18 garde sa fermeté et gagne une exception nommée**, plutôt que de voir son
   périmètre redéfini en « action qui modifie un état persistant ». Motif : une frontière
   redéfinie devient plaidable, et un auteur de module pourra soutenir que son cas non plus
   n'est pas une écriture. Une exception nommée et close ne se transporte pas par analogie.
2. **Le code atterrit dans une story dédiée 1.13**, apposée en fin d'epic 1 — pas repliée
   dans la 1.9, pas corrigée hors workflow. Motif : le renversement d'un constat de revue
   antérieur doit rester lisible dans le suivi.
3. **Portée limitée au GET nu sans jeton.** L'exemption des portiers (délai de grâce 2FA
   d'AD-19, futures zones d'`access_control`) et la présence du lien de déconnexion sur
   toutes les pages ne sont **pas** traitées ici — elles restent portées par les stories 2.3
   et 5.x. Ce n'est pas un oubli.

## 4. Propositions de changement détaillées

### 4.1 Architecture

**`ARCHITECTURE-SPINE.md` — AD-18, `Prevents`**

OLD :
> que chaque formulaire décide seul de sa protection, et que le ralentissement exigé par
> FR-3 soit réécrit à la main dans chaque chemin d'authentification.

NEW :
> que chaque formulaire décide seul de sa protection, que le ralentissement exigé par FR-3
> soit réécrit à la main dans chaque chemin d'authentification, et qu'un module s'exempte
> par analogie avec l'exception nommée ci-dessous.

**`ARCHITECTURE-SPINE.md` — AD-18, `Rule`**

OLD :
> CSRF obligatoire sur toute écriture, sans exception. Le ralentissement des échecs de
> connexion utilise le `login_throttling` natif du SecurityBundle…

NEW :
> CSRF obligatoire sur toute écriture. **Une seule exception, et elle est nommée ici : la
> déconnexion.** Toute autre exception exige une nouvelle décision d'architecture ;
> l'analogie avec celle-ci ne vaut pas argument.
>
> **Pourquoi la déconnexion, et elle seule.** Trois raisons, dont aucune ne se transporte à
> une écriture qui modifie une donnée. (a) La session du socle porte `SameSite=Lax` : une
> requête de sous-ressource — le `<img src="…/logout">` du manuel — n'emporte pas le cookie,
> la contrefaçon est donc déjà close sans jeton. (b) La déconnexion est un GET : le jeton y
> voyage en query string, donc dans les journaux d'accès et les en-têtes `Referer` — une
> protection qui fuit là où elle s'applique. (c) Ce qui reste — une navigation de premier
> niveau piégée qui ferme une session — ne détruit rien, ne divulgue rien, et se répare en
> se reconnectant ; en regard, une déconnexion qui répond 403 depuis un favori, une page
> servie par un cache ou une session expirée échoue précisément quand on en a besoin.
>
> Le ralentissement des échecs de connexion utilise le `login_throttling` natif du
> SecurityBundle… *(suite inchangée)*

*Justification.* AD-18 est cité comme `Binds` par FR-3, FR-4, FR-5, FR-10 et « tout
formulaire du socle et de tout module » : la règle doit rester assez ferme pour porter ces
liens. Les trois raisons sont écrites dans le spine parce que c'est là que le prochain
auteur de module viendra chercher si son cas ressemble — et la formulation lui répond non.

### 4.2 Epics

**`epics.md` — NFR-1**

OLD : `… ; protection CSRF sur toute écriture, sans exception ; aucune donnée sensible …`

NEW : `… ; protection CSRF sur toute écriture (seule exception : la déconnexion, AD-18) ; aucune donnée sensible …`

*Justification.* Seule occurrence de « sans exception » côté epics. La forme parenthésée
existe déjà dans la même phrase pour l'anonymisation de FR-20 : la règle garde sa forme et
nomme sa seconde exception au même format.

**`epics.md` — AR-15 (AD-18)**

OLD : `CSRF obligatoire sur toute écriture. login_throttling natif adossé à…`

NEW : `CSRF obligatoire sur toute écriture, à la seule exception nommée de la déconnexion. login_throttling natif adossé à…`

*Justification.* Un résumé qui omet l'exception fait mentir sa source.

**`epics.md` — nouvelle story, après la story 1.12, avant le `---` de l'Epic 2**

```markdown
### Story 1.13 : Rendre la déconnexion joignable sans jeton

As a utilisateur connecté,
I want que la déconnexion aboutisse depuis n'importe quel point de l'application,
So that je ne reste pas connecté parce qu'un jeton manque à l'URL.

**Estimation :** ~30 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** une session ouverte
**When** j'ouvre `/logout` sans aucun paramètre — depuis un favori, un lien recopié, une page servie par un cache
**Then** ma session est fermée et je suis redirigé, jamais refusé en 403

**Given** un lien de déconnexion rendu par `logout_path()`
**When** j'inspecte l'URL
**Then** elle ne porte plus de jeton en query string, donc plus rien à fuir dans les journaux d'accès ni dans un `Referer`

**Given** la configuration de la déconnexion
**When** je la lis
**Then** l'exception à AD-18 y est nommée et justifiée sur place — le commentaire qui invoquait l'attaque par `<img>` est remplacé par ce que `SameSite=Lax` couvre déjà et ce qu'il laisse passer

**Given** la suite de tests
**When** je cherche ce qui prouve la déconnexion
**Then** le test qui exigeait un 403 sans jeton est inversé et exige une déconnexion aboutie, et le helper qui fabriquait l'URL signée disparaît avec son dernier appelant

**Given** le plancher d'accessibilité
**When** il découvre `app_logout`
**Then** il obtient une redirection au premier appel, sans rejeu signé

**Given** toute autre écriture du socle, à commencer par le formulaire de connexion
**When** elle est soumise
**Then** elle porte toujours son jeton CSRF — l'exception ne s'étend à rien d'autre
```

*Justification.* Le dernier critère est le garde-fou qui empêche la story d'être lue comme
un assouplissement général du CSRF. Les deux critères sur les tests sont écrits parce que le
travail n'est pas « une ligne de YAML » : un test à inverser, un helper à retirer, deux
commentaires longs qui affirment le contraire de la nouvelle règle. Un commentaire qui ment
coûte plus cher qu'un test manquant.

### 4.3 PRD et critères d'acceptation

**`prd.md` — NFR Sécurité** et **`criteres-acceptation.md` — Exigences transverses**

OLD : `… ; protection CSRF sur toute écriture ; aucune donnée sensible …`

NEW : `… ; protection CSRF sur toute écriture, la déconnexion exceptée ; aucune donnée sensible …`

*Justification.* Aucun des deux ne dit « sans exception », donc aucun ne se contredit — mais
les deux affirment une couverture totale. `criteres-acceptation.md` est le cas sérieux :
c'est le seul endroit où l'omission produirait un **critère faux**, quelqu'un vérifiant
« CSRF sur toute écriture » recalant la déconnexion.

### 4.4 UI/UX

Aucun changement. `DESIGN.md:639` place « Se déconnecter » dans le menu du compte sans rien
dire du transport ; `EXPERIENCE.md` ne mentionne le CSRF que pour le POST des Dialogs
destructifs. Le parcours utilisateur ne change pas — il cesse seulement d'échouer.

## 5. Passation

**Classification : Moderate.** Le changement réorganise le backlog (une story ajoutée, un
fichier de suivi à mettre à jour) et modifie une décision d'architecture, mais il ne
replanifie rien : aucun epic obsolète, aucun objectif produit en cause, MVP intact.

### Plan d'action, dans l'ordre

1. **Éditions documentaires** (PO/architecte) — AD-18, NFR-1, AR-15, NFR Sécurité du PRD,
   exigences transverses des critères d'acceptation, et insertion de la story 1.13 dans
   `epics.md`. Sur une branche dédiée, jamais directement sur `main`.
2. **Suivi de sprint** — ajouter la clé `1-13-rendre-la-déconnexion-joignable-sans-jeton` en
   statut `backlog` via `bmad-sprint-planning`, qui fusionne les nouvelles clés en
   conservant les statuts acquis. Ne pas éditer `epic-1-context.md`, qui se recompile seul.
3. **Implémentation** (agent développeur, `bmad-build story 1.13`) — après la clôture de la
   story 1.8, actuellement en `review`.

### Critères de succès

- `GET /logout` sans paramètre ferme la session et redirige, depuis un client qui n'a jamais
  rendu de `logout_path()`.
- Le formulaire de connexion continue d'exiger son jeton : la suite de tests de la story 1.7
  passe sans modification.
- Les six catégories de la porte de qualité restent vertes, sans nouvelle testsuite.
- Aucun fichier du socle n'affirme plus « CSRF sans exception ».

### Dépendances et séquencement

- **Après** la clôture de la story 1.8 (en `review`).
- **Avant** la story 2.3, qui rendra le menu du compte et son lien de déconnexion.
