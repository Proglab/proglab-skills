<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Dto\Input\FirstSuperAdminInput;
use App\Core\Enum\DerivativeState;
use App\Core\Exception\InitializationFailed;
use App\Core\Service\DerivativeInitializer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * « Je viens de cloner le socle : rends-le utilisable », en une commande.
 *
 * C'est la première chose que le guide de dérivation fera taper, d'où la forme à un
 * segment — `app:init` et non `app:derivative:init`, contre les deux de
 * `app:deployment:check`.
 *
 * **Aucune règle ne vit ici** (AC 4 de la story). La commande lit l'état que
 * `App\Core\Service\DerivativeInitializer` lui rend, enchaîne deux commandes Doctrine
 * existantes, pose trois questions, met en forme et rend un code de sortie. Elle ne prend
 * qu'une décision — le `match` sur `DerivativeState` — et cet état lui arrive tout fait.
 *
 * **Pourquoi elle enchaîne des sous-commandes plutôt que le service.** Jouer les migrations
 * depuis un service demanderait de piloter `DependencyFactory`, `PlanCalculator` et
 * `Migrator` à la main : une réimplémentation de `doctrine:migrations:migrate` à maintenir
 * contre l'amont, dans une couche à qui `deptrac.yaml` interdit de voir Doctrine.
 * Enchaîner les commandes existantes est de la plomberie console, au même titre que la
 * mise en forme de la sortie.
 *
 * **Le DDL n'est joué que sur `Uninitialized`, et c'est d'abord le bon comportement :** une
 * commande relancée ne doit rien retoucher, et le rapport doit dire ce qu'elle a sauté.
 * Que cela mette aussi les tests fonctionnels hors de portée de l'`implicit commit` MySQL
 * qui viderait la transaction de DAMA est une conséquence heureuse, pas la raison.
 *
 * **`--force` inverse le patron du skill console, et la story le demande.** Le standard
 * veut un dry-run par défaut sur une commande destructive ; ici le défaut sûr n'est pas
 * « rapporter » mais « agir sur une base vide, refuser sur une base peuplée » — parce que
 * le danger n'est pas la perte de données, c'est la création silencieuse d'un compte à tous
 * les droits sur un dérivé en service. L'écart est déclaré, pas contourné.
 *
 * Aucun verrou (`symfony/lock`) : la commande n'est ni longue ni planifiée. Aucune
 * traduction non plus — la sortie console suit le précédent de `DeploymentCheckCommand`,
 * en français et en dur ; les catalogues du socle sont pour l'interface.
 */
