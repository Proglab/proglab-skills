---
name: proglab-skills
version: 0.1.0
status: final
updated: 2026-09-09
sources:
  - ../../briefs/brief-proglab-skills-2026-09-09/brief.md
  - ../../prds/prd-proglab-skills-2026-09-09/prd.md
description: >-
  Socle ERP custom (proglab) — thème de départ neutre shadcn (white-label) servi par
  Symfony UX Toolkit, kit shadcn, en clair et en sombre. Ce fichier décrit le thème que
  chaque dérivé reçoit au clonage et le point exact où il le rebrande ; ce n'est pas une
  marque.
colors:
  # Thème clair — valeurs shadcn « neutral » telles que documentées dans
  # mockups/direction-aere.html (oklch → hex approché). La source à l'exécution reste
  # assets/styles/app.css ; ces hex servent aux maquettes et aux vérifications de contraste.
  background: '#ffffff'            # oklch(1 0 0)
  foreground: '#0a0a0a'            # oklch(0.145 0 0)
  page: '#f5f5f5'                  # oklch(0.97 0 0) — fond de page derrière les cartes (= muted)
  card: '#ffffff'                  # oklch(1 0 0)
  card-foreground: '#0a0a0a'
  muted: '#f5f5f5'                 # oklch(0.97 0 0)
  muted-foreground: '#737373'      # oklch(0.556 0 0) — 4,74:1 sur background
  muted-strong: '#525252'          # oklch(0.439 0 0) — ≈ 7:1 sur page ; texte secondaire lisible
  primary: '#171717'               # oklch(0.205 0 0)
  primary-foreground: '#fafafa'    # oklch(0.985 0 0)
  secondary: '#f5f5f5'             # oklch(0.97 0 0)
  secondary-foreground: '#171717'
  accent: '#f5f5f5'                # survol / ligne active — jamais porteur de sens
  accent-foreground: '#171717'
  destructive: '#e7000b'           # oklch(0.577 0.245 27.325) — 4,77:1 sur background
  destructive-foreground: '#ffffff'
  border: '#e5e5e5'                # oklch(0.922 0 0)
  input: '#e5e5e5'                 # oklch(0.922 0 0) — bordure de champ, défaut shadcn conservé (1,26:1 sur card), voir § Colors
  ring: '#b5b5b5'                  # oklch(0.708 0 0) — voir la note de contraste en § Colors
  track: '#e5e5e5'                 # rail de la barre d'avancement
  sidebar: '#fafafa'               # oklch(0.985 0 0)
  sidebar-foreground: '#0a0a0a'
  sidebar-accent: '#ededed'        # oklch(0.94 0 0) — entrée de navigation courante
  # Statuts lisibles (PRD §3) — achromatiques dans le thème de départ, comme la direction
  # Aéré : la forme (plein / contour / pointillé) et l'icône portent l'information, la
  # couleur illustre. Un dérivé peut les colorer ; icône + libellé restent obligatoires.
  status-done: '#171717'           # « Terminé » — fond plein (= primary)
  status-done-foreground: '#fafafa'
  status-doing: '#0a0a0a'          # « En cours » — contour plein (= foreground)
  status-todo: '#525252'           # « À faire » — contour tireté (= muted-strong)
  status-ready: '#f5f5f5'          # « Tâche prête » — fond muted, texte foreground
  status-unreadable: '#525252'     # « Non lisible » — contour pointillé (FR-14)
  # Thème sombre — shadcn « neutral » sombre, mêmes rôles.
  background-dark: '#0a0a0a'       # oklch(0.145 0 0)
  foreground-dark: '#fafafa'       # oklch(0.985 0 0)
  page-dark: '#0a0a0a'
  card-dark: '#171717'             # oklch(0.205 0 0)
  card-foreground-dark: '#fafafa'
  muted-dark: '#262626'            # oklch(0.269 0 0)
  muted-foreground-dark: '#a3a3a3' # oklch(0.708 0 0) — ≈ 7,8:1 sur background-dark
  muted-strong-dark: '#c4c4c4'     # oklch(0.80 0 0) — ≈ 10:1 sur card-dark
  primary-dark: '#e5e5e5'          # oklch(0.922 0 0)
  primary-foreground-dark: '#171717'
  secondary-dark: '#262626'
  secondary-foreground-dark: '#fafafa'
  accent-dark: '#262626'
  accent-foreground-dark: '#fafafa'
  destructive-dark: '#ff6467'      # oklch(0.704 0.191 22.216) — ≈ 6,8:1 sur background-dark
  destructive-foreground-dark: '#0a0a0a'
  border-dark: '#232323'           # oklch(1 0 0 / 10 %) aplati sur background-dark
  input-dark: '#262626'            # oklch(0.269 0 0) — défaut shadcn conservé (1,18:1 sur card-dark), voir § Colors
  ring-dark: '#737373'             # oklch(0.556 0 0) — ≈ 4,1:1 sur background-dark
  track-dark: '#333333'            # oklch(0.32 0 0)
  sidebar-dark: '#171717'
  sidebar-foreground-dark: '#fafafa'
  sidebar-accent-dark: '#262626'
  status-done-dark: '#e5e5e5'
  status-done-foreground-dark: '#171717'
  status-doing-dark: '#fafafa'
  status-todo-dark: '#c4c4c4'
  status-ready-dark: '#262626'
  status-unreadable-dark: '#c4c4c4'
typography:
  # Pile système : aucune police à charger, aucune requête réseau, rendu natif par OS.
  # Échelle relevée sur la direction Aéré (corps 15 px).
  display:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 30px
    fontWeight: '600'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  heading:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 18px
    fontWeight: '600'
    lineHeight: '1.3'
  subheading:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 15px
    fontWeight: '600'
    lineHeight: '1.4'
  lead:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 16px
    fontWeight: '400'
    lineHeight: '1.5'
  body:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 15px
    fontWeight: '400'
    lineHeight: '1.5'
  body-sm:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 14px
    fontWeight: '400'
    lineHeight: '1.5'
  label:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 14px
    fontWeight: '500'
    lineHeight: '1.4'
  meta:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 13px
    fontWeight: '400'
    lineHeight: '1.4'
  caption:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 12px
    fontWeight: '400'
    lineHeight: '1.4'
  badge:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 13px
    fontWeight: '500'
    lineHeight: '1'
  stat:
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 16px
    fontWeight: '600'
    lineHeight: '1'
    note: 'font-variant-numeric: tabular-nums — le pourcentage d''une epic ne bouge pas quand il change'
  mono:
    fontFamily: 'ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace'
    fontSize: 13px
    fontWeight: '400'
    lineHeight: '1.5'
