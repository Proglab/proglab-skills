import { Controller } from '@hotwired/stimulus';

/*
 * Replace le focus après chaque navigation Turbo — posé sur le `<main>` du gabarit.
 *
 * Sous Turbo Drive, une navigation remplace le `<body>` sans recharger la page : le focus
 * reste où il était, sur un lien qui n'existe plus, et un lecteur d'écran ne lit rien du
 * nouveau contexte. `connect()` d'un contrôleur Stimulus s'exécute à chaque insertion,
 * donc à chaque remplacement de body — c'est le seul crochet qui suive vraiment les
 * navigations Turbo.
 *
 * Amélioration progressive : sans JavaScript, la navigation est en pleine page et c'est
 * le navigateur qui place le focus correctement. Ce contrôleur ne rétablit un confort que
 * Turbo enlève ; il ne porte aucune règle.
 */

/*
 * L'ordre de UX-DR-6, du plus urgent au plus général. Le premier présent gagne.
 *
 * `[data-flash]` est le contrat que le composant Flash des stories suivantes doit porter :
 * un `role="status"` seul ne convient pas comme sélecteur, il désignerait aussi des
 * régions d'état qui ne sont pas des messages de navigation.
 */
const FOCUS_ORDER = [
    '[role="alert"]',
    '[aria-invalid="true"]',
    '[data-flash]',
    'h1',
];

/*
 * Les cibles sont cherchées dans `this.element`, c'est-à-dire dans le `<main>` du gabarit.
 *
 * **Contrainte pour la story 1.6, qui livre le premier Flash et le premier rendu 422 :**
 * le bloc d'erreur `role="alert"` et le Flash doivent être rendus **dans `<main>`**. Rendus
 * dans le `<header>` ou entre le `<header>` et le `<main>`, ils sortent de la portée de ce
 * contrôleur : le focus retomberait en silence sur le `h1`, et l'ordre de UX-DR-6 ne serait
 * plus respecté sans qu'aucun test ne bouge. La portée est volontaire — un contrôleur de
 * focus qui balaie tout le document déplacerait aussi le focus sur une alerte de coque qui
 * n'a rien à voir avec la navigation en cours.
 */

/*
 * Ce qui est déjà focalisable sans qu'on y touche. Poser `tabindex="-1"` sur l'un d'eux le
 * **sort de l'ordre de tabulation** : après un rendu 422, l'utilisateur ne pourrait plus
 * atteindre au clavier le champ que ce contrôleur vient de lui désigner.
 */
const NATIVELY_FOCUSABLE = 'a[href], area[href], button, input, select, textarea, summary, iframe, [contenteditable]';

/*
 * Portée module, pas instance : un rechargement complet réévalue ce fichier et remet le
 * drapeau à `false`, une navigation Turbo ne le fait pas. C'est exactement la distinction
 * cherchée — ignorer le tout premier chargement de la session, où le navigateur place
 * déjà le focus en haut du document, et n'agir qu'aux navigations suivantes.
 */
let hasNavigated = false;

/*
 * L'action de la visite Turbo en cours. `'restore'` est celle du bouton « précédent » :
 * Turbo y remet la page **et sa position de défilement**, et l'utilisateur s'attend à
 * retrouver l'écran où il l'avait laissé. Un `scrollIntoView` à ce moment-là défait cette
 * restauration sous ses yeux.
 *
 * L'écouteur est posé une fois, à l'évaluation du module — comme `hasNavigated`. Sans
 * Turbo, l'événement n'arrive jamais et la valeur reste `null` : le contrôleur se comporte
 * alors comme avant.
 */
let visitAction = null;

document.addEventListener('turbo:visit', (event) => {
    visitAction = event.detail?.action ?? null;
});

export default class extends Controller {
    connect() {
        if (!hasNavigated) {
            hasNavigated = true;

            return;
        }

        /*
         * La prévisualisation de cache de Turbo. Une visite remplace le `<body>` **deux
         * fois** : d'abord par la copie en cache de la page — le document porte alors
         * `data-turbo-preview` —, puis par la réponse du serveur. `connect()` est donc
         * rejoué sur un corps provisoire, dont les cibles sont celles de la page
         * précédente : y placer le focus le déplacerait sur un contenu périmé, avant de
         * devoir le déplacer une seconde fois.
         */
        if (document.documentElement.hasAttribute('data-turbo-preview')) {
            return;
        }

        const target = this.#firstTarget();

        // Aucune cible : on ne déplace rien et on ne lève rien. Une page sans `h1` est une
        // faute que `AccessibilityFloorTest` signale ; ce n'est pas à un contrôleur de
        // focus de la transformer en exception JavaScript.
        if (target === null) {
            return;
        }

        // Un `h1` ou un bloc d'erreur n'est pas focalisable par défaut. `tabindex="-1"` le
        // rend focalisable par programme sans l'ajouter à l'ordre de tabulation — et c'est
        // exactement pourquoi il ne doit jamais atterrir sur un élément qui, lui, y est
        // déjà : le champ `aria-invalid` d'un rendu 422 est un `<input>`, et le lui poser
        // le retirerait de l'ordre de tabulation au moment précis où l'utilisateur doit y
        // revenir pour corriger sa saisie.
        if (!target.hasAttribute('tabindex') && !target.matches(NATIVELY_FOCUSABLE)) {
            target.setAttribute('tabindex', '-1');
        }

        target.focus({ preventScroll: true });

        // Sur une visite de restauration, Turbo a déjà remis la position de défilement de
        // la page : la refaire défiler ici défait ce que l'utilisateur attend du bouton
        // « précédent ». Le focus, lui, reste posé — il ne déplace rien.
        if (visitAction !== 'restore') {
            target.scrollIntoView({ block: 'nearest' });
        }
    }

    #firstTarget() {
        for (const selector of FOCUS_ORDER) {
            const found = this.element.querySelector(selector);

            if (found !== null) {
                return found;
            }
        }

        return null;
    }
}
