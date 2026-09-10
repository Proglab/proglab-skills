<?php

declare(strict_types=1);

/*
 * L'entity manager, pour `phpstan-doctrine` : il en a besoin pour résoudre les types de
 * retour de `find()`, de `getRepository()` et du DQL, et son extension de propriétés
 * s'en sert pour savoir qu'une colonne mappée est bien écrite — par l'ORM, jamais par le
 * code, ce que PHPStan ne peut pas voir seul.
 *
 * Le kernel est booté en `test`, pas en `dev` : c'est le seul environnement où le mapping
 * `FixtureModule` de `config/packages/doctrine.yaml` existe, donc le seul où les entités
 * des modules de démonstration de `tests/Fixtures/` sont connues de l'ORM.
 */

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

// bootEnv choisit les fichiers d'environnement d'apres APP_ENV : sans cette ligne il
// chargerait .env.dev et .env.local, alors que le kernel ci-dessous boote en `test`.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

new Dotenv()->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('test', true);
$kernel->boot();

$manager = $kernel->getContainer()->get('doctrine')->getManager();

if (!$manager instanceof EntityManagerInterface) {
    throw new RuntimeException('Le manager Doctrine par défaut n\'est pas un EntityManagerInterface.');
}

return $manager;