rounded:
  sm: 4px        # code inline, cases à cocher
  md: 8px        # entrées de navigation, logo, champs, boutons rectangulaires
  DEFAULT: 10px  # --radius shadcn ; Alert, DropdownMenu, Sheet (coins intérieurs)
  lg: 10px
  xl: 12px       # Card (carte d'epic), Dialog
  full: 9999px   # Badge, bouton compte, pastille de dépendance, avatar
spacing:
  # Échelle Tailwind à base 4 héritée telle quelle ; les jetons nommés fixent ce que la
  # direction Aéré a arrêté.
  '1': 4px
  '2': 8px
  '3': 12px
  '4': 16px
  '5': 20px
  '6': 24px
  '7': 28px
  '8': 32px
  '10': 40px
  '12': 48px
  gutter-desktop: 32px      # marges du contenu principal ≥ lg
  gutter-mobile: 16px       # marges du contenu < md
  content-max: 1000px       # largeur maximale du contenu (roadmap, fiches, formulaires)
  content-max-table: 1280px # les tableaux (utilisateurs, journal) peuvent aller plus large
  sidebar-width: 248px
  header-height: 60px
  card-padding: 24px        # horizontal ; vertical = 20px
  card-gap: 14px            # entre deux cartes d'epic
  row-padding: 14px         # vertical, ligne de tâche / ligne de tableau
  touch-target-min: 44px    # hauteur minimale d'une cible sur pointeur grossier
components:
  # Héritage : les objets partiels (badge-*, alert-destructive, flash) héritent de badge /
  # alert pour toute clé absente.
  # Hauteurs : les hauteurs ci-dessous (38 px des boutons et champs, 26 px des Badge,
  # spacing.header-height) sont des min-height, avec un line-height normal sur les Badge
  # (WCAG 1.4.12, voir § Components).
  button-primary:
    background: '{colors.primary}'
    foreground: '{colors.primary-foreground}'
    radius: '{rounded.md}'
    height: 38px
    height-touch: '{spacing.touch-target-min}'
    font: '{typography.label}'
  button-outline:
    background: '{colors.card}'
    foreground: '{colors.foreground}'
    border: '1px solid {colors.border}'
    radius: '{rounded.md}'
    height: 38px
  button-ghost:
    background: 'transparent'
    foreground: '{colors.foreground}'
    hover-background: '{colors.accent}'
    radius: '{rounded.md}'
  button-destructive:
    background: '{colors.destructive}'
    foreground: '{colors.destructive-foreground}'
    radius: '{rounded.md}'
  button-account:
    background: '{colors.card}'
    border: '1px solid {colors.border}'
    radius: '{rounded.full}'
    height: 38px
  card:
    background: '{colors.card}'
    border: '1px solid {colors.border}'
    radius: '{rounded.xl}'
    padding: '20px {spacing.card-padding}'
    shadow: 'none'
  accordion-epic:
    summary-padding: '20px {spacing.card-padding}'
    title: '{typography.heading}'
    subtitle: '{typography.body-sm}'
    subtitle-color: '{colors.muted-foreground}'
    stat: '{typography.stat}'
    chevron-size: 22px
    chevron-color: '{colors.muted-foreground}'
    open-duration: 200ms
    chevron-duration: 150ms
  progress:
    track: '{colors.track}'
    fill: '{colors.primary}'
    height: 8px
    radius: 4px
    animation: 'none'
  sidebar:
    background: '{colors.sidebar}'
    foreground: '{colors.sidebar-foreground}'
    border-right: '1px solid {colors.border}'
    width: '{spacing.sidebar-width}'
    item-padding: '9px 10px'
    item-radius: '{rounded.md}'
    item-active-background: '{colors.sidebar-accent}'
    item-active-weight: '600'
    group-label: '{typography.caption}'
    group-label-color: '{colors.muted-foreground}'
  sheet:
    background: '{colors.card}'
    border-left: '1px solid {colors.border}'
    width-desktop: 480px
    width-mobile: 100vw
    overlay: 'rgba(0,0,0,0.4)'
    slide-duration: 200ms
  dialog:
    background: '{colors.card}'
    border: '1px solid {colors.border}'
    radius: '{rounded.xl}'
    max-width: 480px
    overlay: 'rgba(0,0,0,0.4)'
    fade-duration: 150ms
  alert:
    background: '{colors.card}'
    border: '1px solid {colors.border}'
    accent-left: '4px solid {colors.foreground}'
    radius: '{rounded.DEFAULT}'
    padding: '14px 18px'
    title: '{typography.subheading}'
    body: '{typography.body-sm}'
    body-color: '{colors.muted-strong}'
    icon-size: 20px
  alert-destructive:
    accent-left: '4px solid {colors.destructive}'
    icon-color: '{colors.destructive}'
  banner:
    background: '{colors.muted}'
    border-bottom: '1px solid {colors.border}'
    padding: '10px {spacing.gutter-desktop}'
    font: '{typography.body-sm}'
    icon-size: 18px
  flash:
    extends: '{components.alert}'
    position: 'haut du contenu, sous le titre'
  badge:
    height: 26px
    padding: '0 10px'
    radius: '{rounded.full}'
    font: '{typography.badge}'
    border: '1px solid {colors.border}'
    icon-size: 13px
  badge-done:
    background: '{colors.status-done}'
    foreground: '{colors.status-done-foreground}'
    border: '1px solid {colors.status-done}'
    icon: 'coche'
  badge-doing:
    background: 'transparent'
    foreground: '{colors.foreground}'
    border: '1px solid {colors.status-doing}'
    icon: 'demi-disque'
  badge-todo:
    background: 'transparent'
    foreground: '{colors.status-todo}'
    border: '1px dashed {colors.status-todo}'   # 7,8:1 sur card — le tireté doit se voir
    icon: 'cercle vide'
  badge-ready:
    background: '{colors.status-ready}'
    foreground: '{colors.foreground}'
    border: '1px solid {colors.status-ready}'
    icon: 'triangle lecture'
  badge-unreadable:
    background: 'transparent'
    foreground: '{colors.status-unreadable}'
    border: '1px dotted {colors.status-unreadable}'   # 7,8:1 sur card
    icon: 'triangle avertissement'
  badge-deactivated:
    background: '{colors.muted}'
    foreground: '{colors.muted-strong}'
    border: '1px solid {colors.muted}'
    icon: 'utilisateur barré'
  # États de compte (EXPERIENCE.md · State Patterns). Clair : muted-strong ; sombre :
  # muted-strong-dark (≈ 10:1 sur card-dark). Forme distincte de badge-todo / badge-unreadable
  # par l'icône et le libellé — jamais sur le même écran qu'un statut de tâche.
  badge-invited:
    background: 'transparent'
    foreground: '{colors.muted-strong}'
    border: '1px dashed {colors.muted-strong}'   # « Invité » / « Invitation en attente »
    icon: 'enveloppe'
  badge-expired:
    background: 'transparent'
    foreground: '{colors.muted-strong}'
    border: '1px dotted {colors.muted-strong}'   # « Invitation expirée »
    icon: 'horloge barrée'
  task-row-dev:
    # Ligne de tâche en environnement de développement (bouton « Lancer », Notes internes)
    grid: '36px 1fr 150px 130px'
    launch-button: '{components.button-outline}'
    launch-reason: '{typography.meta}'
    launch-reason-color: '{colors.muted-strong}'
    notes-trigger: '{components.button-ghost}'
    notes-background: '{colors.muted}'
    notes-radius: '{rounded.md}'
    notes-font: '{typography.body-sm}'
  table:
    header-font: '{typography.meta}'
    header-color: '{colors.muted-foreground}'
    row-padding: '{spacing.row-padding} 0'
    row-border: '1px solid {colors.border}'
    row-hover: '{colors.accent}'
    cell-font: '{typography.body}'
  tabs:
    list-border-bottom: '1px solid {colors.border}'
    trigger-font: '{typography.label}'
    trigger-color: '{colors.muted-strong}'
    trigger-active-color: '{colors.foreground}'
    trigger-active-border: '2px solid {colors.foreground}'
  input:
    background: '{colors.background}'
    border: '1px solid {colors.input}'
    radius: '{rounded.md}'
    height: 38px
    height-touch: '{spacing.touch-target-min}'
    font: '{typography.body}'
    label: '{typography.label}'
    help: '{typography.meta}'
    help-color: '{colors.muted-foreground}'   # sur card uniquement — tout formulaire vit dans une Card
    error-color: '{colors.destructive}'       # 4,77:1 sur card uniquement
    error-border: '1px solid {colors.destructive}'
  input-otp:
    # Code 2FA : un seul champ texte, jamais six cases (EXPERIENCE.md · Component Patterns)
    width: 200px
    font: '{typography.body}'
    letter-spacing: 0.1em
    text-align: 'center'
  dropdown-menu:
    background: '{colors.card}'
    border: '1px solid {colors.border}'
    radius: '{rounded.DEFAULT}'
    item-height: 38px
    item-active-background: '{colors.accent}'
  avatar:
    size: 32px
    size-sm: 26px
    background: '{colors.primary}'
    foreground: '{colors.primary-foreground}'
    radius: '{rounded.full}'
  focus-ring:
    outline: '2px solid {colors.ring}'
    offset: 2px
