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

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: `--sidebar-accent` est livré sans `--sidebar-accent-foreground`, alors que tous les autres rôles de fond vont par paire.
  evidence: |
    Vérifié dans `assets/styles/theme.css` : `--card`/`--card-foreground`,
    `--primary`/`--primary-foreground`, `--status-done`/`--status-done-foreground` vont
    par paire ; `--sidebar-accent` est seul. `DESIGN.md` ne donne pas ce jeton, et le bloc
    gelé de la story 1.3 interdit d'en inventer un — c'est pourquoi il n'a pas été ajouté.
    La story 2.3, qui pose la coque et l'entrée de navigation courante, aura un fond
    d'item actif sans rôle de texte associé : elle devra soit faire ajouter le jeton à
    `DESIGN.md`, soit s'appuyer sur `--sidebar-foreground` et le documenter.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: Les douze rôles typographiques du socle ne sont consommés par aucun composant du kit, qui emploie les paliers Tailwind par défaut.
  evidence: |
    Vérifié dans les six composants copiés : `text-xs`, `text-sm`, `text-base`, que
    `theme.css` ne remappe pas. Le corps à 15 px n'existe que sur `<body>`, et
    `ThemeTokensTest` vérifie que les jetons existent, jamais qu'ils servent. Deux
    exceptions déjà refermées par la story 1.3 : `Card:Title` et `Alert:Title` portent
    maintenant `text-heading` et `text-subheading`.

    Ce qui trancherait : décider si le socle remappe les paliers Tailwind (`--text-sm`
    devient le rôle `body-sm`, etc.) ou s'il édite chaque composant copié. Le premier
    change le sens de classes que tout développeur croit connaître ; le second se paie à
    chaque `ux:install`. À trancher à la story 1.4, qui rend les premiers écrans réels.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: Un serveur client réellement coupé du réseau sortant ne peut pas construire la feuille de style : le binaire Tailwind se télécharge au premier build.
  evidence: |
    `assets/vendor/` est commité, donc les dépendances JavaScript n'ont pas besoin du
    réseau. Le binaire Tailwind, lui, est téléchargé par `symfonycasts/tailwind-bundle`
    dans `var/` au premier `tailwind:build`, et le déploiement construit sur le serveur.
    Le README a été corrigé pour ne plus promettre l'inverse.

    Ce qui trancherait : décider à la story 3.1 si le socle commite la feuille compilée,
    si `var/tailwind/` devient un répertoire partagé de Deployer, ou si le binaire est
    déposé une fois à la main sur le serveur client.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: `asset-map:compile` n'est exercé par aucune façade de la porte : le chemin `when@prod` n'est jamais parcouru en CI.
  evidence: |
    `config/packages/asset_mapper.yaml` bascule `missing_import_mode` en `warn` sous
    `when@prod`, et rien dans le `Makefile` ni dans le workflow ne lance
    `asset-map:compile`. Un import cassé qui ne casse plus en production se découvrirait
    au premier déploiement client — exactement ce que `strict` en développement cherche à
    éviter. Non corrigé ici parce qu'ajouter cette étape change la forme de la porte et
    demande de décider à quelle catégorie elle appartient.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: `HardcodedColorTest` déclenche un faux positif sur un fragment d'URL de trois caractères hexadécimaux, sans mécanisme de suppression.
  evidence: |
    Le motif `#[0-9a-f]{3}…` attrape `href="#add"`, `"#face"`, `"#fee"`. Aucune occurrence
    aujourd'hui, et la story 1.4 pose un lien d'évitement vers `#main`, qui passe. Le
    correctif demande d'exiger un contexte de couleur (attribut `style`, valeur arbitraire
    Tailwind, propriété CSS) et un marqueur d'exemption — plus de complexité que le défaut
    n'en justifie aujourd'hui.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: `KitIntegrityTest` refusera le premier composant anonyme propre au socle placé dans `templates/components/`.
  evidence: |
    `every_copied_component_belongs_to_the_declared_kit()` exige que chaque composant du
    répertoire appartienne au catalogue shadcn. Le socle n'a aucun composant maison
    aujourd'hui, donc la liste d'exceptions qui refermerait l'écart n'aurait rien à
    contenir. À traiter le jour où le premier arrive — vraisemblablement la story 1.4 ou
    la 2.3.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: Le binaire Tailwind est retéléchargé à chaque exécution du job « Tests », sans `actions/cache`.
  evidence: |
    Le job construit la feuille avant la suite, et le bundle télécharge le CLI autonome
    dans `var/` — un répertoire que le runner ne conserve pas. Coût en temps à chaque
    exécution, et une dépendance de plus à une panne de GitHub Releases. Le correctif
    ajoute un bloc de cache clé sur `binary_version`, à maintenir avec lui.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-installer-le-kit-de-composants-et-poser-le-theme-du-socle.md`
  summary: `--motion-fade` et `--motion-panel` ne sont dans aucun namespace Tailwind : aucun utilitaire n'est généré.
  evidence: |
    Vérifié sur la sortie compilée : `--motion-*` n'est pas un namespace que Tailwind
    reconnaît, donc il n'existe pas de classe `duration-fade`. Sans conséquence — les
    jetons se lisent en valeur arbitraire (`duration-[var(--motion-panel)]`), et leur
    remise à zéro sous `prefers-reduced-motion` fonctionne de la même façon. Ce qui
    trancherait : la story 1.4, qui écrira les premières transitions à la main, dira si
    un namespace `--transition-duration-*` vaut le renommage.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  status: RÉ-ANCRÉ SUR LA STORY 2.3 — remap typographique
  summary: Ré-ancrage de l'entrée de la story 1.3 « Les douze rôles typographiques du socle ne sont consommés par aucun composant du kit ». La 1.4 devait trancher ; elle n'a pas de quoi.
  evidence: |
    La story 1.3 renvoyait l'arbitrage à la 1.4, « qui rend les premiers écrans réels ».
    La 1.4 n'a écrit que deux textes visibles — « Aller au contenu » dans le gabarit et
    « Accueil » comme nom de page. Un lien d'évitement en `text-label` et un titre de page
    ne suffisent pas à décider entre remapper les paliers Tailwind (`--text-sm` devient le
    rôle `body-sm`, ce qui change le sens de classes que tout développeur croit connaître)
    et éditer chaque composant copié (ce qui se paie à chaque `ux:install`).

    Ré-ancré sur la **story 2.3**, qui pose la coque, la Sidebar et les premiers écrans
    denses : c'est là que la densité typographique devient une vraie question. L'entrée
    d'origine reste ouverte au-dessus ; celle-ci en déplace l'échéance, elle ne la referme
    pas.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  status: CLOS — tranché par la story 1.4
  summary: Résolution de l'entrée de la story 1.3 « `--motion-fade` et `--motion-panel` ne sont dans aucun namespace Tailwind ». Aucun renommage n'est dû.
  evidence: |
    La 1.3 disait : « la story 1.4, qui écrira les premières transitions à la main, dira si
    un namespace `--transition-duration-*` vaut le renommage ». Elle l'a écrite — une
    seule, celle du lien d'évitement — sous la forme
    `transition-all duration-[var(--motion-fade)]`, la valeur arbitraire déjà prévue par
    l'entrée d'origine.

    Vérifié par un test plutôt que par lecture :
    `AccessibilityFloorTest::reduced_motion_brings_every_motion_token_to_zero` mesure que
    **chaque** jeton `--motion-*` déclaré dans `:root` est ramené à `0ms` sous
    `prefers-reduced-motion: reduce`, et
    `BaseTemplateTest::the_hand_written_transition_reads_the_motion_token` que la
    transition du gabarit lit bien ce jeton. La promesse tient sans namespace.

    Ce qui rouvrirait la question : un écran qui écrit assez de transitions à la main pour
    que `duration-[var(--motion-panel)]` devienne pénible à relire. Une seule occurrence ne
    le justifie pas.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  status: RÉ-ANCRÉ SUR LA STORY 2.3 — composant anonyme du socle
  summary: Ré-ancrage de l'entrée de la story 1.3 « `KitIntegrityTest` refusera le premier composant anonyme propre au socle ». La 1.4 n'en a créé aucun.
  evidence: |
    Le gabarit tient en un seul fichier — lien d'évitement, repères, région d'annonces — et
    `templates/layout/` n'a donc pas été créé : un partiel qui n'a qu'un appelant est un
    fichier de plus à ouvrir pour lire une page. Rien n'est entré dans
    `templates/components/`, donc `every_copied_component_belongs_to_the_declared_kit()`
    n'a rien de nouveau à refuser.

    La règle reste posée pour la suite : les partiels du socle vont dans
    `templates/layout/`, hors du périmètre de `KitIntegrityTest` ; `templates/components/`
    reste le miroir du kit shadcn. Ré-ancré sur la **story 2.3**, qui pose la coque et aura
    de vrais partiels à ranger.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  summary: Le job « Accessibility » s'ajoute aux cinq jobs à déclarer en « required status checks » dans la branch protection GitHub.
  evidence: |
    Complément à l'entrée de la story 1.2 « Rien dans le dépôt ne rend la CI bloquante ».
    La liste des noms de jobs à exiger sur github.com/Proglab/proglab-skills passe de cinq
    à six : Tests, Static analysis, Code style, Layer contract, Linters and audits,
    **Accessibility**. Tant que ce réglage n'est pas posé, le plancher d'accessibilité
    rapporte sans bloquer — et c'est précisément le contrat de l'epic (« vérifié par un
    test automatisé en CI, jamais par relecture ») qui reste alors à moitié tenu.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  summary: Le sélecteur du Flash utilisé par `page-focus` (`[data-flash]`) est un contrat inventé par la 1.4, que personne ne rend encore.
  evidence: |
    L'ordre de focus de UX-DR-6 nomme « le Flash » sans dire à quoi le reconnaître.
    `page_focus_controller.js` a donc posé `[data-flash]` comme troisième sélecteur, entre
    le premier champ `aria-invalid` et le `h1`. Aucun template ne porte cet attribut
    aujourd'hui, et rien ne le vérifie : le contrôleur passerait simplement au `h1`.

    À refermer par la story qui livre le composant Flash — vraisemblablement la 1.6 (Flash
    après connexion) ou la 2.3 (coque). Elle doit soit poser `data-flash` sur l'élément,
    soit changer le sélecteur ici ; dans les deux cas, l'écart doit cesser d'être muet.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  summary: `config/packages/ux_turbo.yaml` active `framework.csrf_protection.check_header`, un réglage inerte tant qu'aucun jeton CSRF sans état n'est déclaré.
  evidence: |
    Fichier posé par la recette de `symfony/ux-turbo` et conservé tel quel, avec un
    commentaire qui dit pourquoi. La vérification d'en-tête ne s'applique qu'aux jetons
    déclarés dans `framework.csrf_protection.stateless_token_ids`, et le socle n'en a aucun
    avant la story 1.6, qui pose le premier formulaire.

    Conséquence à surveiller à la 1.6 : au moment où ce formulaire arrive, il faut décider
    si le socle passe au CSRF sans état (auquel cas cette ligne devient juste et
    `csrf_protection_controller.js` la sert) ou reste sur des jetons en session (auquel cas
    la ligne reste inerte et devrait être retirée). L'epic dit « CSRF obligatoire sur toute
    écriture, sans exception » sans trancher entre les deux mécanismes.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  summary: Le contrôle d'accessibilité ne visite que les routes GET sans paramètre servies par `App\Core\` — aujourd'hui une seule, `app_home`.
  evidence: |
    `AccessibilityFloorTest::pages()` filtre sur `_controller` commençant par `App\Core\`
    et écarte les chemins à paramètre. Deux écarts assumés, écrits ici pour qu'ils ne se
    découvrent pas plus tard :

    Une route à paramètre (`/password/reset/{token}`, story 1.9 ; `/audit-log/{id}`,
    Epic 4) n'est pas visitée : lui inventer une valeur rendrait une page d'erreur au lieu
    d'un écran. La story qui pose la première devra fournir un jeu de valeurs —
    vraisemblablement par une fixture — ou accepter que son écran sorte du plancher.

    Une page derrière le pare-feu (toutes celles de l'Epic 2) ne sera pas visitée non plus
    tant que le contrôle ne saura pas s'authentifier. À traiter à la story 1.6, qui livre la
    connexion : c'est le moment où `loginUser()` devient disponible et où le nombre de pages
    non couvertes cesse d'être zéro.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  status: CLOS — refermé pendant la story 1.4 par l'exception Node accordée par Fabrice
  summary: Résolution de l'entrée « Les deux contrôleurs Stimulus `page-focus` et `announce` n'ont aucun test — la porte n'exécute pas de JavaScript », ajoutée puis retirée à tort du fichier pendant cette même story.
  evidence: |
    L'observation était juste : quatre des huit lignes de la matrice d'edge cases du spec
    décrivent du comportement JavaScript — premier chargement ignoré, focus sur le `h1`
    après navigation Turbo, focus sur le bloc 422, focus et annonce du Flash — et aucun
    test PHP ne pouvait les prouver.

    Fabrice a accordé une exception explicite pendant le build : les tests ont le droit
    d'utiliser Node, la chaîne d'assets non. Les quatre lignes sont désormais couvertes par
    `tests/js/`, exécuté par la catégorie « Accessibility » de la porte. L'exception est
    bornée par `AssetPipelineTest::the_node_exception_is_confined_to_the_test_directory()`,
    et `nothing_in_the_chain_needs_node()` de la story 1.3 n'a pas été touché.

    Deux choses restent hors de portée et le resteront : jsdom n'a pas de moteur de mise en
    page, donc ni l'ordre de tabulation réel, ni le rendu à 320 px, ni ce qu'un lecteur
    d'écran annonce vraiment. Les trois vérifications manuelles du README sont le complément
    assumé, pas un oubli.

    Note de procédure : l'entrée d'origine a été supprimée du fichier sur ma demande, contre
    la règle « append-only » écrite en tête. C'était mon erreur ; elle est refermée ici par
    ajout, comme les précédents du dépôt le font.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  summary: Le favicon de la recette a disparu avec le gabarit et rien ne le remplace : chaque page déclenche un `/favicon.ico` en 404.
  evidence: |
    Constaté pendant la relecture de la story 1.4. `templates/base.html.twig` a remplacé le
    gabarit de recette, qui portait l'espace réservé de favicon posé par Symfony ; le
    nouveau gabarit ne déclare aucun `<link rel="icon">`. Sans déclaration, tout navigateur
    demande `/favicon.ico` sur chaque page, et le socle répond 404. C'est une requête morte
    par page et une ligne de bruit par page dans les logs — pas une panne, mais un écart
    visible dès le premier écran.

    Reporté, pas ignoré, et pour une raison de propriété : le socle n'a jamais eu de favicon
    de *marque*, seulement celui de la recette. Or le favicon appartient à la surface de
    rebranding — la même que le logo de la sidebar — et cette surface est possédée par les
    stories 1.11 et 1.12. Poser ici un favicon du socle reviendrait à créer un artefact de
    marque que ces stories devraient ensuite déplacer, et à trancher à leur place où vivent
    les fichiers de marque d'un dérivé.

    À refermer par 1.11 / 1.12 : déclarer l'icône dans le gabarit, la ranger avec les autres
    fichiers rebrandables, et — puisque le socle vérifie ses écrans plutôt qu'il ne les
    relit — ajouter la déclaration au contrat que `BaseTemplateTest` tient.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-poser-le-gabarit-de-base-et-le-plancher-d-accessibilite.md`
  summary: `DESIGN.md § Colors` annonce `--ring` à ≈ 2,05:1 sur la foi d'un hex approché faux ; la valeur réelle du jeton mesure 2,59:1.
  evidence: |
    Établi par le calcul pendant la story 1.4, en construisant `tests/Core/Accessibility/Contrast.php`.
    Le tableau du spine donne `--ring` pour `#b5b5b5`, soit ≈ 2,05:1 sur blanc. Mais le jeton
    livré vaut `oklch(0.708 0 0)`, qui converti en sRGB donne `#a1a1a1` et mesure 2,59:1.
    C'est le hex approché du spine qui est faux, pas la conversion : les trois autres paires
    du tableau se recoupent à moins de 1 % près (19,79 / 4,73 / 7,15 / 4,76 mesurés contre
    ≈ 19 / 4,74 / ≈ 7,2 / 4,77 annoncés).

    Sans conséquence pratique aujourd'hui : `--ring` est de toute façon l'un des deux défauts
    shadcn conservés en choix assumé, sous le seuil de 3:1 dans les deux lectures. Le chiffre
    faux reste néanmoins écrit dans le document que les stories suivantes citent, et
    `brand.css` recopie le « ≈ 2,05:1 » dans son commentaire d'invitation au rebranding.

    Pourquoi c'est reporté plutôt que corrigé : le bloc gelé de la story 1.4 interdit de
    toucher `_bmad-output/planning-artifacts/`, et l'erreur est antérieure à cette story. La
    correction est une décision de spine — celle de Fabrice —, pas une correction de build.
    Quand elle est prise, deux endroits suivent : la ligne `--ring` du tableau de
    `DESIGN.md § Colors`, et le commentaire correspondant d'`assets/styles/brand.css`.
