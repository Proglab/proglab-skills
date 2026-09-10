<?php

declare(strict_types=1);

namespace App\Tests\Core\Accessibility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La mesure elle-même, sur les cas où elle a le droit de se taire et sur celui où elle
 * doit crier.
 *
 * `AccessibilityFloorTest` se sert de `Contrast` sur le thème livré ; ce test-ci tient le
 * contrat de la classe indépendamment des valeurs du moment — en particulier celui qui
 * l'empêche de rendre un chiffre faux sans le dire.
 */
final class ContrastTest extends TestCase
{
    /**
     * Une couleur translucide ne se mesure pas seule.
     *
     * Le ratio d'un `oklch(L C H / 10%)` dépend de ce qu'il y a derrière — donc de
     * l'empilement réel des éléments, que ce contrôle ne connaît pas. Traiter l'alpha
     * comme absent rendrait un ratio faux **en silence**, et un contrôle de contraste qui
     * se trompe sans le dire est pire que pas de contrôle du tout : il fait croire que la
     * question est réglée. `--border` porte un alpha aujourd'hui sans être dans aucune
     * paire mesurée ; un rebranding qui en amène un dans une paire doit s'arrêter ici.
     */
    #[Test]
    #[DataProvider('couleursTranslucides')]
    public function une_couleur_translucide_est_refusee_plutot_que_mesuree_de_travers(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/translucide/');

        Contrast::parse($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function couleursTranslucides(): iterable
    {
        yield 'alpha en pourcentage' => ['oklch(0.708 0 0 / 10%)'];
        yield 'alpha en nombre' => ['oklch(0.922 0 0 / 0.5)'];
        yield 'alpha collé au sélecteur' => ['oklch(0.577 0.245 27.325 / 20%)'];
    }

    /**
     * L'alpha explicitement opaque reste mesurable : `/ 1` et `/ 100%` ne changent rien à
     * ce que l'écran affiche, et les refuser transformerait une écriture équivalente en
     * panne de la porte.
     */
    #[Test]
    #[DataProvider('couleursOpaques')]
    public function une_couleur_opaque_se_mesure_avec_ou_sans_alpha_explicite(string $value): void
    {
        self::assertSame(
            Contrast::parse('oklch(1 0 0)'),
            Contrast::parse($value),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function couleursOpaques(): iterable
    {
        yield 'alpha à 1' => ['oklch(1 0 0 / 1)'];
        yield 'alpha à 100%' => ['oklch(1 0 0 / 100%)'];
    }

    /**
     * Le repère de la chaîne complète, sur les deux extrêmes : noir sur blanc vaut 21:1,
     * la valeur maximale que définit WCAG 2.1.
     */
    #[Test]
    public function le_ratio_du_noir_sur_blanc_est_celui_que_wcag_definit(): void
    {
        $palette = ['--noir' => 'oklch(0 0 0)', '--blanc' => 'oklch(1 0 0)'];

        self::assertEqualsWithDelta(21.0, Contrast::ratio($palette, '--noir', '--blanc'), 0.01);
    }
}
