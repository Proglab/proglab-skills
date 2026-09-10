import { Controller } from '@hotwired/stimulus';

/*
 * Recopie le texte d'un Flash ou d'un bloc d'erreur dans la région live du gabarit —
 * posé sur le message lui-même, jamais sur la région.
 *
 * Pourquoi une recopie plutôt qu'un `aria-live` sur le message : une région live n'annonce
 * que ses **mutations**. Un message présent dès le rendu de la page — ce qui est le cas
 * de tout Flash du socle, rendu côté serveur après une redirection 303 — n'est pas une
 * mutation et n'est donc pas annoncé. La région d'`base.html.twig`, elle, est là avant et
 * survit aux navigations (`data-turbo-permanent`) : y écrire est une vraie mutation.
 *
 * Le message garde sa propre sémantique (`role="status"` ou `role="alert"`) pour qui le
 * lit dans le flux du document ; c'est la région qui annonce.
 *
 * Amélioration progressive : sans JavaScript, le message est rendu et lisible à sa place
 * dans la page. Seule l'annonce automatique disparaît.
 */

const REGION_ID = 'annonces';

/*
 * Le délai. Écrire dans la région au même instant que l'insertion du message revient
 * souvent, pour un lecteur d'écran, à écrire avant qu'il ne surveille la région : rien
 * n'est annoncé. Un tour d'horloge suffit à séparer les deux.
 */
const DELAY_MS = 100;

/*
 * `polite` est l'état de repos de la région, pas seulement sa valeur de naissance.
 *
 * Une page peut porter plusieurs messages — la story 1.6 livre le premier rendu 422 et le
 * premier Flash, et rien n'interdit qu'ils coexistent. Chaque message s'inscrit donc ici
 * plutôt que d'écrire directement dans la région : le dernier connecté n'écrase plus le
 * précédent, et la priorité de la région se **recalcule** à chaque changement au lieu de se
 * fixer. C'est ce recalcul qui la ramène à `polite` — sans lui, la région porte
 * `data-turbo-permanent`, donc une seule erreur laissait toute la session en `assertive`,
 * où chaque annonce ordinaire interrompt la lecture en cours.
 */
const RESTING_PRIORITY = 'polite';

/** Les messages actuellement connectés, dans l'ordre du document. */
const announced = new Map();

let timeout = null;

/**
 * Réécrit la région à partir de tous les messages connectés.
 *
 * La région passe **par le vide** à chaque fois : deux messages identiques à la suite ne
 * produiraient sinon aucune mutation, et une région live n'annonce que ses mutations.
 */
function refresh() {
    const region = document.getElementById(REGION_ID);

    if (region === null) {
        return;
    }

    window.clearTimeout(timeout);

    const messages = [...announced.values()];

    region.textContent = '';
    region.setAttribute(
        'aria-live',
        // « assertive » interrompt la lecture en cours : dès qu'un des messages présents
        // le mérite, il l'emporte — une erreur ne doit pas attendre la fin d'un Flash.
        messages.some(({ priority }) => priority === 'assertive') ? 'assertive' : RESTING_PRIORITY,
    );

    if (messages.length === 0) {
        return;
    }

    timeout = window.setTimeout(() => {
        region.textContent = messages.map(({ message }) => message).join(' ');
    }, DELAY_MS);
}

export default class extends Controller {
    static values = {
        // « assertive » interrompt la lecture en cours — réservé à une erreur. Par
        // défaut, la priorité se déduit du rôle porté par le message.
        priority: String,
    };

    connect() {
        const message = this.element.textContent.trim();

        // Un message vide n'entre pas dans la composition, et ne fait donc pas non plus
        // basculer la priorité de la région.
        if (message === '') {
            return;
        }

        announced.set(this, { message, priority: this.#priority() });

        refresh();
    }

    disconnect() {
        announced.delete(this);

        // Et on repasse par `refresh()` plutôt que par un simple `clearTimeout` : c'est ce
        // qui rend la région à `polite` quand le dernier message s'en va, et qui laisse
        // les messages restants annoncés quand il en reste.
        refresh();
    }

    #priority() {
        if (this.priorityValue !== '') {
            return this.priorityValue;
        }

        return this.element.getAttribute('role') === 'alert' ? 'assertive' : 'polite';
    }
}
