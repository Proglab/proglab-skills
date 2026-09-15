<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Service\DeploymentReadiness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RequestContext;

/**
 * « Ce dérivé est-il configuré pour le serveur où il tourne ? », à poser au déploiement.
 *
 * **La première commande du dépôt.** Elle est donc le précédent que les suivantes
 * copieront : forme invocable de Symfony 7.4 — `#[AsCommand]` sur la classe, `__invoke()`,
 * `SymfonyStyle` injecté par type —, aucune règle métier à l'intérieur, et **un seul appel
 * de service**. Ce qu'elle fait en propre tient en une phrase : traduire une liste de
 * problèmes en code de sortie.
 *
 * **Le code de sortie est l'interface.** C'est la seule chose qu'un script de déploiement
 * regarde ; le texte est pour l'humain qui relit le log une semaine plus tard. D'où un
 * rapport qui nomme l'hôte validé même quand tout va bien — une commande qui ne dit rien
 * ne prouve rien — et les problèmes sur stderr, pour que l'appelant puisse les séparer.
 *
 * **Elle n'est branchée sur rien.** Aucun écouteur du noyau, aucun hook : la vérification
 * de type production du skill `symfony-proglab-local-dev` fait tourner `APP_ENV=prod` sur
 * localhost en toute légitimité, et un garde-fou automatique sur « prod + localhost »
 * casserait précisément ce contrôle. C'est au déploiement d'appeler cette commande — la
 * story 3.1 lui donnera sa place dans la séquence.
 */
#[AsCommand(
    name: 'app:deployment:check',
    description: 'Vérifie la configuration qui échoue en silence sur un dérivé déployé',
)]
final readonly class DeploymentCheckCommand
{
    public function __construct(
        private DeploymentReadiness $readiness,
        private RequestContext $context,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $problems = $this->readiness->problems();

        if ([] === $problems) {
            $io->success(\sprintf('Les liens envoyés par ce dérivé pointeront vers « %s ».', $this->context->getHost()));

            return Command::SUCCESS;
        }

        $error = $io->getErrorStyle();

        foreach ($problems as $problem) {
            $error->error($problem);
        }

        return Command::FAILURE;
    }
}
