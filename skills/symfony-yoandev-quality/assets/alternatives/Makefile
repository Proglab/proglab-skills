# Quality gate. Copy to the project root and commit it.
#
# Every tool runs from the jakzal/phpqa image: one pinned version of each,
# identical locally and in CI, extensions preinstalled. (Only php-cs-fixer has
# dependencies that can conflict with the application's; PHPStan and deptrac
# are self-contained phars.)
#
# Requires Docker. Nothing else.

# Pinned on purpose: the `alpine` tag moves, and a rebuilt image can fail a pipeline
# with no code change. Bump deliberately, in its own commit.
PHPQA_IMAGE ?= jakzal/phpqa:1.125.1-php8.4-alpine
# CI has no TTY. Override with `make stan PHPQA_TTY=` in a pipeline.
PHPQA_TTY   ?= -it
PHPQA        = docker run --init --rm $(PHPQA_TTY) \
                 -v "$(CURDIR):/project" \
                 -v "$(CURDIR)/var/phpqa:/tmp" \
                 -w /project $(PHPQA_IMAGE)

.DEFAULT_GOAL := help
.PHONY: help qa test stan stan-baseline cs cs-check deptrac audit lint container-cache update

help: ## List the available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

qa: cs-check stan deptrac lint audit test ## Run the full gate, exactly as CI does

test: ## Run the test suite
	vendor/bin/phpunit

container-cache: ## Compile the container so PHPStan can read service ids
	php bin/console cache:warmup --env=dev

stan: container-cache ## Static analysis at level max
	$(PHPQA) phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G

stan-baseline: container-cache ## Freeze existing errors (adopting the standard on an existing codebase)
	$(PHPQA) phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G \
	  --generate-baseline=phpstan-baseline.neon
	@echo "Commit phpstan-baseline.neon and uncomment the includes line in phpstan.dist.neon."
	@echo "The baseline may shrink, never grow."

cs: ## Fix code style
	$(PHPQA) php-cs-fixer fix --config=.php-cs-fixer.dist.php

cs-check: ## Verify code style without changing anything
	$(PHPQA) php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff

deptrac: ## Verify the layer contract (skipped when deptrac.yaml is absent — on demand)
	@test -f deptrac.yaml || { echo "No deptrac.yaml — layer contract not enforced on this project."; exit 0; }
	$(PHPQA) deptrac analyse --config-file=deptrac.yaml --report-uncovered

audit: ## Known vulnerabilities, PHP and JavaScript
	# composer audit, not local-php-security-checker: that one is archived and
	# its own repository points here. It is still in the phpqa image.
	composer audit
	php bin/console importmap:audit

lint: ## Lint the container, templates, YAML and the Doctrine mapping
	php bin/console lint:container
	php bin/console lint:twig templates/
	php bin/console lint:yaml config/
	php bin/console doctrine:schema:validate --skip-sync

update: ## Pull the latest phpqa image
	docker pull $(PHPQA_IMAGE)