---

## Brand & Style

**Nature du socle.** Ce socle n'a pas de marque, et c'est voulu. Il est cloné au
démarrage de chaque ERP custom et rebrandé par le dérivé ; ce que décrit ce fichier est
donc le **thème de départ** — le shadcn « neutral » que Symfony UX Toolkit installe avec
le kit shadcn (`symfony-proglab-ui`) — et le **contrat de rebranding**, pas une identité
visuelle.

**Registre.** Celui d'un produit moderne, sobre, aéré : une seule densité dans tout
l'ERP, du blanc et du gris neutre, sans teinte, aucune couleur chromatique hors le rouge
destructif, et l'information portée par la forme, l'icône et le texte plutôt que par la
teinte.

**Contrat de rebranding.** Un dérivé change deux choses, et seulement deux :

1. Les variables `oklch` du thème dans `assets/styles/app.css` — au minimum
   `--primary` / `--primary-foreground` (clair et sombre), éventuellement `--ring`,
   `--sidebar*`, `--destructive` ; le reste hérite.
2. Le logo dans la sidebar — le remplaçant par défaut est le logo carré (`{rounded.md}`,
   mêmes couleurs que `{components.avatar}`) portant les initiales du dérivé.

Aucun réglage de marque n'existe dans l'administration : la marque est du code, versionnée
avec le dérivé. Après chaque changement de variable, le contraste se remesure
(`symfony-proglab-accessibility`, règle 2) — les valeurs par défaut passent les seuils ;
rien ne garantit qu'une charte client les passe.

**Principe directeur.** Le plus simple possible à utiliser : peu d'écrans, peu de chrome,
aucun réglage qui n'est pas exigé par une exigence fonctionnelle (FR) du PRD, un chemin
évident par tâche, le vocabulaire du glossaire du PRD sans jargon technique ni jargon
BMAD.

**Explorations.** Trois directions ont été explorées pour l'accueil roadmap ; la
direction retenue, **Aéré**, est la seule promue dans `mockups/`
(`mockups/direction-aere.html`) et s'étend à tout l'ERP. Les écrans clés rendus 1:1 et
les écarts connus des maquettes sont listés en § Components, bloc **Écrans clés**. Les
deux directions rejetées restent dans `.working/` :

- Compact (`.working/direction-compact.html`) — tableau dense à 13 px sans mouvement ;
- Éditorial (`.working/direction-editorial.html`) — serif et rail vertical.

## Colors

Le thème est achromatique par construction. Chaque rôle existe en clair et en sombre,
suffixe `-dark` ; le mode sombre suit `prefers-color-scheme` par défaut et l'utilisateur
peut forcer clair / sombre / système (mémorisé par utilisateur — comportement dans
EXPERIENCE.md).

