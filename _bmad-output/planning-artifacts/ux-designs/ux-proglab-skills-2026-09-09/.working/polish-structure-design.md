# Lentille structure — DESIGN.md (bmad-review, doc_standards)

Modèle retenu : Référence / base de données. Ordre des sections de niveau 2 verrouillé par la spec DESIGN.md. 6 173 mots (1 513 frontmatter, 4 660 corps).

| Pass | Texte d'origine | Texte révisé | Changements |
|---|---|---|---|
| structure | § Layout & Spacing — puce « Grille d'une ligne de tâche en développement » et § Components — ligne « Ligne de tâche (dev) » | MERGE : ne garder dans § Layout que la valeur de grille et le repli sous `md` ; placement du bouton « Lancer », raison, « Notes internes », repli production restent dans la ligne Components | Redondance quasi mot pour mot (≈ 50 mots) |
| structure | Règle « formulaires et tableaux vivent dans une Card » justifiée cinq fois (§ Colors puces muted-foreground / destructive, tableau lignes 2 et 7, § Layout, § Components Table et Champ) | CONDENSE : règle + justification une seule fois dans § Layout & Spacing ; ailleurs renvoi nu « (§ Layout & Spacing) » | ≈ 60 mots |
| structure | § Components, 2e paragraphe d'intro (héritage `badge-*` / `alert-destructive` / `flash`, hauteurs = `min-height`) | MOVE en commentaires YAML à la clé `components:` du frontmatter ; garder en § Components la seule justification WCAG 1.4.12 | Information critique enterrée |
| structure | § Components — trois pointeurs « → Composition : mockups/key-*.html » dans les cellules Dialog, Tabs, Champ + phrase d'intro + renvoi de § Brand & Style | MOVE : bloc **Écrans clés.** après le tableau, à côté d'**Icônes.** et **Mouvement.** ; supprimer la phrase d'intro ; § Brand & Style pointe vers ce bloc | Cellules trop lourdes (≈ 30 mots) |
| structure | Écarts connus des maquettes dispersés (ligne Alert h2/h3, **Mouvement.**, règle de conflit en § Brand & Style) | MERGE : liste **Écarts connus des maquettes.** dans le bloc Écrans clés, règle de conflit en une ligne | Un seul endroit pour les divergences |
| structure | § Typography — paragraphe « Règles : … » (~140 mots) | CONDENSE en trois puces (capitales/graisses ; un seul h1, aucun niveau sauté ; MOVE anatomie `<h2><button aria-expanded>` vers la ligne Accordion de § Components) | ≈ 35 mots |
| structure | § Colors — **Principe « défauts shadcn »** après la liste, invoqué avant sa définition par les puces input et ring | MOVE : juste après le paragraphe d'ouverture de § Colors, avant la liste ; retirer les « ci-dessous » | Définir avant d'utiliser |
| structure | § Colors — puce double muted-foreground + muted-strong | CONDENSE (scinder) : une puce par jeton ; justification Card vers § Layout | Schéma constant |
| structure | § Brand & Style — premier paragraphe (~200 mots) | CONDENSE : scinder en **Nature du socle.** / **Registre.** / **Explorations.** (directions en liste courte à la fin) ; règle de conflit vers Écarts connus | Contrat de rebranding avant l'historique |
| structure | § Layout & Spacing — trois puces de grille | CONDENSE en tableau Élément · Grille · Sous `md` | ≈ 20 mots |
| structure | § Layout — puce « Formulaires et tableaux… » avec correspondance écran → composition en prose | CONDENSE : sous-liste de trois entrées | Lecture par écran |
| structure | Renvois « (règle n) » dans § Components, Icônes, Do's | QUESTION : ajouter sous l'intro de § Components « Les « règle n » renvoient aux règles de `symfony-proglab-accessibility`. » | Antécédent manquant (+ 10 mots) |
| structure | § Components — « Comportement : voir EXPERIENCE.md · Component Patterns » répété dans 8 lignes | CONDENSE : renvoi général dans l'intro ; dans les cellules, forme courte « Comportement : filtrage, tiroir, repères → EXPERIENCE.md » ; supprimer les renvois sans précision (Champ 2FA, Pagination) | ≈ 30 mots |
| structure | § Shapes — paragraphe unique mappant les rayons ; divergence avec le frontmatter `rounded:` (DEFAULT : Alert, Dialog, Sheet vs Alert, DropdownMenu, Sheet ; xl : Card, Dialog vs Dialog) | CONDENSE en liste ; QUESTION : réconcilier frontmatter et § Shapes sur Dialog / DropdownMenu | Deux copies divergentes (≈ 20 mots) |
| structure | § Shapes — icône de statut (disque 28 px, bordure par statut) | MOVE vers § Components (ligne Accordion ou ligne « Icône de statut ») ; § Shapes ne garde que « ronde » | Spécification enfouie |
| structure | § Do's and Don'ts — 16 lignes, règles a11y dans l'ordre 2,1,3,4,5,6,7 | PRESERVE la section ; réordonner en quatre blocs : rebranding · accessibilité (1 → 7) · stack et kit · rendu | Vérifiable d'un regard |
| structure | Frontmatter `typography:` — `fontFamily` répété sur 12 rôles | PRESERVE (imposé par le schéma) | 0 mot |

Mineurs : durées d'animation énoncées trois fois (frontmatter, lignes Accordion / Sheet, **Mouvement.**) ; colonne « Usage » du tableau de contrastes (lignes 2, 8, 9) à raccourcir.

Bilan : 17 recommandations (7 CONDENSE, 4 MOVE, 2 MERGE, 2 QUESTION, 2 PRESERVE), ≈ 245 mots (≈ 4 %). Gain principal : scannabilité de Components, Layout, Colors.
