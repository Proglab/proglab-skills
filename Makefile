# Porte de qualité — la façade locale.
#
# L'autre façade est .github/workflows/ci.yml, et les deux vérifient la même liste de
# catégories : un job de CI par catégorie, nommé d'après elle. Ce n'est pas une promesse
# tenue par relecture — la table `# qa-category:` ci-dessous rattache chaque cible de
# `qa` à une catégorie, et tests/Core/Quality/QualityGateParityTest.php refuse toute
# orpheline des deux côtés.
#
# Chaque outil est une dépendance require-dev, lancée depuis vendor/ par le PHP du
# projet — une version épinglée de chacun, résolue par composer.lock, identique en local
# et en CI. Rien au-delà de Composer et de ce PHP : pas de daemon, aucune installation
# globale.
#
# qa-category: Static analysis = stan
# qa-category: Code style = cs-check
# qa-category: Layer contract = deptrac
# qa-category: Tests = test
# qa-category: Linters and audits = lint audit
# qa-category: Accessibility = a11y

# Sous Windows, GNU Make appelle cmd.exe, qui ne sait exécuter ni `vendor/bin/…` ni
# `grep`. Le socle se développe sous Laragon et s'intègre sous Ubuntu : les deux façades
# doivent lancer les mêmes commandes, donc on impose un shell POSIX. Git Bash est livré
# avec Git, il est donc déjà là — et `php vendor/bin/<outil>` reste de toute façon la
# forme qui fonctionne partout.
ifeq ($(OS),Windows_NT)
SHELL := bash.exe
endif

.DEFAULT_GOAL := help
.PHONY: help qa test stan stan-baseline cs cs-check deptrac audit lint a11y container-cache

# Le `0-9` de la classe n'est pas decoratif : `a11y` porte un chiffre, et sans lui la
# cible existe, s'execute et n'apparait dans aucune liste.
help: ## Liste les cibles disponibles
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

qa: cs-check stan deptrac lint audit test a11y ## Exécute la porte complète, exactement comme la CI

# Les memes etapes que le job « Tests » de la CI, dans le meme ordre : sans le garde-fou,
# sans les migrations et sans le theme compile, la facade locale verifierait moins que la
# facade en ligne — exactement le desalignement que cette porte existe pour empecher.
#
# `tailwind:build` n'est pas une commodite. En environnement de test, le compilateur du
# bundle s'efface quand la feuille compilee manque (`strict_mode` vaut false en test) :
# `@import 'tailwindcss'` retombe alors sur AssetMapper, qui ne connait pas cet asset et
# echoue en `missing_import_mode: strict`. Sur un clone frais — un runner de CI, par
# exemple — chaque test fonctionnel qui rend une page tombe. La feuille se construit donc
# avant la suite, comme le schema se monte avant elle.
#
# Sans `--env=test`, contrairement aux deux lignes au-dessus : la sortie ne depend pas de
# l'environnement, elle ne depend que d'`input_css`. C'est le meme fichier que produit le
# deploiement.
test: ## Exécute la suite de tests, comme le job « Tests » de la CI
	php bin/console --env=test debug:config dama_doctrine_test > /dev/null
	php bin/console --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration
	php bin/console tailwind:build
	php vendor/bin/phpunit

