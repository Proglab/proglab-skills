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

# Sous Windows, GNU Make appelle cmd.exe, qui ne sait exécuter ni `vendor/bin/…` ni
# `grep`. Le socle se développe sous Laragon et s'intègre sous Ubuntu : les deux façades
# doivent lancer les mêmes commandes, donc on impose un shell POSIX. Git Bash est livré
# avec Git, il est donc déjà là — et `php vendor/bin/<outil>` reste de toute façon la
# forme qui fonctionne partout.
ifeq ($(OS),Windows_NT)
SHELL := bash.exe
endif

.DEFAULT_GOAL := help
.PHONY: help qa test stan stan-baseline cs cs-check deptrac audit lint container-cache

help: ## Liste les cibles disponibles
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

qa: cs-check stan deptrac lint audit test ## Exécute la porte complète, exactement comme la CI

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
