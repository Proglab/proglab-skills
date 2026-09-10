/*
 * Le harnais des deux tests de contrôleur Stimulus du socle.
 *
 * Ce qu'il monte n'est pas une imitation de Stimulus : c'est **le** Stimulus du socle,
 * `assets/vendor/@hotwired/stimulus/stimulus.index.js`, celui qu'`importmap.php` épingle
 * et que le dépôt commite. Rien n'est installé depuis npm pour lui — un second
 * `@hotwired/stimulus` en dépendance de test dériverait un jour de celui qui est servi,
 * et les tests seraient alors verts contre une version que personne ne livre.
 *
 * C'est aussi pourquoi le sens de la dépendance ne s'inverse jamais : `tests/js/` lit
 * `assets/`, `assets/` ignore `tests/js/`. `AssetPipelineTest::the_node_exception_is_confined_to_the_test_directory`
 * tient cette flèche mécaniquement.
 */

import { registerHooks } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { JSDOM } from 'jsdom';

const here = path.dirname(fileURLToPath(import.meta.url));

export const PROJECT_DIR = path.resolve(here, '..', '..', '..');

const STIMULUS_URL = pathToFileURL(
    path.join(PROJECT_DIR, 'assets', 'vendor', '@hotwired', 'stimulus', 'stimulus.index.js'),
).href;

/*
 * `import { Controller } from '@hotwired/stimulus'` est un spécificateur nu : dans un
 * navigateur, c'est l'import map générée par AssetMapper qui le résout ; ici, c'est ce
 * crochet. Il pointe sur le fichier commité, donc les contrôleurs sont importés **sans
 * être modifiés** — pas de copie, pas de transpilation, pas de double de `Controller`.
 *
 * `registerHooks` est synchrone et s'applique au processus courant (Node 22.15+). Le
 * crochet doit être posé avant le premier `import()` d'un contrôleur : c'est le cas,
 * puisque ce module s'évalue entièrement avant le corps du fichier de test qui l'importe,
 * et que les contrôleurs ne sont chargés que par `import()` dynamique.
 */
registerHooks({
    resolve(specifier, context, nextResolve) {
        if ('@hotwired/stimulus' === specifier) {
            return { url: STIMULUS_URL, shortCircuit: true };
        }

        return nextResolve(specifier, context);
    },
});

/*
 * Les globales qu'un module écrit pour le navigateur suppose présentes. Stimulus lit
 * `document`, construit des `MutationObserver` et émet des `CustomEvent` ; les
 * contrôleurs du socle lisent `document` et `window`. Elles sont réinstallées à chaque
 * montage, sur le document de ce montage-là, pour qu'aucun test ne parle au DOM d'un
 * autre.
 */
const BROWSER_GLOBALS = [
    'window',
    'document',
    'navigator',
    'location',
    'Element',
    'HTMLElement',
    'HTMLInputElement',
    'Node',
    'NodeList',
    'DOMTokenList',
    'MutationObserver',
    'Event',
    'CustomEvent',
    'ErrorEvent',
    'KeyboardEvent',
    'MouseEvent',
    'getComputedStyle',
];

/**
 * Un tour de boucle d'événements complet : les rappels de `MutationObserver` de jsdom
 * partent en microtâche, donc `await` sur un `setTimeout(0)` de Node les a tous vus
 * passer. C'est ce qui laisse `connect()` s'exécuter avant qu'un test n'affirme quoi que
 * ce soit.
 */
export function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * Attendre un vrai délai, pour le seul cas où le comportement testé *est* un délai :
 * `announce` diffère son écriture de `DELAY_MS`.
 */