# La sixieme categorie. Elle a sa propre testsuite (phpunit.dist.xml) plutot qu'un test
# perdu dans la suite : « Accessibility » rouge dit quoi corriger, « Tests » rouge ne le
# dit pas. `defaultTestSuite` exclut cette suite de la cible `test`, donc la porte ne la
# joue qu'une fois.
#
# `tailwind:build` pour la meme raison que dans `test` : le plancher rend de vraies pages,
# et sans la feuille compilee chaque rendu tombe sur `missing_import_mode: strict`.
#
# Les deux dernieres lignes sont **la seule etape Node de tout le depot**. Quatre lignes
# de la matrice d'edge cases de la story 1.4 decrivent du comportement JavaScript — le
# focus replace apres une navigation Turbo, la recopie d'un message dans la region
# d'annonces — et aucun test PHP ne peut les prouver. Elles appartiennent a
# « Accessibility » et non a « Tests » : c'est du comportement d'accessibilite, et un
# echec doit dire « le plancher est casse ».
#
# La chaine d'assets, elle, reste sans Node : tout le necessaire vit sous `tests/js/`,
# rien a la racine, et `AssetPipelineTest` tient les deux bouts.
#
# `install` en local et `ci` en CI : `install` est quasi instantane quand rien n'a bouge,
# la ou `ci` supprime et reinstalle `node_modules` a chaque appel.
#
# Mais il n'est lance que quand il a quelque chose a faire. Le temoin est
# `node_modules/.package-lock.json`, l'arbre reellement installe qu'npm y ecrit : absent,
# ou plus vieux que le lockfile, l'installation a du retard. Sans cette condition, `make qa`
# exigeait le reseau a chaque execution, alors que tout le reste de la porte tourne hors
# ligne — un poste sans connexion ne pouvait plus lancer sa propre porte.
#
# Et si `node` manque, on le dit. « npm: not found » n'apprend rien a qui decouvre le
# depot ; le README explique le plancher de version, la seule etape Node et pourquoi elle
# existe.
a11y: ## Vérifie le plancher d'accessibilité sur les pages rendues, les sources et les deux contrôleurs Stimulus
	php bin/console tailwind:build
	php vendor/bin/phpunit --testsuite Accessibility
	@command -v node > /dev/null 2>&1 || { \
	  echo "Node est absent : la moitié JavaScript de « Accessibility » ne peut pas s'exécuter."; \
	  echo "Installez Node 22.15 ou plus récent (plancher déclaré dans tests/js/package.json), puis relancez « make a11y »."; \
	  exit 1; \
	}
	@if [ ! -f tests/js/node_modules/.package-lock.json ] || [ tests/js/package-lock.json -nt tests/js/node_modules/.package-lock.json ]; then \
	  npm --prefix tests/js install --no-audit --no-fund; \
	fi
	node --test "tests/js/**/*.test.js"

container-cache: ## Compile le conteneur pour que PHPStan puisse lire les ids de service
	php bin/console cache:warmup --env=dev

stan: container-cache ## Analyse statique au niveau max
	php vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G

stan-baseline: container-cache ## Gèle les erreurs existantes — le socle n'en a pas, et n'en veut pas
	php vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G \
	  --generate-baseline=phpstan-baseline.neon
	@echo "Le socle naît avec sa porte : il n'a aucune dette à geler."
	@echo "Cette cible existe pour un dérivé qui adopterait le standard sur du code déjà écrit."

cs: ## Corrige le style de code
	php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

cs-check: ## Vérifie le style de code sans rien changer
	php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff

deptrac: ## Vérifie le contrat de couches des deux racines
	php vendor/bin/deptrac analyse --config-file=deptrac.yaml --report-uncovered

# composer audit sans option, et pas local-php-security-checker : celui-là est archivé
# et son propre dépôt renvoie ici.
#
# `importmap:audit` interroge la base d'avis de GitHub pour les versions épinglées dans
# `importmap.php`. Il n'y a pas de `npm audit` sur cette stack : sans cette ligne, rien
# ne signalerait jamais qu'un paquet JavaScript du socle a une faille publiée. Elle sort
# en 1 sur un avis connu, et c'est ce qu'on lui demande.
#
# Ces lignes sont au-dessus de la règle, pas dedans : make echo les lignes du corps d'une
# recette avant de les passer au shell, si bien qu'un commentaire indente s'affiche comme
# une commande qui aurait tourne.
audit: ## Vulnérabilités connues, en PHP et en JavaScript
	composer audit
	php bin/console importmap:audit

# `composer install` ne fait qu'avertir quand le lock a derive du json : sans
# `composer validate --strict`, « meme version en local et en CI » n'est verifie par rien.
lint: ## Lint le conteneur, les templates, le YAML, le mapping Doctrine et composer.json
	composer validate --strict
	php bin/console lint:container
	php bin/console lint:twig templates/
	php bin/console lint:yaml config/ .github/
	php bin/console doctrine:schema:validate --skip-sync
