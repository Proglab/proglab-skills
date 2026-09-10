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

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-bloquer-les-regressions-en-integration-continue.md`
  summary: Le gabarit `.claude/skills/symfony-proglab-quality/assets/phpstan.dist.neon` liste `phpstan-phpunit/rules.neon` sans `extension.neon`, ce qui fait echouer PHPStan au demarrage sur `CoversHelper introuvable`.
  evidence: Reproduit pendant la story 1.2, et corrige dans le `phpstan.dist.neon` du projet. Le gabarit du skill reste faux : tout projet proglab qui le copiera tel quel rencontrera la meme panne. La spec interdit de toucher `.claude/skills/`, donc la remontee amont est une decision de standard maison, distincte de cette story.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-bloquer-les-regressions-en-integration-continue.md`
  summary: `php bin/console importmap:audit` manque a la cible `audit` du Makefile et au job « Linters and audits », faute d'AssetMapper.
  evidence: Retire volontairement avec un commentaire nommant la story 1.3 dans les deux fichiers. Sans npm dans la boucle, rien d'autre ne signale une dependance JavaScript vulnerable : la ligne doit revenir des qu'AssetMapper entre au socle, sinon la categorie « audit » ne couvre plus que PHP en silence.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-bloquer-les-regressions-en-integration-continue.md`
  summary: Trois lignes de la matrice de la story 1.2 n'ont aucun test — « Test qui ecrit » (le critere d'acceptation n3), « Test rouge » et « Paquet vulnerable ».
  evidence: |
    **Tranche par Fabrice le 2026-09-10 : reporte a la story 1.6.**

    « Test qui ecrit » n'a pas de sujet aujourd'hui : le socle n'a ni entite ni migration,
    donc aucun test fonctionnel n'ecrit en base. Le mecanisme d'isolation est verifie trois
    fois indirectement (les deux lignes DAMA tenues ensemble par un test, `debug:config
    dama_doctrine_test` ordonne avant la suite dans le job, et la meme commande sortant en 1
    quand on retire le bundle), mais le rollback lui-meme n'est jamais exerce. L'option
    ecartee etait de creer la table de l'entite de fixture `DemoWidget` au bootstrap PHPUnit
    par le SchemaTool. **A rouvrir a la story 1.6**, avec l'entite Utilisateur et sa
    migration : c'est la que le critere se referme.

    « Test rouge » est tautologique — une assertion cassee fait echouer PHPUnit, donc le job.
    « Paquet vulnerable » exigerait d'installer un paquet reellement vulnerable pour prouver
    que `composer audit` echoue. Les deux sont documentes comme verifies par construction.

    Consequence assumee : la story 1.2 se cloture avec un critere d'acceptation ouvert.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-bloquer-les-regressions-en-integration-continue.md`
  summary: Rien dans le depot ne rend la CI bloquante : il faut declarer les cinq jobs en « required status checks » dans la branch protection GitHub.
  evidence: |
    L'Intent de la story annonce « une CI bloquante en ligne » et le README presente la
    porte comme bloquante. Le workflow ne declare que ses declencheurs (`push` sur main,
    `pull_request`) : en l'etat, un job rouge n'empeche aucun merge. Le correctif n'est pas
    du code — c'est un reglage de depot, a poser sur github.com/Proglab/proglab-skills, sur
    les cinq noms de jobs : Tests, Static analysis, Code style, Layer contract, Linters and
    audits. Tant que ce n'est pas fait, la story livre une porte qui rapporte sans bloquer.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-bloquer-les-regressions-en-integration-continue.md`
  summary: Ajouter au workflow un job hors categorie — typiquement le check agrege qu'appelle la branch protection — fera echouer le test de parite.
  evidence: |
    `QualityGateParityTest::both_facades_check_the_same_list_of_categories` compare
    l'ensemble des `name:` de jobs a l'ensemble des categories du Makefile : tout job
    supplementaire devient une « orpheline dans la CI ». C'est le comportement voulu
    aujourd'hui, et il deviendra genant des que l'entree ci-dessus sera traitee, un check
    agrege etant la facon usuelle de n'exiger qu'un seul status. Ce qui trancherait : un
    marqueur d'exemption explicite sur le job, plutot qu'un assouplissement de la
    comparaison. A traiter avec la branch protection, pas avant.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-bloquer-les-regressions-en-integration-continue.md`
  summary: Complement a l'entree « Test qui ecrit » : `enable_static_connection: false` couperait l'isolation DAMA sans qu'aucune verification actuelle ne bronche.
  evidence: |
    Append-only, donc consigne ici plutot que dans l'entree du dessus. La relecture a lu
    `vendor/dama/doctrine-test-bundle/src/DependencyInjection/Configuration.php:13,27-28` :
    `enable_static_connection` est un noeud a `true` par defaut qu'un
    `config/packages/test/dama_doctrine_test.yaml` peut passer a `false`. Dans ce cas
    `DatabaseIsolationTest` passe (les deux lignes sont toujours declarees) **et** le
    garde-fou `debug:config dama_doctrine_test` sort en 0 en affichant simplement la valeur.
    L'isolation serait coupee, chaque test fonctionnel commiterait dans `app_test`, et rien
    n'echouerait.

    Consequence pour la story 1.6 : le test qui fermera le critere n3 doit exercer le
    rollback **par le comportement** — ecrire une ligne, puis affirmer la table vide — et
    non se contenter de verifier une declaration de plus.
