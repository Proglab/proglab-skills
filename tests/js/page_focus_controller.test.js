/*
 * `assets/controllers/page_focus_controller.js` — les lignes de la matrice d'edge cases
 * de la story 1.4 qu'aucun test PHP ne peut prouver.
 *
 * Quatre des huit lignes décrivent du comportement JavaScript : « Premier chargement de
 * session », « Navigation Turbo, page ordinaire », « Rendu 422 avec erreurs » et « Flash
 * après redirection 303 ». Les trois dernières supposent qu'une navigation Turbo a
 * réellement remplacé le `<body>` et rejoué `connect()` — c'est ce que `mount().navigate()`
 * reproduit.
 *
 * Ordre d'écriture. Contrôleurs et tests sont nés dans le même changement : écrire les
 * contrôleurs d'abord était un **choix**, pas une contrainte, et le rouge d'abord restait
 * possible. Le repli de la règle 1 a donc été appliqué à la place — chaque assertion
 * vérifiée par mutation, en cassant le contrôleur et en observant l'échec, treize
 * régressions injectées une à une. Voir le rapport de la story.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { mount } from './support/harness.js';

const CONTROLLERS = { 'page-focus': 'page_focus_controller.js' };

/** Le `<main>` du gabarit, tel que `templates/base.html.twig` le rend. */
function page(inner) {
    return `<main id="contenu" tabindex="-1" data-controller="page-focus">${inner}</main>`;
}

/*
 * Les quatre cibles, écrites dans le DOM **à l'envers** de leur priorité. Un contrôleur
 * qui prendrait « la première du document » plutôt que « la première de FOCUS_ORDER »
 * choisirait le `h1` à chaque fois, et les quatre cas ci-dessous le diraient.
 */
const H1 = '<h1>Accueil</h1>';
const FLASH = '<div data-flash role="status">Enregistré.</div>';
const INVALID = '<input id="nom" aria-invalid="true">';
const ALERT = '<div role="alert">Le formulaire comporte des erreurs.</div>';