#[AsCommand(
    name: 'app:init',
    description: 'Prépare la base, joue les migrations et crée le premier Super admin',
)]
final readonly class InitCommand
{
    /**
     * Les deux commandes Doctrine, dans l'ordre, avec ce qui les rend rejouables.
     *
     * `--if-not-exists` parce qu'`Uninitialized` recouvre aussi « la base existe, son
     * schéma non » ; `--allow-no-migration` parce qu'un dérivé dont les migrations sont
     * déjà enregistrées ne doit pas sortir en échec ici — c'est la vérification des rôles,
     * juste après, qui dira ce qui manque vraiment, et en une phrase.
     *
     * @var array<string, array<string, bool>>
     */
    private const array SCHEMA_COMMANDS = [
        'doctrine:database:create' => ['--if-not-exists' => true],
        'doctrine:migrations:migrate' => ['--allow-no-migration' => true],
    ];

    public function __construct(
        private DerivativeInitializer $initializer,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        Application $application,
        #[Option(description: 'Créer un Super admin supplémentaire sur un dérivé déjà initialisé')]
        bool $force = false,
    ): int {
        $io->title('Initialisation du dérivé');

        // Cette commande est interactive par nature : elle demande des identifiants qu'on
        // ne peut ni deviner ni passer en option. Sans saisie possible, elle sort en
        // INVALID plutôt que de fabriquer un compte avec des valeurs par défaut — c'est
        // *notre* refus d'une invocation qui s'est pourtant correctement parsée.
        if (!$input->isInteractive()) {
            $io->getErrorStyle()->error(
                'app:init a besoin d\'une saisie : l\'adresse et le mot de passe du premier Super admin '
                .'sont demandés au clavier, jamais passés en option — ils fuiraient dans l\'historique du shell et dans « ps ». '
                .'Relance la commande sans --no-interaction.',
            );

            return Command::INVALID;
        }

        $state = $this->initializer->state();
        $io->writeln(\sprintf('État de départ : %s.', self::describe($state)));
        $io->newLine();

        $prepared = match ($state) {
            DerivativeState::Uninitialized => $this->prepareSchema($io, $application),
            DerivativeState::Empty, DerivativeState::Initialized => self::reportSchemaLeftAlone($io),
        };

        if (!$prepared) {
            return Command::FAILURE;
        }

        try {
            $this->initializer->verifyCoreRoles();
            $this->initializer->guardAgainstExistingAccounts($force);
        } catch (InitializationFailed $failure) {
            $io->getErrorStyle()->error($failure->getMessage());

            return Command::FAILURE;
        }

        if (DerivativeState::Initialized === $state) {
            $io->warning(\sprintf(
                'Ce dérivé porte déjà %d compte(s) et --force a été passé : le compte demandé ci-dessous sera un Super admin '
                .'SUPPLÉMENTAIRE. Rien ni personne n\'est remplacé ni désactivé.',
                $this->initializer->accountCount(),
            ));
        }

        $credentials = new FirstSuperAdminInput(
            $this->askEmail($io, $input),
            $this->askPassword($io, $input),
        );

        try {
            $user = $this->initializer->createFirstSuperAdmin($credentials);
        } catch (InitializationFailed $failure) {
            $io->getErrorStyle()->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Super admin « %s » créé. Connecte-toi sur la page de connexion du dérivé.',
            $user->getEmail(),
        ));

        return Command::SUCCESS;
    }

    /**
     * Les deux commandes Doctrine, sur le seul état qui les justifie.
     *
     * Rend `false` dès que l'une d'elles échoue, plutôt que de continuer vers des questions
     * dont la réponse ne pourrait pas être écrite. Leur propre sortie est passée telle
     * quelle — `SymfonyStyle` *est* un `OutputInterface` —, donc le log garde ce que
     * Doctrine a répondu, pas ce que cette commande en aurait résumé.
     */
    private function prepareSchema(SymfonyStyle $io, Application $application): bool
    {
        $io->section('Préparation du schéma');

        foreach (self::SCHEMA_COMMANDS as $name => $parameters) {
            $parameters = new ArrayInput($parameters);
            $parameters->setInteractive(false);

            if (Command::SUCCESS !== $application->find($name)->run($parameters, $io)) {
                $io->getErrorStyle()->error(\sprintf(
                    '« %s » a échoué : le schéma n\'a pas pu être préparé, et aucun compte n\'a été créé.',
                    $name,
                ));

                return false;
            }
        }

        // Obligatoire, et non défensif : les migrations laissent la connexion avec un
        // compteur de transaction faussé, et sans cette ligne la toute première écriture
        // du dérivé échoue sur « SAVEPOINT DOCTRINE_4 does not exist ». Le raisonnement
        // complet est sur `UserRepository::reopenConnection()`.
        $this->initializer->reopenAfterSchemaChange();

        return true;
    }

    /**
     * Le rapport de ce que la commande n'a **pas** fait.
     *
     * Rend toujours `true` : ne rien avoir à faire est un succès, et c'est ce qui rend la
     * commande idempotente. La ligne est écrite parce qu'une commande relancée qui se
     * tairait laisserait croire qu'elle a rejoué les migrations.
     */
    private static function reportSchemaLeftAlone(SymfonyStyle $io): bool
    {
        $io->writeln('Schéma : déjà en place, création de la base et migrations sautées.');
        $io->newLine();

        return true;
    }

    private static function describe(DerivativeState $state): string
    {
        return match ($state) {
            DerivativeState::Uninitialized => 'la table des comptes est introuvable — base absente, ou base sans schéma',
            DerivativeState::Empty => 'le schéma est en place, et aucun compte n\'existe',
            DerivativeState::Initialized => 'le schéma est en place, et au moins un compte existe',
        };
    }

    /**
     * L'adresse, reposée tant que les contraintes du DTO la refusent.
     *
     * La validation vient du DTO et de nulle part ailleurs — la commande l'appelle et
     * repose la question, exactement comme `PasswordController` valide et rerend.
     */
    private function askEmail(SymfonyStyle $io, InputInterface $input): string
    {
        while (true) {
            $email = $this->ask($io, $input, 'Adresse email du premier Super admin', secret: false);
            $problems = $this->problemsWith('email', $email);

            if ([] === $problems) {
                return $email;
            }

            $io->error($problems);
        }
    }

    /**
     * Le mot de passe, demandé deux fois, reposé tant qu'il est refusé ou non confirmé.
     *
     * **La comparaison des deux saisies n'est pas une règle métier** : c'est l'idiome
     * console du « tapez-le à nouveau », et il n'a aucun équivalent côté service — rien
     * n'est comparé, rien n'est stocké. Le jumeau HTTP porte la confirmation sur son DTO
     * parce qu'un formulaire doit marquer *quel champ* corriger ; une console n'a pas de
     * champ à marquer, elle repose les deux questions.
     *
     * La comparaison est un simple `===` : les deux opérandes sont le **même** secret, tapé
     * deux fois par la même personne sur son propre terminal, et il n'y a donc aucun
     * attaquant à qui une différence de durée apprendrait quoi que ce soit. `hash_equals()`
     * n'aurait rien acheté ici — elle sort d'ailleurs immédiatement sur une différence de
     * longueur, donc elle n'est pas « en temps constant » au sens où on le lit souvent.
     */
    private function askPassword(SymfonyStyle $io, InputInterface $input): string
    {
        while (true) {
            $password = $this->ask($io, $input, 'Mot de passe du premier Super admin', secret: true);
            $problems = $this->problemsWith('password', $password);

            if ([] !== $problems) {
                $io->error($problems);

                continue;
            }

            if ($password === $this->ask($io, $input, 'Confirmez le mot de passe', secret: true)) {
                return $password;
            }

            $io->error('Les deux saisies ne sont pas identiques. Le mot de passe est redemandé.');
        }
    }

    /**
     * Une question, masquée seulement quand le masquage veut dire quelque chose.
     *
     * **`setHidden(true)` ne cache rien par lui-même : il demande au *terminal* d'arrêter
     * d'écho-er** — `stty -echo` sous Unix, un exécutable dédié sous Windows. Quand
     * l'entrée n'est pas un terminal (un tube, un `CommandTester`), il n'y a aucun écho à
     * couper : le masquage n'a alors plus d'objet, et sous Windows il est même nuisible —
     * vérifié sur Symfony 7.4, `QuestionHelper::getHiddenResponse()` y lance
     * `hiddeninput.exe`, qui ignore le flux d'entrée fourni et attend la console réelle,
     * donc bloque indéfiniment. La condition ci-dessous est donc exactement celle sous
     * laquelle le masquage a un effet, et non un assouplissement.
     *
     * `setHiddenFallback(false)` ferme l'autre bout : sur un vrai terminal où le masquage
     * échouerait malgré tout, la commande s'arrête plutôt que d'écho-er un mot de passe.
     *
     * **Un secret n'est pas rogné, une adresse l'est.** `Question` rogne par défaut
     * (`Question::$trimmable`), et le rognage s'applique aussi au chemin masqué : un mot de
     * passe commençant ou finissant par une espace serait enregistré **différent de ce que
     * l'opérateur a tapé**, et la connexion HTTP le refuserait ensuite sans rien pouvoir
     * expliquer. Seul le saut de ligne est retiré, parce que `doReadInput()` le conserve —
     * il termine la ligne, il ne fait pas partie de la réponse. Une adresse, elle, reste
     * rognée : une espace autour d'un email est toujours une scorie de copier-coller.
     */
    private function ask(SymfonyStyle $io, InputInterface $input, string $label, bool $secret): string
    {
        $question = new Question($label);
        $question->setHidden($secret && self::readsFromATerminal($input));
        $question->setHiddenFallback(false);
        $question->setTrimmable(!$secret);

        $answer = $io->askQuestion($question);
        $answer = \is_string($answer) ? $answer : '';

        return $secret ? rtrim($answer, "\r\n") : $answer;
    }

    private static function readsFromATerminal(InputInterface $input): bool
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        // Un flux nul veut dire « le défaut du QuestionHelper », c'est-à-dire STDIN.
        $stream ??= \defined('STDIN') ? \STDIN : null;

        return \is_resource($stream) && stream_isatty($stream);
    }

    /**
     * Ce que les contraintes du DTO reprochent à une saisie, en clair.
     *
     * `validatePropertyValue()` lit les contraintes portées par la propriété du DTO sans
     * en construire un : c'est ce qui permet de reposer **la seule** question refusée
     * plutôt que les trois.
     *
     * @return list<string>
     */
    private function problemsWith(string $property, string $value): array
    {
        $problems = [];

        foreach ($this->validator->validatePropertyValue(FirstSuperAdminInput::class, $property, $value) as $violation) {
            $problems[] = (string) $violation->getMessage();
        }

        return $problems;
    }
}