export function wait(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

let mountCount = 0;

/**
 * Monte une page et démarre Stimulus dessus.
 *
 * `controllers` associe un identifiant (`page-focus`) au nom de fichier du contrôleur
 * dans `assets/controllers/`. Chaque montage réimporte le module avec une chaîne de
 * cache-bust : `page_focus_controller.js` porte un drapeau de **portée module**
 * (`hasNavigated`), et sans cela le deuxième test du fichier hériterait de l'état du
 * premier — exactement la confusion que le contrôleur cherche à éviter entre un
 * rechargement complet et une navigation Turbo.
 */
export async function mount(bodyHtml, controllers) {
    const dom = new JSDOM(`<!DOCTYPE html><html lang="fr"><head><title>Test</title></head><body>${bodyHtml}</body></html>`);

    /*
     * `Object.defineProperty` et non une affectation : Node expose déjà `navigator` en
     * accesseur sans setter, et `globalThis.navigator = …` y lève un `TypeError`.
     */
    for (const name of BROWSER_GLOBALS) {
        Object.defineProperty(globalThis, name, {
            value: dom.window[name],
            configurable: true,
            writable: true,
        });
    }

    /*
     * jsdom n'implémente pas `scrollIntoView` — il n'a pas de moteur de mise en page,
     * donc rien à faire défiler. `page-focus` l'appelle après avoir posé le focus ;
     * sans ce bouchon, `connect()` lèverait un `TypeError` que Stimulus avalerait, et
     * chaque test de focus échouerait pour une raison qui n'a rien à voir avec le
     * contrôleur. Le bouchon enregistre l'appel, pour qu'un test puisse l'affirmer
     * plutôt que de simplement le tolérer.
     */
    const scrolledInto = [];
    dom.window.Element.prototype.scrollIntoView = function scrollIntoView(options) {
        scrolledInto.push({ element: this, options });
    };

    if ('loading' === dom.window.document.readyState) {
        await new Promise((resolve) => {
            dom.window.document.addEventListener('DOMContentLoaded', resolve, { once: true });
        });
    }

    const { Application } = await import(STIMULUS_URL);

    const application = new Application(dom.window.document.documentElement);

    /*
     * Stimulus attrape toute exception levée dans un `connect()` et la passe à
     * `handleError`, qui par défaut ne fait que la logger. Ici elle est **collectée** :
     * « ne lève aucune exception quand aucune cible n'existe » ne se prouve qu'en
     * regardant cette liste, sans quoi le test serait vert même sur un contrôleur qui
     * explose à chaque connexion.
     */
    const errors = [];
    application.handleError = (error, message, detail) => {
        errors.push({ error, message, detail });
    };

    ++mountCount;

    for (const [identifier, file] of Object.entries(controllers)) {
        const url = `${pathToFileURL(path.join(PROJECT_DIR, 'assets', 'controllers', file)).href}?mount=${mountCount}`;
        const module = await import(url);

        application.register(identifier, module.default);
    }

    application.start();

    await flush();

    const { document } = dom.window;

    return {
        dom,
        document,
        application,
        errors,
        scrolledInto,

        /**
         * Ce que Turbo Drive fait d'une navigation : le `<body>` est remplacé, et les
         * éléments `data-turbo-permanent` sont **reportés** au lieu d'être recréés — la
         * région `#annonces` du gabarit en est un, et c'est tout l'intérêt qu'elle y
         * gagne : une région live qui vient d'apparaître n'annonce rien.
         *
         * Le `<head>` n'est pas fusionné : aucun des deux contrôleurs ne le lit.
         *
         * Deux détails de Turbo sont modélisés par `options`, parce que les deux changent
         * ce qu'un contrôleur a le droit de faire :
         *
         * - `action` est celui de `turbo:visit`. `'restore'` est la visite du bouton
         *   « précédent », où Turbo remet aussi la position de défilement.
         * - `preview` reproduit le rendu de **prévisualisation** : Turbo affiche d'abord
         *   la copie en cache de la page, `data-turbo-preview` posé sur `<html>`, puis
         *   la réponse du serveur. Le `<body>` est donc remplacé deux fois par visite, et
         *   `connect()` rejoué deux fois.
         */
        async navigate(nextBodyHtml, options = {}) {
            const { action = 'advance', preview = false, previewBodyHtml = nextBodyHtml } = options;

            document.dispatchEvent(
                new dom.window.CustomEvent('turbo:visit', { detail: { action } }),
            );

            if (preview) {
                document.documentElement.setAttribute('data-turbo-preview', '');
                await this.render(previewBodyHtml);
                document.documentElement.removeAttribute('data-turbo-preview');
            }

            await this.render(nextBodyHtml);
        },

        /** Le remplacement de corps lui-même, sans l'enveloppe d'une visite. */
        async render(nextBodyHtml) {
            const permanent = [...document.body.querySelectorAll('[data-turbo-permanent][id]')];

            document.body.innerHTML = nextBodyHtml;

            /*
             * Turbo apparie sur l'`id` : l'élément que la nouvelle page a rendu est
             * **remplacé** par le nœud déjà en place, qui garde donc son identité, ses
             * observateurs et son contenu. Se contenter de rajouter l'ancien nœud
             * laisserait deux `#annonces` dans le document, et le contrôleur écrirait
             * dans celui que le lecteur d'écran ne surveille pas — le défaut exact que
             * `data-turbo-permanent` existe pour éviter.
             */
            for (const node of permanent) {
                document.getElementById(node.id)?.replaceWith(node);
            }

            await flush();
        },

        /** L'élément qui a le focus, ou `null` quand c'est le `<body>` — donc personne. */
        focused() {
            const active = document.activeElement;

            return null === active || active === document.body ? null : active;
        },

        close() {
            application.stop();
            dom.window.close();
        },
    };
}
