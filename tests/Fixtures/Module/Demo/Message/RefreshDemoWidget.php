<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Message;

/**
 * Le message d'un module : des scalaires, et rien d'autre.
 *
 * Elle n'existe que pour donner à `ModuleWiringTest` une classe réelle dans le dossier
 * `Message/` d'un module. Un message est construit par son appelant avec ses valeurs ;
 * un conteneur qui prétend savoir le fabriquer ne peut qu'échouer à l'autowiring de ses
 * scalaires — c'est ce qui a mis `Message/` dans la liste d'exclusion du socle à la
 * story 1.8, et dans celle des modules à la 1.15.
 *
 * **Aucun `#[AsMessage]`, et c'est la seule chose à ne pas recopier d'ici.** Ce message
 * n'est jamais dispatché : il n'existe que pour traverser le glob, donc le router
 * n'aurait rien à router. Un message réel, lui, porte `#[AsMessage('async')]` comme
 * `App\Core\Message\SendEmail` — le routage vit sur la classe, le bloc `routing:` de
 * `config/packages/messenger.yaml` étant réservé aux messages tiers. Sans l'attribut, le
 * handler s'exécute **en synchrone dans la requête**, c'est-à-dire exactement ce que la
 * story 1.8 a sorti de la requête, et rien ne le signale.
 *
 * Son handler, lui, reste un service (`RefreshDemoWidgetHandler`).
 */
final readonly class RefreshDemoWidget
{
    public function __construct(public int $widgetId)
    {
    }
}