**Principe « défauts shadcn ».** Les réglages par défaut du kit shadcn priment sur les
mesures de contraste pour les jetons du socle : quand un défaut du kit passe sous un seuil
WCAG (bordure des champs, anneau de focus), le socle **garde le défaut** et ne le consigne
pas en dette d'accessibilité. Ce sont des choix de conception assumés, cohérents avec
« le plus simple possible » et avec le contrat de rebranding : un dérivé qui veut un
contour ou un anneau plus marqué change `--input` ou `--ring` dans
`assets/styles/app.css`, comme n'importe quelle autre variable. Les seuils du tableau de
contrastes restent la référence pour tout ce que le socle **ajoute** au kit (statuts,
badges, texte secondaire).

- **`{colors.page}` et `{colors.card}`** structurent l'espace : le fond de page est le
  gris `muted` (`#f5f5f5`), les cartes sont blanches. C'est la seule hiérarchie tonale du
  socle — pas d'ombre, pas de dégradé. En sombre, la page est `#0a0a0a` et les cartes
  `#171717`.
- **`{colors.foreground}` / `{colors.primary}`** sont quasi identiques (`#0a0a0a` /
  `#171717`) : dans le thème de départ, le bouton primaire est simplement noir. C'est
  `--primary` que le dérivé recolore en premier.
- **`{colors.muted-foreground}` (`#737373`)** est le gris de texte secondaire de shadcn —
  4,74:1 sur blanc, tout juste au-dessus du seuil AA. Il ne sert qu'aux textes courts
  (méta, sous-titres, en-têtes de tableau, aide de champ) et jamais sur `{colors.page}`,
  où il tombe à 4,35:1 (§ Layout & Spacing).
- **`{colors.muted-strong}` (`#525252`)** est le jeton ajouté par la direction Aéré : le
  gris de texte secondaire lisible sur le fond de page (≈ 7:1). Il sert aux phrases
  d'accueil, aux descriptions d'alerte, aux dépendances, à la raison sous un bouton
  « Lancer » et à la note de pied de la roadmap (« Cette page reflète le dernier
  déploiement… »), seul texte posé directement sur la page.
- **`{colors.destructive}`** est la seule couleur chromatique : bouton de confirmation
  d'une action irréversible, bordure et message d'erreur de champ, alerte destructive.
  Jamais pour un statut, jamais pour « en retard », jamais pour attirer l'œil. En texte,
  il n'est lisible que sur `{colors.card}` — 4,77:1, contre 4,38:1 sur `{colors.page}`
  (§ Layout & Spacing).
- **`{colors.input}`** est la bordure des champs de formulaire, à la valeur shadcn par
  défaut (`#e5e5e5` en clair, 1,26:1 sur `{colors.card}` ; `#262626` en sombre,
  1,18:1). Le contour seul est donc discret : c'est le label visible au-dessus, le fond
  de carte et l'anneau de focus qui situent le champ, pas sa bordure (principe « défauts
  shadcn »).
- Les **statuts lisibles** (`{colors.status-todo}`, `{colors.status-doing}`,
  `{colors.status-done}`, `{colors.status-ready}`, `{colors.status-unreadable}`) sont des
  alias sémantiques, achromatiques dans le thème de départ. La règle qui ne se négocie
  pas, quel que soit le rebranding : **jamais la couleur seule** — chaque statut est
  une icône distincte + un libellé (« À faire », « En cours », « Terminé », « Tâche
  prête », « Non lisible ») + une forme de bordure distincte (tireté / plein / rempli /
  pointillé). Un dérivé qui colore ses statuts (vert / ambre) garde les trois.
- **`{colors.accent}`** est le fond de survol et de ligne active des menus et tableaux.
  Il n'a aucun sens en lui-même.
- **`{colors.ring}`** est l'anneau de focus, à la valeur shadcn par défaut (`#b5b5b5`
  en clair, ≈ 2,05:1 sur blanc ; `#737373` en sombre, ≈ 4,1:1 sur `#0a0a0a`). La règle 4
  de `symfony-proglab-accessibility` (focus visible) reste impérative : l'anneau est
  présent sur chaque élément interactif et jamais supprimé ; s'il est remplacé, c'est par
  `:focus-visible` + `box-shadow` avec `--ring`.

Combinaisons porteuses de sens, avec leur ratio (à remesurer après rebranding) :

| Texte / composant | Fond | Ratio | Seuil | Usage |
|---|---|---|---|---|
| `{colors.foreground}` | `{colors.background}` | ≈ 19:1 | 4,5:1 | corps, titres |
| `{colors.muted-foreground}` | `{colors.card}` | 4,74:1 | 4,5:1 | méta, en-têtes de tableau, aide de champ — sur carte uniquement |
| `{colors.muted-foreground}` | `{colors.sidebar}` | 4,54:1 | 4,5:1 | titres de groupe de la Sidebar — à remesurer si `--sidebar` est recoloré |
| `{colors.muted-strong}` | `{colors.page}` | ≈ 7,2:1 | 4,5:1 | texte secondaire sur fond de page, note de pied de roadmap |
| `{colors.primary-foreground}` | `{colors.primary}` | ≈ 16:1 | 4,5:1 | bouton primaire, badge Terminé |
| `{colors.destructive-foreground}` | `{colors.destructive}` | 4,77:1 | 4,5:1 | bouton destructif |
| `{colors.destructive}` | `{colors.card}` | 4,77:1 | 4,5:1 | texte d'erreur de champ — sur carte uniquement |
| `{colors.input}` (bordure) | `{colors.card}` | 1,26:1 | — | contour des champs — défaut shadcn conservé |
| `{colors.status-todo}` / `{colors.status-unreadable}` (bordure de Badge) | `{colors.card}` | ≈ 7,8:1 | 3:1 | tireté « À faire », pointillé « Non lisible » |
| `{colors.muted-strong}` (bordure de Badge) | `{colors.card}` | ≈ 7,8:1 | 3:1 | « Invité », « Invitation expirée » |
| `{colors.muted-foreground-dark}` | `{colors.card-dark}` | ≈ 7,1:1 | 4,5:1 | méta, sombre |
| `{colors.destructive-dark}` | `{colors.card-dark}` | ≈ 6,2:1 | 4,5:1 | erreurs, sombre |
| `{colors.input-dark}` (bordure) | `{colors.card-dark}` | 1,18:1 | — | contour des champs, sombre — défaut shadcn conservé |
| `{colors.status-todo-dark}` (bordure de Badge) | `{colors.card-dark}` | ≈ 10:1 | 3:1 | bordures de Badge, sombre |

