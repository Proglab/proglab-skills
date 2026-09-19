<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Exception;

use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * L'exception métier d'un module, dans la forme que le guide de dérivation prescrit :
 * un constructeur nommé, aucun constructeur autowirable, aucune dépendance, et **les deux
 * attributs** — son statut HTTP et son niveau de log.
 *
 * Elle donne à `ModuleWiringTest` une classe réelle dans le dossier `Exception/` d'un
 * module : une exception est un objet de données qu'un appelant construit avec ses
 * valeurs, jamais un service, et `config/services.yaml` exclut le dossier des deux côtés
 * de la frontière. `App\Core\Exception\InitializationFailed` est le même cas côté socle —
 * il écrit, lui, pourquoi il ne porte **pas** ces attributs : ses échecs ne franchissent
 * jamais HTTP.
 *
 * **Le statut est un littéral, et `Response::HTTP_NOT_FOUND` est interdit ici.** Le
 * ruleset `Exception` de `deptrac.yaml` n'autorise que `Exception` et les racines :
 * référencer `Symfony\Component\HttpFoundation\Response` pour une constante fait échouer
 * la frontière sur « Exception must not depend on Http ». C'est la constante qui est
 * refusée, pas le statut ; écrivez le nombre.
 *
 * Le dossier `Exception/` ne voit pas non plus les enums : pas de statut ni de motif
 * qualifié par un `enum` du module, même pour dire lequel des widgets manque.
 */
#[WithHttpStatus(404)]
#[WithLogLevel(LogLevel::INFO)]
final class DemoWidgetNotFound extends RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('Fixture : aucun widget de démonstration ne porte l\'identifiant %d.', $id));
    }
}
