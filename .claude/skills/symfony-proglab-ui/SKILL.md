---
name: symfony-proglab-ui
description: >-
  Utiliser symfony/ux-toolkit avec le kit Shadcn UI pour obtenir des composants Twig
  prêts à l'emploi (boutons, dialogues, formulaires, tableaux, cartes...) sans écrire
  React ni JSX : le toolkit copie le code source de chaque composant dans le projet
  plutôt que d'installer une dépendance, exactement comme le fait le CLI shadcn/ui
  d'origine. Utilise ce skill dès qu'on demande d'ajouter un bouton, un dialogue/modal,
  un menu déroulant, un tableau, une carte, un formulaire stylé, une barre latérale, un
  composant Shadcn, d'installer le UX Toolkit, de mettre en place un design system, ou
  demande comment styliser l'interface sans repartir de zéro à chaque composant. Pour
  savoir *où* doit vivre le comportement d'un composant (Stimulus, Live Component, Turbo
  Stream) une fois qu'il est installé, `symfony-proglab-frontend` reste l'arbitre — ce
  skill dit quel composant visuel installer et comment le thémer, pas comment il se
  comporte.
---

# UI : symfony/ux-toolkit et le kit Shadcn

> **Niveau : à la demande** — un projet qui écrit ses classes Tailwind à la main n'a
> rien à faire de ce skill. Il devient pertinent dès qu'on veut un jeu de composants
> cohérent (boutons, dialogues, formulaires) sans les reconstruire un par un.