## Typography

Une seule famille : la pile système (`{typography.body.fontFamily}`). Aucune police
web à charger — pas de requête réseau, pas de flash de texte, rendu natif sur chaque
OS, cohérent avec le refus d'un bundler (`symfony-proglab-frontend`). Un dérivé peut
ajouter une police de marque via AssetMapper ; ce n'est pas une décision du socle.

L'échelle vient de la direction Aéré, corps à 15 px — un cran au-dessus du 14 px
habituel des back-offices, parce que Marc, le gérant (PRD, UJ-3), lit sa roadmap une
fois par semaine et ne doit pas plisser les yeux.

| Rôle | Jeton | Emploi |
|---|---|---|
| Titre de page | `{typography.display}` | le seul `h1` de chaque page : « Bonjour Marc », « Utilisateurs », « Journal d'audit » |
| Titre de section / carte | `{typography.heading}` | `h2` : nom d'epic, section d'une fiche, titre de Dialog et de Sheet (dans un contexte modal, ce titre tient lieu de titre de section) |
| Sous-titre | `{typography.subheading}` | `h3` : titre d'Alert et de Flash, de groupe de permissions |
| Accroche | `{typography.lead}` | phrase sous le `h1` |
| Corps | `{typography.body}` | tout le reste, cellules de tableau, nom de tâche |
| Corps réduit | `{typography.body-sm}` | description d'epic, corps d'Alert, bandeau |
| Libellé | `{typography.label}` | labels de champ, boutons, onglets, nom dans la sidebar |
| Méta | `{typography.meta}` | assignation · priorité · difficulté ; note de bas de page |
| Légende | `{typography.caption}` | titres de groupe de la sidebar, sous-ligne d'avatar |
| Badge | `{typography.badge}` | statuts, origine d'une permission |
| Chiffre | `{typography.stat}` | pourcentage d'epic, compteurs — chiffres tabulaires |
| Mono | `{typography.mono}` | valeurs brutes dans le journal d'audit, nom de fichier BMAD dans une alerte, jeton d'API affiché une fois |

- **Capitales et graisses.** Pas de capitales forcées, pas de lettrage espacé ; la
  hiérarchie vient de la taille et de la graisse (400 / 500 / 600 seulement, jamais 700).
- **Un seul `h1` par page**, porté par le template de page et jamais par le layout ni par
  un composant répété (`symfony-proglab-accessibility`, règle 6).
- **Aucun niveau sauté.** Une seule règle, partout : `h1` page ; `h2` carte d'epic,
  section de fiche, Dialog et Sheet ; `h3` Alert, Flash, groupe de permissions.
  L'anatomie du `h2` d'une carte d'epic est fixée à la ligne Accordion de § Components.

## Layout & Spacing

- **Coque.** Sidebar fixe de `{spacing.sidebar-width}` à gauche à partir de `lg`,
  en-tête de `{spacing.header-height}` sans bordure (le fond de page continue), contenu
  avec gouttières `{spacing.gutter-desktop}`. Sous `lg`, la sidebar devient un tiroir
  (Sheet) ouvert par un bouton hamburger dans l'en-tête, et les gouttières tombent à
  `{spacing.gutter-mobile}`.
