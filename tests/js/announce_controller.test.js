/*
 * `assets/controllers/announce_controller.js` — la moitié « lecteur d'écran » des deux
 * lignes de la matrice d'edge cases qui décrivent un message.
 *
 * « Rendu 422 avec erreurs » : `announce` recopie le texte du bloc `role="alert"` dans
 * `#annonces` en `assertive`. « Flash après redirection 303 » : il recopie le texte du
 * Flash en `polite`. Les deux se prouvent en regardant la région, pas le message : c'est
 * la région qui annonce, et une région n'annonce que ses **mutations**.
 *
 * Ordre d'écriture. Contrôleur et tests sont nés dans le même changement : écrire le
 * contrôleur d'abord était un **choix**, pas une contrainte, et le rouge d'abord restait
 * possible. Le repli de la règle 1 a donc été appliqué à la place — chaque assertion
 * vérifiée par mutation, en cassant le contrôleur et en observant l'échec. Voir le rapport
 * de la story.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { flush, mount, wait } from './support/harness.js';

const CONTROLLERS = { announce: 'announce_controller.js' };

/*
 * `DELAY_MS` du contrôleur. Il n'est pas exporté — ce n'est pas une API —, donc il est
 * recopié ici avec une marge. Les deux assertions qui l'encadrent sont ce qui rend la
 * valeur vérifiable : la région est vide juste après `connect()`, remplie après le
 * délai. Ramener le délai à zéro, ou écrire sans différer, fait échouer la première.
 */
const DELAY_MS = 100;

/** La région d'annonces du gabarit, à la lettre de `templates/base.html.twig`. */
const REGION = '<div id="annonces" aria-live="polite" class="sr-only" data-turbo-permanent></div>';

function body(message) {
    return `<main id="contenu">${message}</main>${REGION}`;
}

function region(harness) {
    return harness.document.getElementById('annonces');
}