**Ce fichier est sourcé depuis la documentation publiée du paquet plutôt que vérifié
contre un `vendor/` réel, à la différence du reste de la suite** — `symfony/ux-toolkit`
est marqué **EXPERIMENTAL** par son propre README ("likely to change, or even change
drastically"), et n'a jamais été installé dans un projet de référence pour cette suite.
Traite tout ce qui suit comme un point de départ à confronter à
`vendor/symfony/ux-toolkit` et à [ux.symfony.com/toolkit](https://ux.symfony.com/toolkit),
pas comme un fait établi.

## Ce que fait le toolkit : il copie, il ne dépend pas

`symfony/ux-toolkit` ne s'installe pas comme une bibliothèque qu'on appelle depuis son
code. Une commande copie le code source d'**un** composant dans le projet — un fichier
Twig, parfois un contrôleur Stimulus qui l'accompagne — et à partir de là, ce composant
est un fichier du projet comme un autre : plus de lien avec le paquet, aucune garantie
de rétrocompatibilité, aucune mise à jour automatique. C'est le même modèle que le CLI
`shadcn/ui` d'origine en React, transposé à Twig : le paquet est un générateur, pas une
dépendance d'exécution — d'où `composer require --dev`, jamais `require`.

Conséquence directe : mettre à jour le toolkit ne touche à rien dans le projet.
Récupérer une correction ou une nouvelle variante d'un composant déjà installé veut
dire relancer sa commande d'installation et relire le diff — exactement comme on
relirait un `recipes:update` (`symfony-proglab-upgrade`).

## Prérequis : Tailwind, sans sortir d'AssetMapper

Le kit Shadcn est un design system Tailwind ; il a besoin de Tailwind pour produire
quoi que ce soit de stylé. Deux voies existent en amont (AssetMapper ou Webpack
Encore) — **cette suite ne retient que la première**, pour rester cohérente avec le
rejet du bundler déjà posé dans `symfony-proglab-frontend` :

```bash
composer require symfonycasts/tailwind-bundle    # si ce n'est pas déjà fait
composer require --dev symfony/ux-toolkit
```

Le kit ajoute ensuite deux paquets CSS via l'importmap (`shadcn` et `tw-animate-css`)
et attend que `assets/styles/app.css` importe Tailwind, ces deux feuilles de style, et
un jeu de variables de thème au format `oklch` (couleurs primaire/secondaire/
destructive, rayons de bordure) déclinées pour le mode clair et le mode sombre. Les
valeurs exactes à écrire dépendent de la version installée — la commande d'installation
du premier composant les affiche ou les documente ; ne les recopie pas d'un exemple en
ligne sans les comparer à ce que la commande donne réellement.

## Installer un composant

```bash
php bin/console ux:install button --kit shadcn
```

```twig
<twig:Button>Enregistrer</twig:Button>
<twig:Button variant="destructive">Supprimer</twig:Button>
<twig:Button variant="outline" size="sm">Annuler</twig:Button>
```

Chaque composant a ses propres props (`variant`, `size`, parfois `as` pour changer la
balise rendue) — elles sont documentées sur la page du composant sur
`ux.symfony.com/toolkit/kits/shadcn/components/<nom>`, pas ici : les reproduire dans ce
fichier garantirait qu'elles deviennent fausses à la première variante ajoutée en amont.

**Un composant interactif se compose de plusieurs sous-composants**, avec le même
préfixe :

```twig
<twig:Dialog id="edit_profile">
    <twig:Dialog:Trigger>
        <twig:Button variant="outline" {{ ...dialog_trigger_attrs }}>Modifier</twig:Button>
    </twig:Dialog:Trigger>
    <twig:Dialog:Content>
        <twig:Dialog:Header>
            <twig:Dialog:Title>Modifier le profil</twig:Dialog:Title>
        </twig:Dialog:Header>
        <twig:Dialog:Footer>
            <twig:Dialog:Close>
                <twig:Button variant="outline" {{ ...dialog_close_attrs }}>Annuler</twig:Button>
            </twig:Dialog:Close>
        </twig:Dialog:Footer>
    </twig:Dialog:Content>
</twig:Dialog>
```

`{{ ...dialog_trigger_attrs }}` et `{{ ...dialog_close_attrs }}` épandent les attributs
(`aria-*`, `data-action` Stimulus) qui relient le déclencheur au reste du dialogue —
ne les remplace pas par des attributs écrits à la main, c'est ce qui câble
l'accessibilité et l'ouverture/fermeture.

## Une fois copié, c'est ton code — les règles habituelles s'appliquent

Un composant installé n'est plus un composant "du toolkit", c'est un composant Twig du
projet, et tout ce que `symfony-proglab-frontend` dit déjà des composants Twig
s'applique sans exception : il prend des DTOs, jamais des entités
(`references/components.md` de ce skill-là) ; aucune règle métier ne s'y glisse ; et la
question de savoir si un bouton déclenche un Live Component, un Turbo Stream ou un
simple contrôleur Stimulus reste tranchée par ce même skill, pas par celui-ci.

Ce que ce skill ajoute par-dessus, c'est uniquement : quel visuel installer, comment le
thémer, et comment ses sous-composants s'assemblent.

## Composants agnostiques et autres kits

Le toolkit ne se limite pas à Shadcn : un kit Bootstrap 5.3 existe (`--kit bootstrap`),
et des composants "design-system-agnostic" — sans dépendance à un système visuel
précis — couvrent des besoins Symfony récurrents. Change `--kit shadcn` dans la
commande `ux:install` en conséquence si le projet a déjà fait un autre choix de design
system ; ne mélange pas deux kits visuels sur un même projet, pour la même raison que
la suite rejette déjà deux formateurs de style en même temps
(`symfony-proglab-quality`).

## Quand quelque chose ne va pas

| Symptôme | Cause probable |
|---|---|
| Le composant installé ne s'affiche pas stylé | `assets/styles/app.css` n'importe pas les feuilles `shadcn`/`tw-animate-css`, ou `tailwind:build` n'a pas tourné |
| Les variables de thème n'ont aucun effet | Les variables `oklch` ne sont pas déclarées, ou déclarées dans le mauvais sélecteur (clair vs sombre) |
| `Component "Dialog:Trigger" not found` | Seul `Dialog` a été installé — certains composants composés nécessitent d'installer chaque sous-partie, vérifie ce que la commande d'installation a réellement copié |
| Le dialogue s'affiche mais ne s'ouvre pas | `{{ ...dialog_trigger_attrs }}` a été remplacé par des attributs écrits à la main, ou le contrôleur Stimulus du composant n'a pas été copié |
| Deux styles de composants coexistent sur une page | Deux kits (`--kit shadcn` et `--kit bootstrap`) installés sur le même projet |

## Fichiers de référence

Aucun pour l'instant, délibérément : le catalogue de composants change trop vite pour
qu'une copie locale reste juste plus de quelques semaines. La documentation officielle
(`ux.symfony.com/toolkit`) est la référence à jour ; ce fichier documente le mécanisme,
pas le catalogue.