- **Largeur de lecture.** Le contenu narratif (roadmap, fiches, formulaires, profil) est
  borné à `{spacing.content-max}` ; les tableaux (utilisateurs, journal d'audit) peuvent
  aller jusqu'à `{spacing.content-max-table}`. Rien ne s'étire sur un écran 4K.
- **Rythme vertical.** `h1` puis accroche puis pastille de dernier déploiement, marge
  basse `{spacing.7}` ; Alert admin le cas échéant, marge basse `{spacing.6}` ; cartes
  d'epic séparées de `{spacing.card-gap}`. Dans une carte, le résumé a un padding de
  `20px {spacing.card-padding}` ; chaque ligne de tâche prend `{spacing.row-padding}` en
  haut et en bas, et une bordure la sépare de la suivante. Les tableaux prennent
  exactement le même rythme de ligne : une seule densité, aérée, partout.
- **Grilles.**

  | Élément | Grille | Sous `md` |
  |---|---|---|
  | Résumé d'epic | `1fr 240px 28px` — titre et description, bloc d'avancement, chevron | le bloc d'avancement passe sous le titre |
  | Ligne de tâche | `36px 1fr 150px` — icône de statut, nom + méta + dépendance, badge | le badge passe sous les méta |
  | Ligne de tâche en développement | `{components.task-row-dev.grid}` (`36px 1fr 150px 130px`) — quatrième colonne pour le bouton « Lancer » (ligne « Ligne de tâche (dev) » de § Components) | badge puis bouton passent sous les méta, dans cet ordre |

- **Formulaires et tableaux vivent toujours dans une `{components.card}`** — jamais
  à nu sur `{colors.page}`. C'est ce qui garantit les ratios de `{colors.muted-foreground}`
  (en-têtes, aide) et de `{colors.destructive}` (erreurs), qui tombent sous 4,5:1 sur le
  fond de page. Par écran :
  - Connexion, Invitation, Réinitialisation, Vérification 2FA : une seule Card centrée
    de 480 px au plus ;
  - Inviter, Profil, Fiche rôle : une Card par section ;
  - Utilisateurs, Journal d'audit : la Table et ses filtres dans une Card de
    `{spacing.content-max-table}`.
- **Formulaires** : une colonne, champs en pleine largeur, 480 px au plus, label
  au-dessus, aide et erreur en dessous, boutons alignés à gauche (primaire d'abord).

Points de rupture Tailwind par défaut (`sm` 640 px, `md` 768 px, `lg` 1024 px, `xl`
1280 px) ; le comportement à chacun est dans EXPERIENCE.md · Responsive & Platform.

## Elevation & Depth

Plat. La profondeur vient de deux tons (`{colors.page}` sous `{colors.card}`) et d'une
bordure de 1 px (`{colors.border}`) — jamais d'une ombre. Les cartes n'ont pas d'ombre,
ni au repos ni au survol. Seules exceptions : les surfaces qui flottent réellement
au-dessus de la page — Dialog, Sheet, DropdownMenu. Elles gardent l'ombre par défaut du
kit shadcn, parce qu'elle décrit une vraie superposition ; Dialog et Sheet ajoutent un
voile (`{components.dialog.overlay}`). En sombre, la bordure passe à un blanc à 10 %
(`{colors.border-dark}`) et les cartes s'éclaircissent d'un cran par rapport à la page :
même logique, inversée.

## Shapes

Coins doux, jamais de pilule sur une surface — la forme pleinement arrondie est réservée
aux petits éléments d'une ligne, jamais à un conteneur.

- `{rounded.sm}` (4 px) : code inline, cases à cocher.
- `{rounded.md}` (8 px) : ce qui se clique dans un flux — entrées de navigation, champs,
  boutons rectangulaires ; le logo du dérivé est un carré `{rounded.md}`.
- `{rounded.DEFAULT}` (10 px, le `--radius` shadcn) : Alert, DropdownMenu et Sheet.
- `{rounded.xl}` (12 px) : les cartes d'epic et les Dialog, pour qu'ils se lisent comme
  des objets posés.
- `{rounded.full}` : les petits éléments qui ne contiennent qu'une ligne — Badge, avatar,
  bouton compte de l'en-tête, pastille « En attente de … », pastille de dernier
  déploiement. L'icône de statut de tâche est ronde (spécification à la ligne Accordion
  de § Components).

## Components

Tous les composants viennent du kit shadcn de Symfony UX Toolkit, copiés dans le projet
par `ux:install <nom> --kit shadcn` (`symfony-proglab-ui`) — après quoi ce sont des
composants Twig du projet, qui prennent des DTOs et ne portent aucune règle métier
(`symfony-proglab-frontend`, `references/components.md`). Ce qui suit fixe les variantes
retenues et leurs règles d'usage ; les props exactes sont celles du kit installé, pas
celles de ce fichier. Un seul kit dans le projet : jamais `--kit bootstrap` à côté.
Les « règle n » renvoient aux règles de `symfony-proglab-accessibility`.

Ce tableau ne porte que l'anatomie, les variantes et les règles visuelles : **le
comportement de chaque composant vit dans `EXPERIENCE.md · Component Patterns`**, une
seule fois — les mentions « Comportement : … → EXPERIENCE.md » des cellules ne font que
nommer ce qui y est décrit. Les hauteurs du frontmatter sont des `min-height`
(commentaires à la clé `components:`) : une feuille de style utilisateur qui augmente
l'espacement du texte (WCAG 1.4.12) agrandit l'élément au lieu d'en faire déborder le
libellé.

| Composant (kit) | Variantes retenues | Spécification visuelle et règle d'usage |
|---|---|---|
| **Button** | `default` (`{components.button-primary}`), `outline`, `ghost`, `destructive`, `size="icon"` | Un seul bouton primaire par écran, à gauche du groupe. `outline` pour Annuler et les actions secondaires ; `ghost` dans les en-têtes de tableau et les menus ; `destructive` uniquement dans le pied d'un Dialog de confirmation, jamais directement sur une page. `size="icon"` exige un `aria-label` (règle 5). Actions en ligne de la Table des permissions (« Ajouter », « Retirer », « Revenir à l'héritage ») : `outline` `size="sm"`, à droite de la ligne. Un bouton inactif garde l'apparence `disabled` du kit mais reste focusable (`aria-disabled`, voir EXPERIENCE.md). Hauteur 38 px, `{spacing.touch-target-min}` sur pointeur grossier — y compris `size="icon"` et les entrées de navigation. |
| **Card** | par défaut | `{components.card}` : blanc, bordure, `{rounded.xl}`, sans ombre. Une carte par epic sur la roadmap ; une carte par section de fiche (Identité, Permissions, Sécurité). |
| **Accordion** (carte d'epic) | `single` ou `multiple`, ouverte pour l'epic en cours | `{components.accordion-epic}` : résumé = titre `h2` + Badge de statut + description `{typography.body-sm}` + bloc d'avancement (« 6 sur 10 tâches terminées » / `{typography.stat}` 60 % / Progress) + chevron 22 px qui pivote en 150 ms. Le `h2` ne contient que le titre : `<h2><button aria-expanded aria-controls>Gestion des devis</button></h2>`, Badge et bloc d'avancement restent hors du bouton : sinon le nom accessible du bouton concatène titre, Badge et avancement, et le titre n'est plus atteignable en tant que titre. Contenu = liste de tâches à deux niveaux (nom `{typography.body}` 500, méta `{typography.meta}`, pastille de dépendance `{rounded.full}` sur `{colors.muted}`) ; l'icône de statut de tâche est un disque de 28 px dont la bordure change de style avec le statut (tireté à faire, pleine en cours, remplie terminé). Dépliage 200 ms ease-out. Une epic non lisible garde sa carte, en bordure tiretée, fond transparent, avancement « — » et Badge « Non lisible ». |
| **Progress** | par défaut | `{components.progress}` : rail 8 px `{colors.track}`, remplissage `{colors.primary}`, **sans animation au chargement** — la barre est rendue à sa largeur finale. Toujours accompagnée du chiffre et de la phrase « n sur m tâches terminées » : la barre illustre, le texte porte. |
| **Sidebar** | `sidebar` (fixe ≥ lg) / `sheet` (< lg) | `{components.sidebar}` : logo + nom du dérivé + sous-ligne « ERP · production » ou « ERP · développement » ; groupes « Suivi » et « Administration » en `{typography.caption}` ; entrées icône 18 px + libellé ; entrée courante sur `{colors.sidebar-accent}` en graisse 600 ; pied avec avatar, nom et rôle. Comportement : filtrage par permission, tiroir, repères → EXPERIENCE.md. |
| **Sheet** | `side="right"` (journal d'audit), `side="left"` (navigation mobile) | `{components.sheet}` : 480 px à droite sur desktop, plein écran sous `md` ; titre `h2` dans l'en-tête, bouton de fermeture `size="icon"` avec `aria-label="Fermer"`, glissement 200 ms. |
| **Dialog** | confirmation | `{components.dialog}` : 480 px max, titre `h2` (« Désactiver Karim Benali ? »), corps `{typography.body}`, pied avec `outline` Annuler à gauche et `destructive` ou `default` à droite. Un seul niveau de Dialog, jamais deux. |
| **Alert** | `default`, `destructive` | `{components.alert}` : bordure gauche 4 px, icône 20 px, titre `h3` `{typography.subheading}`, corps `{colors.muted-strong}`. `default` pour l'avertissement roadmap partielle (administrateurs) et les messages d'information ; `destructive` pour un échec (lancement de tâche échoué, invitation expirée). |
| **Banner** (bandeau 2FA) | un seul type | `{components.banner}` : pleine largeur sous l'en-tête, fond `{colors.muted}`, icône bouclier 18 px, texte `{typography.body-sm}` avec le délai restant, lien « Activer maintenant » en graisse 500. Comportement : affichage, fin du délai → EXPERIENCE.md. |
| **Flash** (message après action) | `success`, `error` | `{components.flash}` : même anatomie que l'Alert (titre `h3` facultatif), en haut du contenu sous le titre, bouton de fermeture `ghost` `size="icon"` `aria-label="Fermer"`. Comportement : annonce, fermeture, persistance → EXPERIENCE.md. |
| **Badge** | statut (`done`, `doing`, `todo`, `ready`, `unreadable`), état de compte (`deactivated`, `invited`, `expired`), origine de permission | `{components.badge}` : 26 px, `{rounded.full}`, icône 13 px + libellé, toujours les deux. Bordures de statut en `{colors.status-todo}` / `{colors.status-unreadable}` (≥ 3:1 sur carte) pour que le tireté et le pointillé se voient. États de compte : « Invité » et « Invitation en attente » (`{components.badge-invited}`, enveloppe, tireté `{colors.muted-strong}`), « Invitation expirée » (`{components.badge-expired}`, horloge barrée, pointillé), « Compte désactivé » (`{components.badge-deactivated}`) ; « Actif » est un texte `{typography.body}` sans Badge. Un état de compte et un statut de tâche ne cohabitent jamais sur un écran. Origine d'une permission : « Héritée du rôle » (`outline`), « Ajoutée » (`{components.badge-done}`), « Retirée » (`{components.badge-todo}` avec icône moins). |
| **Pill** (pastille) | `outline` sans icône | Variante de `{components.badge}` sans icône, fond `{colors.muted}`, bordure `{colors.border}`, texte `{typography.meta}` `{colors.muted-strong}` : dépendance « En attente de : … » sous une tâche, « Dernier déploiement : … » sous l'accroche. Jamais porteuse d'un statut. |
| **Table** | par défaut | `{components.table}` : **toujours dans une `{components.card}`** (§ Layout & Spacing) ; en-têtes `{typography.meta}` en `{colors.muted-foreground}`, lignes `{spacing.row-padding}` séparées d'une bordure, survol `{colors.accent}`, même densité que les cartes. Une ligne cliquable porte son lien dans la première cellule ; un `::after` sur ce lien — et sur lui seul — étend la zone cliquable à toute la ligne. Sous `md`, seules les deux ou trois colonnes prioritaires restent. Pas de zébrure. Comportement : tri, colonnes, nom des liens → EXPERIENCE.md. |
| **Tabs** | par défaut, sémantique `nav` + liens | `{components.tabs}` : liste soulignée, onglet courant en `{colors.foreground}` avec bordure basse 2 px. Utilisé sur le Profil (Langue, Mot de passe, Double authentification, Jetons d'API). On garde le visuel du kit, pas sa sémantique `role="tab"` ; comportement et balisage → EXPERIENCE.md. |
| **Champ de formulaire** (Input, Select, Checkbox, Textarea) | par défaut | `{components.input}` : bordure `{colors.input}` (défaut shadcn, § Colors), label visible `{typography.label}` au-dessus (jamais un placeholder seul, règle 3), aide `{typography.meta}`, erreur en `{colors.destructive}` avec icône + texte + `aria-invalid` + `aria-describedby` (règle 1). Toujours dans une `{components.card}` (§ Layout & Spacing). Hauteur 38 px, `{spacing.touch-target-min}` sur pointeur grossier. Valeurs `autocomplete` et règles de focus → EXPERIENCE.md. |
| **Champ de code 2FA** | un seul `Input` | `{components.input-otp}` : un seul champ texte de 200 px, `{typography.body}` centré avec `letter-spacing` 0.1em, label visible « Code à 6 chiffres de votre application » (ou « Code reçu par email »), aide `{typography.meta}` indiquant la durée de validité du code envoyé par email. **Jamais le composant InputOTP à six cases** du kit : six champs sans label et un focus qui saute sont hostiles aux lecteurs d'écran. Le champ « Code de secours » est un second `Input` identique, sur sa propre page. |
| **Ligne de tâche (dev)** | variante de la ligne de tâche, `APP_ENV=dev` seulement | `{components.task-row-dev}` : grille `{components.task-row-dev.grid}` (§ Layout & Spacing) — icône de statut, nom + méta + pastille de dépendance, Badge, puis le bouton « Lancer » (`{components.button-outline}`, 38 px, aligné à droite). Sa raison d'inactivité (« En attente de : … ») s'écrit sous le bouton en `{typography.meta}` `{colors.muted-strong}`, alignée à droite, sur deux lignes au plus. Le bouton « Notes internes » est un `{components.button-ghost}` `size="sm"` avec chevron, sous les méta dans la deuxième colonne ; son contenu déplié est un bloc `{colors.muted}` `{rounded.md}` en `{typography.body-sm}`, padding `{spacing.3}`, sur toute la largeur de la deuxième colonne. En production, ni bouton ni colonne (grille `36px 1fr 150px`). |
| **DropdownMenu** (menu compte) | par défaut | `{components.dropdown-menu}` : ouvert par `{components.button-account}` (avatar 26 px + nom + chevron). Sections : Profil ; Langue (langues actives, radio) ; Thème (Clair / Sombre / Système, radio) ; Se déconnecter. |
| **Avatar** | initiales | `{components.avatar}` : disque `{colors.primary}`, initiales en `{colors.primary-foreground}` en graisse 600, `aria-hidden` quand le nom est à côté. Pas d'upload de photo dans le socle. |
| **Pagination** | par défaut | Boutons `outline` Précédent / Suivant + « Page 3 sur 41 » en `{typography.meta}`, sous la Table, dans la même Card. |

**Icônes.** Trait de 2 px (Tabler via `ux:icons`), 18 px dans la navigation, 15 à 16 px
en ligne, 13 px dans un Badge, 20 px dans une Alert. Une icône à côté d'un texte est
`aria-hidden="true"` ; une icône seule porte un `aria-label` (règle 5).

**Mouvement.** Minimal et fonctionnel uniquement : dépliage d'Accordion 200 ms ease-out,
chevron 150 ms, glissement de Sheet 200 ms, fondu de Dialog et de DropdownMenu 150 ms.
Rien d'autre ne bouge — pas de barre d'avancement qui se remplit, pas d'apparition en
cascade, pas d'effet de survol animé. Sous `prefers-reduced-motion: reduce`, toutes les
durées tombent à 0 (les composants du kit le font ; les transitions écrites à la main
doivent le faire aussi).

**Écrans clés.** Référence visuelle générale : `mockups/direction-aere.html` (Sidebar,
Accordion d'epic, Badge, Alert, Progress, bouton compte, en clair et en sombre). Trois
écrans clés rendus 1:1 :

- `mockups/key-fiche-utilisateur.html` — Fiche de Karim vue par Sophie : Flash après
  redirection, sections Identité / Permissions / Sécurité en `h2`, Table des permissions
  avec Badge d'origine, Dialog « Anonymiser Karim Benali ? » avec champ de confirmation.
  Illustre Card, Table, Badge, Dialog et Flash.
- `mockups/key-profil.html` — Profil de Sophie : Banner 2FA sous l'en-tête, Tabs, onglet
  Double authentification avec Alert « Codes de secours », onglet Jetons d'API. Illustre
  Banner, Tabs et Alert.
- `mockups/key-connexion-invitation.html` — Connexion avec liens de langue et lien
  « Mot de passe oublié ? », Acceptation d'invitation « Bienvenue Karim ! » à deux champs
  de mot de passe, page « Invitation expirée » — chaque écran en clair et en sombre, une
  seule Card centrée. Illustre Champ de formulaire et Button.

**Écarts connus des maquettes.**

- `direction-aere.html` rend le titre de l'Alert en `h2` ; la règle est `h3`.
- `direction-aere.html` anime encore la largeur de la barre d'avancement
  (`.bar i { transition: width .6s }`) et n'a pas de bloc `prefers-reduced-motion` ; la
  règle est `{components.progress.animation}` = `none` — ne pas recopier.
- `direction-aere.html` montre le Banner 2FA à Marc, qui dans UJ-3 a déjà sa 2FA
  active : le Banner y est illustratif.

En cas de conflit entre une maquette et les spines (ce fichier et EXPERIENCE.md), les
spines l'emportent.

## Do's and Don'ts

Quatre blocs, dans l'ordre : rebranding, accessibilité (règles 1 à 7), stack et kit,
rendu.

| Do | Don't |
|---|---|
| Hériter du thème neutre shadcn ; rebrander uniquement via les variables `oklch` de `assets/styles/app.css` et le logo | Ajouter un réglage de marque dans l'administration, ou coder une couleur en dur dans un template |
| Un statut = icône + libellé + forme de bordure ; la couleur illustre (règle 1) | Un point vert / orange / rouge sans texte, une bordure rouge seule sur un champ en erreur |
| Remesurer le contraste (4,5:1 texte, 3:1 composants) après chaque changement de variable de thème (règle 2) | Renforcer un défaut du kit (bordure de champ, anneau de focus) au nom du contraste, ou le consigner en dette : le principe « défauts shadcn » s'applique |
| Un label visible au-dessus de chaque champ, ou `sr-only` si la maquette le cache (règle 3) | Un placeholder en guise de label, `label: false` |
| Garder `:focus-visible` avec `{components.focus-ring}` sur tout élément interactif écrit à la main (règle 4) | `outline: none` ou `focus:outline-none` sans remplacement |
| `alt=""` sur une image décorative, `aria-hidden` sur une icône accompagnée d'un texte, `aria-label` sur un bouton icône-seule (règle 5) | Omettre `alt`, laisser un bouton `size="icon"` annoncé « bouton » |
| Un seul `h1` par page dans le template de page ; `h2` pour les cartes d'epic, les sections de fiche, Dialog et Sheet ; `h3` pour les Alert, les Flash et les groupes de permissions (règle 6) | Un `h1` dans le layout, un `h4` parce qu'il est plus petit, un Dialog en `h3` |
| Toute action accessible au clic et au clavier ; le survol n'est qu'un bonus visuel (règle 7) | Une action de ligne de tableau révélée uniquement au survol |
| Utiliser les attributs épandus du kit (`{{ ...dialog_trigger_attrs }}`, etc.) pour câbler Dialog, Sheet, DropdownMenu | Réécrire les `aria-*` et `data-action` à la main |
| Un seul kit visuel (`--kit shadcn`) dans le projet | Mélanger shadcn et Bootstrap, ou deux bibliothèques d'icônes |
| AssetMapper, Tailwind via `symfonycasts/tailwind-bundle`, pile de polices système | Installer un bundler (Encore, Vite, `symfony/reprise`) ou une police web « pour faire joli » |
| Bordures et deux tons pour la profondeur ; ombre seulement sous Dialog, Sheet, DropdownMenu | Ombres sur les cartes, dégradés, arrière-plans décoratifs |
| Mouvement de 150 à 200 ms sur l'ouverture/fermeture uniquement ; 0 sous `prefers-reduced-motion` | Barre d'avancement animée, apparition en cascade, effets de survol animés |
| Une seule densité aérée, sur les cartes comme sur les tableaux | Un mode « compact » pour les tableaux, un corps à 13 px sur le journal d'audit |
| `{colors.destructive}` réservé aux erreurs et au bouton de confirmation d'une action irréversible | Rouge pour « en retard », « priorité haute » ou pour attirer l'attention |
| Chiffres tabulaires (`{typography.stat}`) sur les pourcentages et compteurs | Un pourcentage qui fait sauter la mise en page quand il passe de 9 à 10 |
