<?php

declare(strict_types=1);

namespace App\Core\Service;

use Symfony\Component\Routing\RequestContext;

/**
 * « Ce dérivé est-il configuré pour le serveur où il tourne ? ».
 *
 * **Une seule règle pour l'instant, et elle ferme une panne silencieuse.** `DEFAULT_URI`
 * vaut `http://localhost` dans le `.env` committé — le défaut de la recette Symfony, et le
 * bon défaut pour un socle qui ne connaît pas le domaine de son futur client. Un email est
 * rendu par le worker, où il n'y a aucune requête : `url()` n'a pas d'hôte à deviner et
 * lit `framework.router.default_uri`. Un dérivé déployé sans surcharger la variable envoie
 * donc des emails valides, bien formés, journalisés comme envoyés — et dont le lien ne
 * mène nulle part.
 *
 * C'est ce qui sépare cette variable de ses voisines. `MAILER_SENDER=no-reply@localhost`
 * échoue **bruyamment** : un serveur client refuse de relayer ce domaine, et `.env` dit que
 * ce défaut est inutilisable exprès. `DATABASE_URL` échoue à la première requête.
 * `DEFAULT_URI` n'échoue jamais — il produit une adresse que seul le destinataire découvre.
 *
 * **Le contexte du routeur, pas la variable d'environnement.** Ce service lit
 * `RequestContext`, c'est-à-dire ce que le routeur utilisera réellement pour composer une
 * URL absolue. Lire `%env(DEFAULT_URI)%` ne prouverait que l'existence de la variable ;
 * lire le contexte attrape aussi une `config/packages/routing.yaml` débranchée ou un
 * `default_uri` écrit en dur.
 *
 * **Ce service ne décide pas quand il tourne.** Il rend une liste de problèmes, et
 * `bin/console app:deployment:check` en fait un code de sortie. Il n'est branché sur aucun
 * événement du noyau, et c'est délibéré : la vérification de type production décrite par
 * le skill `symfony-proglab-local-dev` fait tourner `APP_ENV=prod` **sur localhost**, en
 * toute légitimité. Un garde-fou qui se déclencherait sur « prod + localhost » casserait
 * exactement ce contrôle. C'est au déploiement de poser la question, pas au noyau.
 */
final readonly class DeploymentReadiness
{
    /**
     * Les hôtes qu'aucun destinataire d'email ne peut atteindre.
     *
     * En minuscules : la comparaison abaisse la casse avant de chercher, un nom d'hôte
     * n'y étant pas sensible.
     */
    public const array UNREACHABLE_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * Le suffixe des noms que les navigateurs et les résolveurs renvoient vers la machine.
     *
     * `app.localhost` est joignable depuis le poste qui l'écrit, et de nulle part ailleurs
     * — donc inutilisable dans un email. Séparé de la liste ci-dessus parce que la règle
     * est un suffixe et non une égalité, et que `localhost.example.com` doit passer.
     */
    private const string LOCAL_SUFFIX = '.localhost';

    public function __construct(
        private RequestContext $context,
    ) {
    }

    /**
     * Les problèmes trouvés, un par phrase, vides quand tout va bien.
     *
     * Chaque phrase nomme la variable à corriger et la valeur trouvée : un rapport de
     * déploiement est lu par quelqu'un qui n'a pas le dépôt sous les yeux.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $host = $this->context->getHost();

        if (self::isUnreachable($host)) {
            $problems[] = \sprintf(
                'DEFAULT_URI pointe vers « %s », un hôte qu\'aucun destinataire d\'email ne peut atteindre. '
                .'Les liens envoyés par ce dérivé — réinitialisation de mot de passe comprise — seront inutilisables. '
                .'Surcharge DEFAULT_URI dans le .env.local du serveur, ou par « composer dump-env prod ».',
                '' === $host ? '(vide)' : $host,
            );
        }

        return $problems;
    }

    private static function isUnreachable(string $host): bool
    {
        $host = mb_strtolower($host);

        return '' === $host
            || \in_array($host, self::UNREACHABLE_HOSTS, true)
            || str_ends_with($host, self::LOCAL_SUFFIX);
    }
}