describe('page-focus', () => {
    it('ne déplace rien au premier chargement de la session', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        assert.equal(
            harness.focused(),
            null,
            'Au tout premier chargement, le navigateur a déjà placé le focus en haut du document : le contrôleur ne doit rien bouger.',
        );
        assert.deepEqual(harness.errors, []);
    });

    it('pose le focus sur le h1 après une navigation Turbo ordinaire', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page('<h1>Tableau de bord</h1>'));

        const focused = harness.focused();

        assert.ok(focused, 'Après un remplacement de body par Turbo, le focus doit repartir du nouveau contexte.');
        assert.equal(focused.tagName, 'H1');
        assert.equal(focused.textContent, 'Tableau de bord');
        assert.deepEqual(harness.errors, []);
    });

    it('pose le focus sur le bloc role="alert" d\'un rendu 422', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page(H1 + INVALID + ALERT));

        const focused = harness.focused();

        assert.ok(focused);
        assert.equal(focused.getAttribute('role'), 'alert');
        assert.deepEqual(harness.errors, []);
    });

    it('pose le focus sur le Flash après une redirection 303', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page(H1 + FLASH));

        const focused = harness.focused();

        assert.ok(focused);
        assert.equal(focused.getAttribute('data-flash'), '');
        assert.equal(focused.textContent, 'Enregistré.');
        assert.deepEqual(harness.errors, []);
    });

    /*
     * L'ordre de FOCUS_ORDER lui-même, un cran à la fois : la cible la plus prioritaire
     * est retirée, et la suivante doit gagner. Inverser deux entrées du tableau fait
     * échouer au moins deux de ces quatre cas.
     */
    const ORDER = [
        { name: 'le bloc d\'erreur bat le champ invalide, le Flash et le titre', body: H1 + FLASH + INVALID + ALERT, expect: (el) => assert.equal(el.getAttribute('role'), 'alert') },
        { name: 'le champ invalide bat le Flash et le titre', body: H1 + FLASH + INVALID, expect: (el) => assert.equal(el.id, 'nom') },
        { name: 'le Flash bat le titre', body: H1 + FLASH, expect: (el) => assert.equal(el.getAttribute('data-flash'), '') },
        { name: 'le titre est le dernier recours', body: H1, expect: (el) => assert.equal(el.tagName, 'H1') },
    ];

    for (const { name, body, expect } of ORDER) {
        it(`respecte l'ordre des sélecteurs : ${name}`, async (t) => {
            const harness = await mount(page(H1), CONTROLLERS);
            t.after(() => harness.close());

            await harness.navigate(page(body));

            const focused = harness.focused();

            assert.ok(focused, 'Aucune cible n\'a pris le focus.');
            expect(focused);
        });
    }

    it('pose tabindex="-1" sur une cible qui n\'est pas focalisable', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page('<h1>Accueil</h1>'));

        const h1 = harness.document.querySelector('h1');

        assert.equal(
            h1.getAttribute('tabindex'),
            '-1',
            'Un `h1` n\'est pas focalisable par défaut : sans `tabindex="-1"`, `focus()` ne fait rien.',
        );
        assert.equal(harness.focused(), h1);
    });

    /*
     * Le piège que ce contrôleur pourrait tendre à la règle 4 qu'il sert : `tabindex="-1"`
     * **retire** de l'ordre de tabulation un élément qui y était. Sur le champ en erreur
     * d'un rendu 422 — un `<input>`, donc focalisable nativement — cela veut dire que
     * l'utilisateur au clavier ne peut plus revenir corriger sa saisie.
     */
    it('ne pose pas tabindex sur une cible nativement focalisable', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page(H1 + INVALID));

        const field = harness.document.getElementById('nom');

        assert.equal(harness.focused(), field, 'Le champ en erreur doit prendre le focus.');
        assert.equal(
            field.getAttribute('tabindex'),
            null,
            'Un `<input>` est déjà focalisable : lui poser `tabindex="-1"` le sortirait de l\'ordre de tabulation, au moment précis où l\'utilisateur doit y revenir.',
        );
    });

    /*
     * La prévisualisation de cache de Turbo : le `<body>` est remplacé **deux fois** par
     * visite, d'abord par la copie en cache — `data-turbo-preview` sur `<html>` — puis par
     * la réponse du serveur. Sans garde, le focus part d'abord sur le contenu périmé.
     */
    it('ne déplace rien pendant la prévisualisation de cache de Turbo', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page('<h1>Réel</h1>'), {
            preview: true,
            previewBodyHtml: page('<h1>Périmé</h1>'),
        });

        assert.equal(
            harness.scrolledInto.length,
            1,
            'Le contrôleur a agi sur le corps de prévisualisation : le focus serait parti sur le contenu de la page précédente avant de devoir bouger une seconde fois.',
        );
        assert.equal(harness.focused().textContent, 'Réel');
        assert.deepEqual(harness.errors, []);
    });

    /*
     * La visite de restauration — le bouton « précédent ». Turbo y remet la position de
     * défilement ; `scrollIntoView` la défait sous les yeux de l'utilisateur.
     */
    it('ne refait pas défiler la page sur une visite de restauration', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page(H1), { action: 'restore' });

        assert.deepEqual(
            harness.scrolledInto,
            [],
            'Sur un retour arrière, Turbo a déjà restauré le défilement : le contrôleur ne doit pas le défaire.',
        );
        assert.ok(harness.focused(), 'Le focus, lui, reste posé — il ne déplace rien.');
    });

    /*
     * La portée de `#firstTarget()` est le `<main>`, et c'est une contrainte pour la story
     * 1.6 : un Flash rendu hors de `<main>` n'est pas trouvé. Le contrôleur le documente ;
     * ce test le rend vérifiable, pour que le jour où quelqu'un élargit la recherche à tout
     * le document, ce soit une décision et non un effet de bord.
     */
    it('ne cherche ses cibles que dans le <main> qui le porte', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(`<header>${FLASH}</header>${page(H1)}`);

        assert.equal(
            harness.focused().tagName,
            'H1',
            'Le Flash est dans le `<header>`, hors de la portée du contrôleur : le focus retombe sur le `h1`. C\'est la contrainte que la story 1.6 doit respecter — Flash et bloc d\'erreur vivent dans `<main>`.',
        );
    });

    it('ne réécrit pas un tabindex déjà posé', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page('<h1 tabindex="0">Accueil</h1>'));

        assert.equal(
            harness.document.querySelector('h1').getAttribute('tabindex'),
            '0',
            'Le contrôleur ajoute `tabindex` quand il manque ; il ne décide pas à la place de l\'auteur du gabarit quand il est là.',
        );
    });

    it('ne déplace rien et ne lève rien quand aucune cible n\'existe', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page('<p>Une page sans titre — une faute que le plancher signale ailleurs.</p>'));

        assert.equal(harness.focused(), null);
        assert.deepEqual(
            harness.errors,
            [],
            'Stimulus avale les exceptions de `connect()` : sans cette assertion, un contrôleur qui explose resterait invisible.',
        );
    });

    it('amène la cible dans le champ de vision sans faire défiler au focus', async (t) => {
        const harness = await mount(page(H1), CONTROLLERS);
        t.after(() => harness.close());

        await harness.navigate(page(H1));

        assert.equal(harness.scrolledInto.length, 1);
        assert.equal(harness.scrolledInto[0].element.tagName, 'H1');
        assert.deepEqual(harness.scrolledInto[0].options, { block: 'nearest' });
    });
});
