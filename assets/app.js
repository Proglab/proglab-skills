/*
 * Le point d'entrée JavaScript du socle.
 *
 * AssetMapper voit cet import et met `styles/app.css` dans la map ; `importmap()` génère
 * alors un vrai `<link rel="stylesheet">` — le CSS n'est pas injecté par du JavaScript,
 * donc il n'y a pas de flash de contenu non stylé.
 *
 * Aucun bundler, aucune étape Node : les modules sont servis tels quels et résolus par
 * l'import map (AD-1).
 */

import './stimulus_bootstrap.js';
import './styles/app.css';
