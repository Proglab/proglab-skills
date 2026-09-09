# Porte de qualité. Copier à la racine du projet et la committer.
#
# Chaque outil est une dépendance require-dev, exécutée via vendor/bin/ — une
# version épinglée de chacun, résolue par composer.lock, identique en local et
# en CI.
#
# Ne nécessite rien d'autre que Composer et le PHP du projet lui-même.

.DEFAULT_GOAL := help
.PHONY: help qa test stan stan-baseline cs cs-check deptrac audit lint container-cache

help: ## Liste les cibles disponibles
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

qa: cs-check stan deptrac lint audit test ## Exécute la porte complète, exactement comme la CI

test: ## Exécute la suite de tests
	vendor/bin/phpunit

container-cache: ## Compile le conteneur pour que PHPStan puisse lire les ids de service
	php bin/console cache:warmup --env=dev

stan: container-cache ## Analyse statique au niveau max
	vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G

stan-baseline: container-cache ## Gèle les erreurs existantes (adoption du standard sur une base de code existante)
	vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G \
	  --generate-baseline=phpstan-baseline.neon
	@echo "Commit phpstan-baseline.neon and uncomment the includes line in phpstan.dist.neon."
	@echo "The baseline may shrink, never grow."

cs: ## Corrige le style de code
	vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

cs-check: ## Vérifie le style de code sans rien changer
	vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff

deptrac: ## Vérifie le contrat de couches (ignoré quand deptrac.yaml est absent — à la demande)
	@test -f deptrac.yaml || { echo "No deptrac.yaml — layer contract not enforced on this project."; exit 0; }
	vendor/bin/deptrac analyse --config-file=deptrac.yaml --report-uncovered

audit: ## Vulnérabilités connues, PHP et JavaScript
	# composer audit, pas local-php-security-checker : celui-là est archivé et
	# son propre dépôt renvoie ici.
	composer audit
	php bin/console importmap:audit

lint: ## Lint le conteneur, les templates, le YAML et le mapping Doctrine
	php bin/console lint:container
	php bin/console lint:twig templates/
	php bin/console lint:yaml config/
	php bin/console doctrine:schema:validate --skip-sync
