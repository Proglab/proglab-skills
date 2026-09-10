# Lentille prose — DESIGN.md (bmad-review, doc_standards)

Voix à préserver : registre de référence dense au présent, phrases-slogans (« Plat. », « la barre illustre, le texte porte »), incises entre tirets cadratins, personas nommés, vocabulaire du kit (« épandent les attributs »), distinction Card / carte, minuscule sur socle / dérivé en texte courant.

| Pass | Texte d'origine | Texte révisé | Changements |
|---|---|---|---|
| prose | Tout le corps | Espace insécable (U+00A0 ou fine U+202F) avant « : », « ; », « ? », « ! », à l'intérieur des « », entre nombre et unité (« 38 px », « 200 ms », « 4,74:1 ») | 0 insécable dans le fichier |
| prose | Principe « défauts shadcn » : « kit Shadcn UI … tokens du socle » | « kit shadcn … jetons du socle » | Terminologie |
| prose | Puce ring : « jamais supprimé, seulement remplacé par… » | « et jamais supprimé ; s'il est remplacé, c'est par `:focus-visible` + `box-shadow` avec `--ring`. » | Condition explicite |
| prose | Don't : « Relever un défaut du kit » | « Renforcer un défaut du kit » | « Relever » = signaler |
| prose | Brand & Style : « les valeurs par défaut passent, rien ne garantit qu'une charte client passe. » | « les valeurs par défaut passent les seuils ; rien ne garantit qu'une charte client les passe. » | Complément manquant |
| prose | Rythme vertical : « le résumé occupe `20px {spacing.card-padding}` ; les lignes de tâche `{spacing.row-padding}` en haut et en bas, séparées d'une bordure. » | « le résumé a un padding de `20px {spacing.card-padding}` ; chaque ligne de tâche prend `{spacing.row-padding}` en haut et en bas, et une bordure la sépare de la suivante. » | Verbe manquant |
| prose | Elevation : phrase de 45 mots « Les seules exceptions sont … et un voile … » | « Seules exceptions : les surfaces qui flottent réellement au-dessus de la page — Dialog, Sheet, DropdownMenu. Elles gardent l'ombre par défaut du kit shadcn, parce qu'elle décrit une vraie superposition ; Dialog et Sheet ajoutent un voile (`{components.dialog.overlay}`). » | Scission |
| prose | Ligne Tabs : « Le visuel du kit, pas sa sémantique `role="tab"` : comportement et balisage dans EXPERIENCE.md · Component Patterns. » | « On garde le visuel du kit, pas sa sémantique `role="tab"` ; comportement et balisage → EXPERIENCE.md. » | Verbe |
| prose | Anatomie h2 (→ ligne Accordion) : « (sinon le nom accessible du bouton est toute la concaténation et le titre perd sa navigabilité) » | « : sinon le nom accessible du bouton concatène titre, Badge et avancement, et le titre n'est plus atteignable en tant que titre. » | Référent |
| prose | Intro Components : « une feuille d'espacement de texte (WCAG 1.4.12) » | « une feuille de style utilisateur qui augmente l'espacement du texte (WCAG 1.4.12) » | Expression reçue |
| prose | Puce muted-strong : « est l'ajout de la direction Aéré : … qui est posée directement sur la page. » | « est le jeton ajouté par la direction Aéré : le gris de texte secondaire lisible sur le fond de page (≈ 7:1). Il sert aux phrases d'accueil, aux descriptions d'alerte, aux dépendances, à la raison sous un bouton « Lancer » et à la note de pied de la roadmap (« Cette page reflète le dernier déploiement… »), seul texte posé directement sur la page. » | Antécédent ; décision auteur : « seul texte » est l'intention |
| prose | Registre : « du blanc et du gris chaud de neutre » | « du blanc et du gris neutre, sans teinte » | Décision auteur : shadcn neutral est un gris sans teinte |
| prose | Écarts connus : « les spines l'emportent » | « les spines (ce fichier et EXPERIENCE.md) l'emportent » | Glose |
| prose | Principe directeur : « exigé par un FR » | « exigé par une exigence fonctionnelle (FR) du PRD » | Sigle |
| prose | Contrat item 2 : « le carré `{components.avatar}` portant les initiales du dérivé » | « le logo carré (`{rounded.md}`, mêmes couleurs que `{components.avatar}`) portant les initiales du dérivé » | Décision auteur : logo carré, avatar disque |
| prose | Typography : « parce que Marc lit sa roadmap » | « parce que Marc, le gérant (PRD, UJ-3), lit sa roadmap » | Persona glosé |
| prose | « **Statuts lisibles** (…) sont des alias » | « Les **statuts lisibles** (…) sont des alias » | Article |
| prose | « 15-16 px » ; « 150-200 ms » ; « 2-3 colonnes » | « 15 à 16 px » ; « de 150 à 200 ms » ; « deux ou trois colonnes » | Plages |
| prose | Frontmatter `rounded.xl` « carte epic », `card-gap` « cartes epic » | « carte d'epic », « cartes d'epic » | Cohérence |
| prose | Ligne Champ : « Jetons `autocomplete` » | « Valeurs `autocomplete` » | « jeton » réservé au design |
| prose | « en 600 », « en 500 » (Sidebar, Banner, Avatar, Accordion) | « en graisse 600 », « en graisse 500 » | Uniformité |
| prose | Ligne Titre de section : « (contexte modal : leur titre est celui du contexte) » | « (dans un contexte modal, ce titre tient lieu de titre de section) » | Circulaire |
| prose | Ligne Table : « (zone étendue en `::after` sur ce lien seul) » | « ; un `::after` sur ce lien — et sur lui seul — étend la zone cliquable à toute la ligne. » | Mécanisme explicite |
| prose | Ligne Champ 2FA : « avec la validité du code par email » | « indiquant la durée de validité du code envoyé par email » | Ellipse |
| prose | Formulaires : « champs de largeur pleine jusqu'à 480 px » | « champs en pleine largeur, 480 px au plus » | Ambiguïté |
| prose | Mineurs : « Combinaisons porteuses » → « porteuses de sens » ; gloser « jamais de pilule sur une surface » ; « et les informations » → « et les messages d'information » (Alert) ; « c'est `h3` » → « la règle est `h3` » ; unités px sur les points de rupture ; « La source d'exécution » → « La source à l'exécution » ; supprimer le commentaire `spacing:` dupliqué en tête de § Layout ; virgule après « (règle 3) — » | appliquer | |

Bilan : 33 corrections, aucune valeur, nombre ou règle modifié.
