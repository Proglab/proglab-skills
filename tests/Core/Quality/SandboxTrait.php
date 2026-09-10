<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Le bac à sable jetable, tel que `BoundaryTest` l'a introduit pour deptrac.
 *
 * Une porte de qualité ne se prouve pas en relisant sa configuration : elle se prouve en
 * y jetant un défaut et en regardant l'outil sortir en 1. Le défaut vit dans un
 * répertoire temporaire sous `var/`, jamais dans le dépôt — sinon la porte devrait
 * s'accommoder de son propre cas de test.
 */
trait SandboxTrait
{
    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if (null !== $this->sandbox) {
            new Filesystem()->remove($this->sandbox);
            $this->sandbox = null;
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function sandboxWith(array $files): string
    {
        $filesystem = new Filesystem();

        // Un second appel dans le même test abandonnerait le premier bac à sable sous
        // `var/quality/`, que le tearDown ne verrait plus.
        if (null !== $this->sandbox) {
            $filesystem->remove($this->sandbox);
        }

        $this->sandbox = \sprintf('%s/var/quality/%s', self::projectDir(), bin2hex(random_bytes(8)));

        $filesystem->mkdir([$this->sandbox.'/src', $this->sandbox.'/tests', $this->sandbox.'/migrations']);

        foreach ($files as $path => $contents) {
            $filesystem->dumpFile($this->sandbox.'/'.$path, $contents);
        }

        return $this->sandbox;
    }

    /**
     * `Process` avec son `cwd`, jamais un `cd` passé à un shell : sous Windows `cd` sans
     * `/d` ne change pas de volume.
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private static function execute(array $command, string $cwd, array $env = []): array
    {
        $process = new Process($command, $cwd, $env, null, 300.0);
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
    }

    /**
     * Les outils sont épinglés par `composer.lock` et exécutés depuis `vendor/` — jamais
     * une installation globale, dont personne ne connaît la version.
     *
     * @param list<string> $candidates
     */
    private static function binary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $path = self::projectDir().'/'.$candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        self::fail(\sprintf('Aucun de ces exécutables n\'est installé : %s. L\'outil doit être un require-dev épinglé.', implode(', ', $candidates)));
    }

    private static function projectDir(): string
    {
        return GateFiles::projectDir();
    }
}
