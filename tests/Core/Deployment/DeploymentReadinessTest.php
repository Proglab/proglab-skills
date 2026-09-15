<?php

declare(strict_types=1);

namespace App\Tests\Core\Deployment;

use App\Core\Service\DeploymentReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;

/**
 * L'hôte que les emails écriront dans leurs liens, vérifié avant qu'un client ne le
 * découvre.
 *
 * **Pourquoi ce garde-fou existe.** `DEFAULT_URI` vaut `http://localhost` dans le `.env`
 * committé — le défaut de la recette Symfony. Un email est rendu par le worker, où il n'y
 * a aucune requête : `url()` n'a pas d'hôte à deviner et lit ce contexte. Un dérivé
 * déployé sans surcharger la variable envoie donc des emails **parfaitement valides** dont
 * le lien ne mène nulle part. Rien ne casse, rien ne se journalise, et personne ne le
 * remarque avant le premier utilisateur qui clique.
 *
 * C'est ce qui distingue cette variable de ses voisines : `MAILER_SENDER=no-reply@localhost`
 * échoue bruyamment — un serveur client refuse de relayer ce domaine —, et `.env` le dit
 * en toutes lettres. `DEFAULT_URI`, lui, n'échoue jamais.
 *
 * **Le contexte, pas la variable.** Le service lit `RequestContext`, c'est-à-dire ce que
 * le routeur utilisera réellement, et non `%env(DEFAULT_URI)%`. Une `routing.yaml`
 * débranchée ou un `default_uri` écrit en dur se ferait donc attraper aussi, là où lire la
 * variable d'environnement ne prouverait que l'existence de la variable.
 *
 * Ce test est un `TestCase` et non un `KernelTestCase` : la règle ne dépend d'aucun
 * conteneur, et la fabriquer ici permet de la vérifier sur des hôtes qu'aucun
 * environnement du dépôt ne porte.
 */
final class DeploymentReadinessTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableHosts(): iterable
    {
        yield 'le défaut de la recette Symfony' => ['localhost'];
        yield 'la boucle locale en clair' => ['127.0.0.1'];
        yield 'la boucle locale en IPv6' => ['::1'];
        yield 'l\'adresse à tout écouter, qui n\'est l\'adresse de personne' => ['0.0.0.0'];
        yield 'un sous-domaine de localhost, que les navigateurs résolvent en local' => ['app.localhost'];
    }

    #[Test]
    #[DataProvider('unreachableHosts')]
    public function an_unreachable_host_is_refused(string $host): void
    {
        $problems = self::readiness($host)->problems();

        self::assertCount(1, $problems, \sprintf('L\'hôte « %s » doit être refusé : aucun destinataire d\'email ne peut l\'atteindre.', $host));
        self::assertStringContainsString('DEFAULT_URI', $problems[0], 'Le problème doit nommer la variable à corriger, sans quoi il n\'est pas actionnable.');
        self::assertStringContainsString($host, $problems[0], 'Le problème doit citer l\'hôte trouvé.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reachableHosts(): iterable
    {
        yield 'un domaine de client' => ['erp.menuiserie-dubois.be'];
        yield 'l\'hôte virtuel d\'un poste de développement' => ['proglab-skills.test'];
        yield 'un nom qui contient « localhost » sans en être un' => ['localhost.example.com'];
    }

    #[Test]
    #[DataProvider('reachableHosts')]
    public function a_reachable_host_passes(string $host): void
    {
        self::assertSame([], self::readiness($host)->problems(), \sprintf('L\'hôte « %s » est joignable : le refuser bloquerait un déploiement légitime.', $host));
    }

    /**
     * La casse ne sauve personne — et ce n'est pas ce service qui le garantit.
     *
     * `RequestContext::setHost()` abaisse la casse lui-même, donc le rapport cite
     * « localhost » là où la configuration disait « LocalHost ». Le service la rabaisse
     * quand même avant de comparer : le contrat de `getHost()` appartient au composant, pas
     * à nous, et une comparaison sensible à la casse se casserait en silence le jour où il
     * change. Cette ligne dit laquelle des deux normalisations on observe.
     */
    #[Test]
    public function the_case_of_the_host_does_not_save_it(): void
    {
        $problems = self::readiness('LocalHost')->problems();

        self::assertCount(1, $problems);
        self::assertStringContainsString('localhost', $problems[0], 'Le rapport cite l\'hôte tel que le contexte le rend, donc en minuscules.');
    }

    /**
     * Un hôte vide n'est pas un hôte joignable, et c'est ce que rend `RequestContext`
     * quand `default_uri` est absent.
     */
    #[Test]
    public function an_empty_host_is_refused(): void
    {
        self::assertCount(1, self::readiness('')->problems());
    }

    private static function readiness(string $host): DeploymentReadiness
    {
        $context = new RequestContext();
        $context->setHost($host);

        return new DeploymentReadiness($context);
    }
}