describe('announce', () => {
    it('recopie une erreur en assertive — le rendu 422', async (t) => {
        const harness = await mount(
            body('<div role="alert" data-controller="announce">Le formulaire comporte des erreurs.</div>'),
            CONTROLLERS,
        );
        t.after(() => harness.close());

        assert.equal(
            region(harness).getAttribute('aria-live'),
            'assertive',
            '« assertive » interrompt la lecture en cours : c\'est ce qu\'une erreur mérite, et rien d\'autre.',
        );

        await wait(DELAY_MS * 2);

        assert.equal(region(harness).textContent, 'Le formulaire comporte des erreurs.');
        assert.deepEqual(harness.errors, []);
    });

    it('recopie un Flash en polite — la redirection 303', async (t) => {
        const harness = await mount(
            body('<div role="status" data-flash data-controller="announce">Modifications enregistrées.</div>'),
            CONTROLLERS,
        );
        t.after(() => harness.close());

        assert.equal(region(harness).getAttribute('aria-live'), 'polite');

        await wait(DELAY_MS * 2);

        assert.equal(region(harness).textContent, 'Modifications enregistrées.');
        assert.deepEqual(harness.errors, []);
    });

    it('déduit polite d\'un message sans rôle', async (t) => {
        const harness = await mount(body('<p data-controller="announce">Trois résultats.</p>'), CONTROLLERS);
        t.after(() => harness.close());

        assert.equal(
            region(harness).getAttribute('aria-live'),
            'polite',
            'Seul `role="alert"` vaut « assertive » : tout le reste est poli par défaut.',
        );
    });

    it('laisse la valeur explicite l\'emporter sur le rôle', async (t) => {
        const harness = await mount(
            body('<div role="alert" data-controller="announce" data-announce-priority-value="polite">Une alerte discrète.</div>'),
            CONTROLLERS,
        );
        t.after(() => harness.close());

        assert.equal(
            region(harness).getAttribute('aria-live'),
            'polite',
            '`priorityValue` est là pour qu\'un appelant tranche lui-même ; sinon la valeur ne sert à rien.',
        );
    });

    it('diffère l\'écriture d\'un instant', async (t) => {
        const harness = await mount(body('<div role="status" data-controller="announce">Bonjour.</div>'), CONTROLLERS);
        t.after(() => harness.close());

        assert.equal(
            region(harness).textContent,
            '',
            'Écrire dans la région au même instant que l\'insertion du message revient souvent à écrire avant que le lecteur d\'écran ne surveille la région.',
        );

        await wait(DELAY_MS * 2);

        assert.equal(region(harness).textContent, 'Bonjour.');
    });

    it('vide la région avant d\'écrire, pour que deux messages identiques restent deux annonces', async (t) => {
        const message = 'Modifications enregistrées.';
        const flash = `<div role="status" data-flash data-controller="announce">${message}</div>`;

        const harness = await mount(body(flash), CONTROLLERS);
        t.after(() => harness.close());

        await wait(DELAY_MS * 2);
        assert.equal(region(harness).textContent, message);

        // Le journal des états successifs de la région, du point de vue d'un lecteur
        // d'écran : c'est cette suite de valeurs qu'il annonce, pas le message lui-même.
        const seen = [];
        const observer = new harness.dom.window.MutationObserver(() => {
            seen.push(region(harness).textContent);
        });
        observer.observe(region(harness), { childList: true, characterData: true, subtree: true });

        // La même page rendue une seconde fois avec le même Flash — l'utilisateur a
        // enregistré deux fois de suite.
        await harness.navigate(body(flash));

        assert.equal(
            region(harness).textContent,
            '',
            'Sans le vidage, la région porterait encore le message identique de l\'annonce précédente et n\'aurait rien de nouveau à annoncer.',
        );

        await wait(DELAY_MS * 2);
        observer.disconnect();

        assert.equal(region(harness).textContent, message);
        assert.deepEqual(
            seen,
            ['', message],
            'La région doit passer par le vide : c\'est la seule façon pour que le second message produise une mutation perceptible.',
        );
        assert.deepEqual(harness.errors, []);
    });

    /*
     * Deux messages sur une même page — le bloc d'erreur d'un rendu 422 et un Flash. La
     * story 1.6 livre les deux, et rien n'interdit qu'ils coexistent.
     */
    it('compose deux messages au lieu de laisser le dernier écraser le premier', async (t) => {
        const harness = await mount(
            body(
                '<div role="alert" data-controller="announce">Le formulaire comporte des erreurs.</div>'
                + '<div role="status" data-flash data-controller="announce">Brouillon enregistré.</div>',
            ),
            CONTROLLERS,
        );
        t.after(() => harness.close());

        assert.equal(
            region(harness).getAttribute('aria-live'),
            'assertive',
            'Dès qu\'un des messages présents mérite « assertive », il l\'emporte : une erreur ne doit pas attendre la fin d\'un Flash.',
        );

        await wait(DELAY_MS * 2);

        const announced = region(harness).textContent;

        assert.ok(
            announced.includes('Le formulaire comporte des erreurs.'),
            'Le premier message a été écrasé par le second : la région n\'annonce qu\'une moitié de ce que la page dit.',
        );
        assert.ok(announced.includes('Brouillon enregistré.'));
        assert.deepEqual(harness.errors, []);
    });

    /*
     * La priorité est un état, pas une bascule. La région porte `data-turbo-permanent` :
     * sans retour à `polite`, une seule erreur laisse toute la session en `assertive`, où
     * chaque annonce ordinaire interrompt la lecture en cours.
     */
    it('rend la région à polite quand plus aucun message n\'est connecté', async (t) => {
        const harness = await mount(
            body('<div role="alert" data-controller="announce">Le formulaire comporte des erreurs.</div>'),
            CONTROLLERS,
        );
        t.after(() => harness.close());

        assert.equal(region(harness).getAttribute('aria-live'), 'assertive');

        // La page suivante n'a aucun message : la région survit à la navigation, sa
        // priorité ne doit pas lui survivre.
        await harness.navigate(body('<h1>Tableau de bord</h1>'));

        assert.equal(
            region(harness).getAttribute('aria-live'),
            'polite',
            'La région est restée « assertive » : chaque annonce de la suite de la session interromprait la lecture en cours.',
        );

        await wait(DELAY_MS * 2);

        assert.equal(region(harness).textContent, '', 'Le message de la page précédente ne doit pas non plus survivre.');
    });

    it('n\'écrit rien pour un message vide', async (t) => {
        const harness = await mount(body('<div role="alert" data-controller="announce">   </div>'), CONTROLLERS);
        t.after(() => harness.close());

        await wait(DELAY_MS * 2);

        assert.equal(region(harness).textContent, '');
        assert.equal(
            region(harness).getAttribute('aria-live'),
            'polite',
            'Un message vide ne doit même pas faire basculer la priorité de la région.',
        );
    });

    it('ne lève rien quand la région est absente', async (t) => {
        const harness = await mount('<main id="contenu"><div role="alert" data-controller="announce">Perdu.</div></main>', CONTROLLERS);
        t.after(() => harness.close());

        await wait(DELAY_MS * 2);

        assert.deepEqual(
            harness.errors,
            [],
            'Stimulus avale les exceptions de `connect()` : sans cette assertion, un contrôleur qui explose resterait invisible.',
        );
    });

    it('annule son écriture quand le message disparaît avant le délai', async (t) => {
        const harness = await mount(body('<div role="status" data-controller="announce">Éphémère.</div>'), CONTROLLERS);
        t.after(() => harness.close());

        harness.document.querySelector('[data-controller="announce"]').remove();
        await flush();

        await wait(DELAY_MS * 2);

        assert.equal(
            region(harness).textContent,
            '',
            'Un message retiré avant l\'échéance ne doit plus être annoncé : c\'est ce que `disconnect()` garantit.',
        );
    });
});
