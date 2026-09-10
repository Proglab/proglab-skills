# Travail differe

Entrees ajoutees par bmad-build. Append-only : ne pas modifier les entrees existantes.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-amorcer-le-depot-et-la-frontiere-des-deux-racines.md`
  summary: La couche deptrac `Http` ne couvre que `HttpFoundation`, donc un service prenant `HttpClientInterface` ou une classe de `HttpKernel` n'est pas vu par le contrat de couches.
  evidence: Le collecteur vient mot pour mot de `.claude/skills/symfony-proglab-quality/assets/deptrac.yaml`. L'elargir est une decision de standard maison qui s'appliquerait a tous les projets proglab, pas une correction propre a cette story.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-amorcer-le-depot-et-la-frontiere-des-deux-racines.md`
  summary: `APP_SECRET` n'a aucun chemin de production : `.env` le laisse vide, `.env.dev` commite une valeur de dev, et il n'existe ni `.env.prod` ni entree de coffre.
  evidence: Non verifie faute de cible de deploiement. Ce qui trancherait : la story de deploiement, qui possede le coffre de secrets Symfony que l'architecture nomme comme seul magasin de production. Si rien n'est fait, `framework.secret` resout a la chaine vide en production et affaiblit silencieusement les jetons CSRF que l'epic rend obligatoires sur toute ecriture.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-amorcer-le-depot-et-la-frontiere-des-deux-racines.md`
  summary: La licence MIT amont exigeait la conservation de sa notice ; elle a ete remplacee par une licence tous droits reserves alors que `README.md`, `_bmad/` et `.claude/skills/` restent derives de l'amont.
  evidence: Non verifie : question juridique, pas technique. Ce qui trancherait : l'inventaire de ce qui reste ecrit par l'auteur amont dans le depot. Si du materiel amont survit, une ligne d'attribution ou un fichier THIRD-PARTY est probablement du.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-amorcer-le-depot-et-la-frontiere-des-deux-racines.md`
  summary: `ModuleDescriptor::translationDomain()` est declare et implemente deux fois, mais consomme par personne.
  evidence: Surface non exercee sur la seule porte publique du socle. Le corriger demande d'elargir `ModuleRegistry` a une methode publique de plus, ce qui n'est pas une correction directe : a reprendre quand une story en a reellement besoin.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-amorcer-le-depot-et-la-frontiere-des-deux-racines.md`
  status: CLOS — tranché par Fabrice le 2026-09-10
  summary: Résolution de l'entrée « licence MIT amont » ci-dessus, et correction d'une erreur qu'elle contenait.
  evidence: |
    **Décision de Fabrice :** la réécriture est complète et ne s'inspire que d'une
    structure ; une structure ne se protège pas par le droit d'auteur, donc aucune notice
    d'attribution n'est due. La licence propriétaire interne reste telle quelle. Question
    close, à ne pas rouvrir sans élément nouveau.

    **Ce que la vérification a établi**, pour que la décision soit traçable : le dépôt est
    bien un fork (4 commits de Yoan Bernabeu, 22 de Fabrice ensuite). Le commit `794ea4a`
    a réécrit 16 708 lignes contre 16 009 supprimées — traduction en français, renommage
    `yoandev` vers `proglab`, refonte. `.claude/skills/` fait aujourd'hui 26 750 lignes de
    markdown, et l'écart avec l'amont est de 37 848 insertions contre 15 026 suppressions.

    **Erreur de l'entrée d'origine, corrigée ici** (append-only : l'entrée fautive reste
    en place au-dessus) : elle affirmait que `_bmad/` était « dérivé de l'amont ». C'est
    faux. BMAD-METHOD est un tiers sans rapport avec le fork, installé au commit `9f9c01b`.
    La question qu'il pose est distincte, et ouverte ci-dessous.

    **Les SHA cités ci-dessus ne résolvent plus.** `794ea4a`, `9f9c01b` et les quatre
    commits amont appartiennent à un historique de 27 commits volontairement écrasé le
    2026-09-10 en un commit unique, `5724cc0` « 🎉 begin the project ». Ce n'est pas une
    erreur de saisie : les chiffres ci-dessus ont été mesurés sur cet historique avant sa
    suppression, et ils ne sont plus vérifiables depuis le dépôt. Conséquence à assumer —
    la provenance du fork n'est plus démontrable par git ; cette entrée en est la seule
    trace.

    Ni Claude ni Fabrice ne sont juristes ; ceci n'est pas un avis juridique.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-amorcer-le-depot-et-la-frontiere-des-deux-racines.md`
  summary: `_bmad/` embarque BMAD-METHOD, du code tiers redistribué dans chaque dérivé, sans qu'aucun fichier de licence n'accompagne ce code dans le dépôt.
  evidence: |
    Constaté en vérifiant la question de la licence amont : aucun fichier de licence sous
    `_bmad/`, et aucune mention de la licence de BMAD-METHOD ailleurs dans le dépôt. Or
    `_bmad-output/` est explicitement embarqué dans la release (AD-10), et le socle se
    clone chez chaque client.

    Ce qui trancherait : la licence réelle de BMAD-METHOD chez son éditeur, et ce qu'elle
    exige en cas de redistribution. Si elle demande la conservation d'une notice — la
    plupart des licences permissives le font —, un fichier de licence sous `_bmad/` ou une
    entrée `NOTICE` est probablement dû. Distinct de la question de la licence amont, qui
    est close.

    À traiter avant la première livraison chez un client, pas avant.
