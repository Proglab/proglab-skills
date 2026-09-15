<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Entity\User;
use App\Core\Enum\SupportedLocale;
use App\Core\Message\SendEmail;
use App\Core\Repository\UserRepository;
use App\Core\Service\EmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * La matrice d'edge cases de la story 1.9, ligne par ligne.
 *
 * Quatre précautions traversent la classe.
 *
 * **Trois lignes de la matrice ne se vérifient pas sur un fragment.** « Adresse inconnue »
 * et « compte désactivé » doivent rendre **la même page** qu'une adresse connue, et les
 * quatre façons dont un lien peut être mort doivent rendre **la même page** entre elles.
 * Elles sont donc comparées en entier, à la normalisation du jeton CSRF près — comparer un
 * sélecteur laisserait passer exactement la fuite qu'on ferme : une classe, un attribut ou
 * un ordre de nœuds qui diffère.
 *
 * **Le lien ouvert est celui de l'email**, lu sur la file (`PasswordTokens::sentToken()`)
 * et jamais fabriqué ici : c'est ce qui prouve que le jeton envoyé est celui que la base
 * sait retrouver.
 *
 * **La file est vidée à chaque requête, et ce n'est pas un défaut du test.**
 * `KernelBrowser` redémarre le noyau avant chaque requête sauf la première, donc le
 * transport `in-memory` du conteneur est celui de la **dernière** requête. Les comptages
 * ci-dessous portent donc toujours sur la soumission qui vient d'avoir lieu ; ce qui
 * traverse les requêtes, c'est la base, que DAMA garde dans une seule transaction.
 *
 * **L'état du limiteur, lui, ne vit pas en base.** Cette classe soumet plusieurs demandes,
 * donc elle efface le pool dans son `setUp()` comme les trois classes de connexion le
 * font — sans quoi elle s'auto-ralentirait à la relance.
 */
final class PasswordResetTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string EMAIL = 'marc@example.test';

    private const string OLD_PASSWORD = 'un-mot-de-passe-assez-long';

    private const string NEW_PASSWORD = 'girafe-tranquille-42-bureau';

    private const string REQUEST_PATH = '/password/forgot';

    protected function setUp(): void
    {
        PasswordThrottling::forget();
    }

    // -------------------------------------------------------------------------------
    // La demande
    // -------------------------------------------------------------------------------

    #[Test]
    public function the_request_page_renders_for_an_anonymous_visitor(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', self::REQUEST_PATH);

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_password_request');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('input[name="email"][autocomplete="email"]', 'Le champ email ne porte pas son jeton `autocomplete` (WCAG 1.3.5).');
        self::assertSelectorExists('input[name="_csrf_token"]', 'Le formulaire de demande ne porte aucun jeton CSRF (AD-18).');
        self::assertSelectorNotExists('[role="status"]', 'Une page ouverte sans soumission ne porte aucune confirmation.');
        self::assertCount(1, $crawler->filter('form label'), 'Le champ de la demande doit porter un label réel (règle 3).');
    }

    /**
     * Le formulaire sort de Turbo, et ce n'est pas un détail de gabarit.
     *
     * La demande acceptée répond **200 sur la même page** — la matrice l'exige, et le test
     * suivant l'assure. Turbo Drive, lui, refuse une réponse de formulaire en 200 sans
     * redirection : il jette « Form responses must redirect to another location » et ne
     * remplace rien. Sans cet attribut, la confirmation ne s'affiche jamais dans un
     * navigateur **alors que toute cette classe reste verte** : `WebTestCase` n'exécute pas
     * JavaScript, il voit le 200 et y trouve la confirmation. C'est le seul endroit du
     * dépôt où la promesse se vérifie côté serveur, d'où cette ligne.
     *
     * Les autres réponses de la page passeraient Turbo sans rien : il affiche les 4xx et
     * les 5xx, donc le 422 de validation comme le 429 du limiteur. C'est bien le chemin du
     * succès, et lui seul, qui impose de sortir le formulaire entier.
     */
    #[Test]
    public function the_request_form_is_submitted_outside_turbo(): void
    {
        $client = self::createClient();
        $client->request('GET', self::REQUEST_PATH);

        self::assertSelectorExists(
            \sprintf('form[action="%s"][data-turbo="false"]', self::REQUEST_PATH),
            'Le formulaire de demande doit porter `data-turbo="false"` : sa réponse de succès est un 200 sans redirection, que Turbo Drive rejette en silence côté navigateur.',
        );
    }

    #[Test]
    public function the_login_page_offers_the_way_in(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter(\sprintf('a[href="%s"]', self::REQUEST_PATH)),
            'La page de connexion doit porter le lien « Mot de passe oublié ? » : c\'est l\'entrée du flux.',
        );
    }

    #[Test]
    public function a_known_address_queues_one_email_and_stores_one_token(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD, language: SupportedLocale::Nl);

        $client->submit(self::requestForm($client, self::EMAIL));

        self::assertResponseIsSuccessful('La demande répond 200 sur la même page, elle ne redirige pas.');
        self::assertSelectorTextContains('[role="status"]', 'Si un compte existe pour cette adresse, un lien vient d\'être envoyé. Il est valable une heure.');

        self::assertSame(1, PasswordTokens::queuedCount(self::getContainer()));
        self::assertCount(1, PasswordTokens::rows(self::getContainer()));

        $sent = self::lastMessage();
        self::assertSame(self::EMAIL, $sent->toEmail);
        self::assertSame(SupportedLocale::Nl, $sent->locale, 'L\'email doit être rendu dans la langue du compte, portée par le message.');
    }

    /**
     * La matrice le dit ainsi : réponse identique, hors jeton CSRF.
     */
    #[Test]
    public function an_unknown_address_answers_exactly_like_a_known_one(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $client->submit(self::requestForm($client, self::EMAIL));
        $known = self::normalise($client);
        self::assertResponseIsSuccessful();
        self::assertSame(1, PasswordTokens::queuedCount(self::getContainer()));

        $client->submit(self::requestForm($client, 'fantome@example.test'));
        $unknown = self::normalise($client);

        self::assertResponseIsSuccessful();
        self::assertSame($known, $unknown, 'Une adresse inconnue rend une page différente : comparer les deux réponses révèle quels comptes existent.');
        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()), 'Une adresse inconnue a déclenché un email.');
        self::assertCount(1, PasswordTokens::rows(self::getContainer()), 'Une adresse inconnue a fait naître un jeton.');
    }

    /**
     * Un compte désactivé ne reprend pas son accès seul — et la page ne le dit pas.
     */
    #[Test]
    public function a_disabled_account_answers_exactly_like_an_active_one(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);
        Accounts::create(self::getContainer(), 'karim@example.test', self::OLD_PASSWORD, false);

        $client->submit(self::requestForm($client, self::EMAIL));
        $active = self::normalise($client);
        self::assertSame(1, PasswordTokens::queuedCount(self::getContainer()));

        $client->submit(self::requestForm($client, 'karim@example.test'));
        $disabled = self::normalise($client);

        self::assertResponseIsSuccessful();
        self::assertSame($active, $disabled, 'Le refus d\'un compte désactivé doit être indiscernable de l\'acceptation d\'un compte actif.');
        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()), 'Un compte désactivé a reçu un lien de réinitialisation.');
        self::assertCount(1, PasswordTokens::rows(self::getContainer()));
    }

    #[Test]
    public function a_malformed_address_is_refused_with_a_format_error(): void
    {
        $client = self::createClient();
        $crawler = $client->submit(self::requestForm($client, 'n\'importe quoi'));

        self::assertResponseStatusCodeSame(422, 'Une entrée invalide réaffiche le formulaire en 422, elle ne redirige pas.');
        self::assertSelectorExists('input[name="email"][aria-invalid="true"]', 'Le champ fautif doit être marqué en erreur.');
        self::assertSelectorNotExists('[role="status"]', 'Une entrée invalide ne doit pas porter la confirmation d\'envoi.');

        $described = $crawler->filter('input[name="email"]')->attr('aria-describedby');
        self::assertIsString($described);
        self::assertCount(1, $crawler->filter('#'.$described), 'Le champ en erreur pointe un message absent de la page (règle 1).');

        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()));
        self::assertSame([], PasswordTokens::rows(self::getContainer()));
    }

    #[Test]
    public function an_empty_address_is_refused_the_same_way(): void
    {
        $client = self::createClient();
        $client->submit(self::requestForm($client, ''));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="email"][aria-invalid="true"]');
        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()));
    }

    /**
     * Une entrée qui n'est même pas une chaîne reste une **entrée invalide**, pas une
     * requête malformée.
     *
     * C'est toute la raison d'être de `PasswordController::string()` :
     * `$request->getPayload()->getString('email')` lève une `BadRequestException` — donc
     * un 400, une page d'erreur — sur `email[]=x`, là où la matrice promet un 422 avec
     * l'erreur de format sur le champ. Revenir à `getString()` casserait cette ligne avec
     * toute la suite au vert.
     */
    #[Test]
    public function an_address_that_is_not_even_a_string_is_refused_like_any_bad_format(): void
    {
        $client = self::createClient();

        // Le formulaire est demandé pour son jeton CSRF ; la charge utile, elle, est
        // assemblée à la main — aucun navigateur ne poste ça, et c'est le point.
        $csrf = self::csrfTokenOf($client->request('GET', self::REQUEST_PATH)->filter('form')->form());

        $client->request('POST', self::REQUEST_PATH, [
            'email' => ['x'],
            '_csrf_token' => $csrf,
        ]);

        self::assertResponseStatusCodeSame(422, 'Un `email[]=x` rend autre chose qu\'un 422 : la page d\'erreur a remplacé le formulaire rerendu.');
        self::assertSelectorExists('input[name="email"][aria-invalid="true"]');
        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()));
    }

    /**
     * Aucun message de format ne doit parler d'existence : c'est la seule chose qu'un 422
     * pourrait dire et que le 200 tait.
     */
    #[Test]
    public function no_format_error_ever_speaks_of_an_account(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $client->submit(self::requestForm($client, 'pas-une-adresse'));

        $error = self::fieldErrorText($client);
        self::assertNotSame('', $error, 'Le 422 ne porte aucun message de champ : la couleur seule signalerait l\'erreur (règle 1).');

        foreach (['compte', 'existe', 'inconnu', 'introuvable'] as $word) {
            self::assertStringNotContainsStringIgnoringCase(
                $word,
                $error,
                \sprintf('Le message de format porte le mot « %s » : une erreur de forme ne parle jamais de l\'existence d\'un compte.', $word),
            );
        }
    }

    /**
     * AD-15 : un seul lien vivant à la fois.
     */
    #[Test]
    public function a_new_request_kills_the_link_of_the_previous_one(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $client->submit(self::requestForm($client, self::EMAIL));
        $first = PasswordTokens::sentToken(self::getContainer());

        $client->submit(self::requestForm($client, self::EMAIL));
        $second = PasswordTokens::sentToken(self::getContainer());

        self::assertNotSame($first, $second);
        self::assertCount(1, PasswordTokens::rows(self::getContainer()), 'Deux jetons vivants coexistent : deux liens ouvrent le même compte.');

        $client->request('GET', self::resetPath($second));
        self::assertResponseIsSuccessful('Le lien le plus récent doit rester ouvrable.');

        $client->request('GET', self::resetPath($first));
        self::assertResponseStatusCodeSame(410, 'Le premier lien reste ouvrable après une seconde demande.');
    }

    /**
     * L'email lui-même : rendu dans la langue du compte, et porteur d'un lien **absolu**
     * qui fonctionne.
     *
     * C'est la vérification manuelle du spec — « l'email arrive dans la langue du compte,
     * le lien fonctionne » — mécanisée. Le message est rendu par le service que le worker
     * appelle, donc hors de toute requête : `url()` doit y prendre son hôte dans
     * `framework.router.default_uri`, et `|trans` sa langue dans le contexte du message.
     * Un `path()` ou un `|trans` sans quatrième argument rougirait ici.
     */
    #[Test]
    public function the_email_is_rendered_in_the_account_language_and_carries_a_working_link(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD, language: SupportedLocale::Nl);

        $client->submit(self::requestForm($client, self::EMAIL));

        $token = PasswordTokens::sentToken(self::getContainer());

        // Ce que fera le worker, mot pour mot : le handler délègue à ce service et rien
        // d'autre.
        self::getContainer()->get(EmailSender::class)->send(self::lastMessage());

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        self::assertSame('Kies een nieuw wachtwoord', $email->getSubject(), 'Le sujet n\'est pas rendu dans la langue du compte.');

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('automatisch bericht', $html, 'Le pied de page n\'est pas rendu dans la langue du compte.');
        self::assertStringContainsString(
            'http://localhost/password/reset/'.$token,
            $html,
            'Le lien de l\'email n\'est pas absolu : dans un worker il n\'y a pas de requête, donc un lien relatif ne mène nulle part.',
        );

        $client->request('GET', self::resetPath($token));
        self::assertResponseIsSuccessful('Le lien porté par l\'email n\'ouvre pas la page de réinitialisation.');
    }

    // -------------------------------------------------------------------------------
    // La réinitialisation
    // -------------------------------------------------------------------------------

    #[Test]
    public function the_link_opens_a_form_with_two_new_password_fields(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $crawler = $client->request('GET', self::resetPath($token));

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_password_reset');
        self::assertSelectorCount(1, 'h1');
        self::assertCount(
            2,
            $crawler->filter('input[type="password"][autocomplete="new-password"]'),
            'Les deux saisies doivent porter `autocomplete="new-password"` (WCAG 1.3.5).',
        );
        self::assertCount(2, $crawler->filter('form label'), 'Chaque saisie porte un label réel (règle 3).');
        self::assertSelectorExists('input[name="_csrf_token"]', 'Le formulaire de réinitialisation ne porte aucun jeton CSRF (AD-18).');
    }

    #[Test]
    public function two_matching_entries_change_the_password_and_land_on_login_with_its_flash(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $client->submit(self::resetForm($client, $token, self::NEW_PASSWORD, self::NEW_PASSWORD));

        self::assertResponseRedirects('/login', 303, 'La réinitialisation se termine sur la page de connexion, en 303.');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main [data-flash]', 'Mot de passe changé. Connectez-vous avec le nouveau.');

        self::assertTrue(self::passwordIsNow(self::NEW_PASSWORD), 'Le mot de passe n\'a pas changé.');

        $rows = PasswordTokens::rows(self::getContainer());
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]['used_at'] ?? null, 'Le jeton consommé doit porter sa date d\'usage.');
    }

    /**
     * Le Flash est le premier du socle, et son contrat est déjà écrit dans
     * `assets/controllers/page_focus_controller.js` : `[data-flash]` rendu dans `<main>`,
     * focalisable par programme, et le contrôleur d'annonce posé **sur le message**.
     */
    #[Test]
    public function the_flash_carries_the_contract_the_focus_controller_expects(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $client->submit(self::resetForm($client, $token, self::NEW_PASSWORD, self::NEW_PASSWORD));
        $crawler = $client->followRedirect();

        $flash = $crawler->filter('main [data-flash]');

        self::assertCount(1, $flash, 'Un seul Flash à la fois, et il est rendu dans `<main>` — sinon `page-focus` ne le trouve pas.');
        self::assertSame('-1', $flash->attr('tabindex'), 'Le Flash doit être focalisable par programme pour recevoir le focus après la navigation.');
        self::assertSame('status', $flash->attr('role'), 'Un Flash de succès est un `role="status"` : `role="alert"` interromprait la lecture en cours.');
        self::assertSame('announce', $flash->attr('data-controller'), 'Le contrôleur d\'annonce se pose sur le message, jamais sur la région.');
    }

    /**
     * Un Flash consommé ne revient pas : il est lu une fois, et la page suivante est nue.
     */
    #[Test]
    public function the_flash_does_not_haunt_the_next_page(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $client->submit(self::resetForm($client, $token, self::NEW_PASSWORD, self::NEW_PASSWORD));
        $client->followRedirect();

        $client->request('GET', '/login');

        self::assertSelectorNotExists('[data-flash]', 'Le Flash d\'une réinitialisation précédente est encore affiché.');
    }

    #[Test]
    public function a_mismatched_confirmation_is_refused_and_leaves_the_link_usable(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $crawler = $client->submit(self::resetForm($client, $token, self::NEW_PASSWORD, 'autre-chose-entierement'));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(
            1,
            $crawler->filter('input[name="confirmation"][aria-invalid="true"]'),
            'L\'erreur appartient au champ de confirmation, et à lui seul.',
        );

        // **Le message rendu, et pas seulement son existence.** Le catalogue
        // `translations/validators.{fr,en,nl}.yaml` est la seule chose qui sépare cette
        // phrase de deux défauts : la clé brute affichée à l'utilisateur si elle est
        // renommée, et le mot de passe saisi réémis dans le HTML si le `message:`
        // personnalisé du DTO disparaît.
        self::assertSame(
            'Les deux mots de passe ne sont pas identiques.',
            self::fieldErrorText($client),
            'Le message d\'égalité rendu n\'est pas celui du catalogue : la clé est peut-être affichée telle quelle.',
        );

        self::assertFalse(self::passwordIsNow(self::NEW_PASSWORD), 'Le mot de passe a changé alors que les deux saisies différaient.');

        $rows = PasswordTokens::rows(self::getContainer());
        self::assertCount(1, $rows);
        self::assertArrayHasKey('used_at', $rows[0]);
        self::assertNull($rows[0]['used_at'], 'Le jeton a été consommé par une soumission refusée.');

        $client->request('GET', self::resetPath($token));
        self::assertResponseIsSuccessful('Le lien doit rester utilisable après une saisie refusée.');
    }

    /**
     * Un mot de passe trop faible est refusé **par le contrôleur**, et le lien survit.
     *
     * Ce qui est vérifié ici est le câblage, et lui seul : la boucle « valider, rerendre en
     * 422 » marque le bon champ, ne consomme pas le jeton, et laisse le lien ouvrable. Où
     * se trouve exactement le seuil — les deux bornes de `STRENGTH_MEDIUM`, la borne haute
     * de longueur, le message d'égalité — est une règle du DTO, et elle vit dans
     * `tests/Core/Dto/PasswordResetInputTest.php`, sans noyau ni requête.
     */
    /**
     * La règle de robustesse se lit **avant** la saisie, pas seulement après l'échec.
     *
     * Le seuil de `PasswordStrength` est indevinable : son score dépend massivement de la
     * longueur et presque pas de la complexité. Découvrir la règle en échouant, sur la page
     * où l'on a déjà perdu son accès, est exactement le scénario que la story veut éviter —
     * d'où un paragraphe rendu dès l'ouverture, relié au champ par `aria-describedby` pour
     * qu'un lecteur d'écran l'annonce à la prise de focus et non après coup.
     */
    #[Test]
    public function the_reset_page_states_the_password_rule_before_anything_is_typed(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $crawler = $client->request('GET', self::resetPath($token));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#password-reset-password-hint', '16 caractères');

        $described = $crawler->filter('input[name="password"]')->attr('aria-describedby');
        self::assertIsString($described);
        self::assertContains('password-reset-password-hint', explode(' ', $described), 'Le champ doit pointer la règle : un texte posé à côté sans lien n\'est pas annoncé à la prise de focus.');
    }

    /**
     * Et le refus dit le critère plutôt qu'un verdict.
     *
     * C'est ici, et non dans `PasswordResetInputTest`, que le texte **rendu** se lit : le
     * DTO épingle la clé, le catalogue la formule, et cette ligne est la seule qui prouve
     * que les deux se rejoignent dans la page.
     *
     * **Les deux paragraphes se partagent le travail, ils ne le répètent pas.** L'erreur dit
     * ce que l'indication ne peut pas dire — que cette saisie-là vient d'être refusée — et
     * l'indication garde le critère, au même endroit qu'avant la soumission. Écrire le seuil
     * dans les deux donnait deux phrases superposées disant la même chose ; la dernière
     * assertion est ce qui empêche d'y revenir sans s'en apercevoir.
     */
    #[Test]
    public function the_strength_refusal_tells_the_user_what_to_do(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $crawler = $client->submit(self::resetForm($client, $token, 'azerty123', 'azerty123'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#password-reset-password-error', 'trop court');
        self::assertSelectorTextContains('#password-reset-password-hint', '16 caractères', 'La règle disparaît au moment où elle sert le plus.');
        self::assertSelectorTextNotContains('#password-reset-password-error', '16 caractères', 'L\'erreur redit le critère que l\'indication porte déjà, juste au-dessus d\'elle.');

        $described = explode(' ', (string) $crawler->filter('input[name="password"]')->attr('aria-describedby'));
        self::assertSame(['password-reset-password-error', 'password-reset-password-hint'], $described, 'L\'erreur doit précéder la règle : la liste se lit dans l\'ordre écrit.');
    }

    #[Test]
    public function a_password_below_the_threshold_is_refused_and_leaves_the_link_usable(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $crawler = $client->submit(self::resetForm($client, $token, 'azerty123', 'azerty123'));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('input[name="password"][aria-invalid="true"]'));
        self::assertFalse(self::passwordIsNow('azerty123'));

        $client->request('GET', self::resetPath($token));
        self::assertResponseIsSuccessful();
    }

    /**
     * Un mot de passe refusé n'est jamais réémis dans le HTML : il repartirait dans
     * l'historique du navigateur et dans les caches intermédiaires.
     */
    #[Test]
    public function a_refused_entry_is_never_echoed_back(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $client->submit(self::resetForm($client, $token, self::NEW_PASSWORD, 'autre-chose-entierement'));

        self::assertStringNotContainsString(self::NEW_PASSWORD, (string) $client->getResponse()->getContent());
    }

    // -------------------------------------------------------------------------------
    // Les quatre façons dont un lien est mort — et la seule page qu'elles rendent
    // -------------------------------------------------------------------------------

    #[Test]
    public function an_expired_link_shows_the_expired_page_without_a_form(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);
        PasswordTokens::expire(self::getContainer(), $token);

        $crawler = $client->request('GET', self::resetPath($token));

        self::assertResponseStatusCodeSame(410, 'Un lien mort se dit en 410 — « a existé, n\'existe plus » —, jamais en 404.');
        self::assertSelectorTextContains('main', 'Ce lien a expiré. Demandez-en un nouveau.');
        self::assertSelectorNotExists('form', 'La page « lien expiré » ne porte aucun formulaire : rien saisi ici ne servirait à quoi que ce soit.');
        self::assertCount(
            1,
            $crawler->filter(\sprintf('main a[href="%s"]', self::REQUEST_PATH)),
            'La page « lien expiré » doit proposer de recommencer.',
        );
    }

    #[Test]
    public function an_unknown_token_shows_exactly_the_same_page_as_an_expired_one(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);
        PasswordTokens::expire(self::getContainer(), $token);

        $client->request('GET', self::resetPath($token));
        $expired = (string) $client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(410);

        $client->request('GET', self::resetPath(bin2hex(random_bytes(32))));
        self::assertResponseStatusCodeSame(410);
        self::assertSame($expired, (string) $client->getResponse()->getContent(), 'Un jeton jamais émis se distingue d\'un jeton expiré : essayer des jetons au hasard apprendrait lesquels ont existé.');

        $client->request('GET', self::resetPath('pas-un-jeton'));
        self::assertResponseStatusCodeSame(410, 'Un jeton malformé doit rendre la même page, jamais un 404.');
        self::assertSame($expired, (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function replaying_the_reset_post_changes_nothing_the_second_time(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $form = self::resetForm($client, $token, self::NEW_PASSWORD, self::NEW_PASSWORD);
        $csrf = self::csrfTokenOf($form);

        $client->submit($form);
        self::assertResponseRedirects('/login', 303);

        $hashAfterFirst = self::currentHash();

        $client->request('POST', self::resetPath($token), [
            'password' => 'encore-un-autre-mot-de-passe',
            'confirmation' => 'encore-un-autre-mot-de-passe',
            '_csrf_token' => $csrf,
        ]);

        self::assertResponseStatusCodeSame(410, 'Un jeton rejoué doit rendre la page « lien expiré ».');
        self::assertSame($hashAfterFirst, self::currentHash(), 'Le rejeu a rechangé le mot de passe.');
    }

    #[Test]
    public function a_link_issued_before_the_account_was_disabled_is_dead(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        // Le formulaire est obtenu tant que le compte est actif : c'est ce qui donne un
        // jeton CSRF utilisable, et ce qui fait porter le refus sur l'état du compte et
        // non sur la protection CSRF.
        $form = self::resetForm($client, $token, self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::disableTheAccount();

        $client->submit($form);

        self::assertResponseStatusCodeSame(410, 'Un compte désactivé après l\'émission du lien ne reprend pas son accès.');
        self::assertFalse(self::passwordIsNow(self::NEW_PASSWORD));
    }

    // -------------------------------------------------------------------------------
    // Le jeton en clair
    // -------------------------------------------------------------------------------

    #[Test]
    public function the_plain_token_appears_nowhere_but_in_the_link(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent(), 'Le jeton en clair est rendu dans la réponse de la demande.');

        $rows = PasswordTokens::rows(self::getContainer());
        self::assertCount(1, $rows);

        foreach ($rows[0] as $column => $value) {
            self::assertNotSame($token, $value, \sprintf('La colonne « %s » porte le jeton en clair : la base ne doit garder que son empreinte.', $column));
        }

        self::assertSame(
            hash('sha256', $token),
            $rows[0]['token_hash'] ?? null,
            'La colonne ne porte pas l\'empreinte SHA-256 du jeton : la recherche indexée ne peut pas être celle que le spec décrit.',
        );

        self::assertLogsAreFreeOf($token, 'après la demande');

        // Et la page qui l'accepte ne le réémet pas : le formulaire poste sur l'URL
        // courante, il ne recopie pas le jeton dans un champ caché.
        $crawler = $client->request('GET', self::resetPath($token));
        self::assertCount(0, $crawler->filter(\sprintf('input[value="%s"]', $token)));

        // **Et après le POST qui consomme le lien, pas seulement après la demande.** Les
        // journaux du conteneur sont ceux de la **dernière** requête — `KernelBrowser`
        // redémarre le noyau à chaque fois —, donc contrôler une seule requête laisse tout
        // le reste du flux hors du regard. C'est le POST de réinitialisation qui traverse
        // le plus de couches : validation, hacheur, transaction, sécurité.
        $client->submit(self::resetForm($client, $token, self::NEW_PASSWORD, self::NEW_PASSWORD));
        self::assertResponseRedirects('/login', 303);

        self::assertLogsAreFreeOf($token, 'après la réinitialisation');
    }

    // -------------------------------------------------------------------------------
    // CSRF (AD-18)
    // -------------------------------------------------------------------------------

    #[Test]
    public function a_reset_post_without_its_csrf_token_changes_nothing(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $client->request('POST', self::resetPath($token), [
            'password' => self::NEW_PASSWORD,
            'confirmation' => self::NEW_PASSWORD,
        ]);

        // 302 vers `/login`, et c'est le chemin natif : `#[IsCsrfTokenValid]` lève une
        // `InvalidCsrfTokenException`, qui est une `AuthenticationException` — l'écouteur
        // d'exception du pare-feu la transforme en renvoi vers le point d'entrée. Ce qui
        // compte est qu'aucune écriture n'ait eu lieu ; le point de chute est honnête pour
        // quelqu'un dont le jeton de session vient d'expirer.
        self::assertResponseRedirects('http://localhost/login', 302, 'Un POST sans jeton CSRF ne doit pas être traité comme une soumission valide.');
        self::assertFalse(self::passwordIsNow(self::NEW_PASSWORD), 'Un POST sans jeton CSRF a changé le mot de passe (AD-18).');
    }

    #[Test]
    public function a_request_post_without_its_csrf_token_sends_nothing(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $client->request('POST', self::REQUEST_PATH, ['email' => self::EMAIL]);

        self::assertResponseRedirects('http://localhost/login', 302);
        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()), 'Un POST sans jeton CSRF a déclenché un email (AD-18).');
        self::assertSame([], PasswordTokens::rows(self::getContainer()));
    }

    /**
     * **Les deux formulaires n'acceptent pas le même jeton.**.
     *
     * La page de demande est publique et anonyme : n'importe qui peut en obtenir un jeton
     * CSRF valide. Si les deux identifiants étaient les mêmes — ou si un seul servait aux
     * deux —, ce jeton-là deviendrait valable pour le POST qui **change un mot de passe**,
     * et la protection ne protégerait plus que la page qui n'en a pas besoin. Poser
     * `CSRF_RESET = CSRF_REQUEST` laisse toute la matrice au vert ; c'est cette assertion,
     * et elle seule, qui rougit.
     */
    #[Test]
    public function the_token_of_the_request_form_does_not_open_the_reset_form(): void
    {
        $client = self::createClient();
        $token = self::askForALink($client);

        $requestToken = self::csrfTokenOf($client->request('GET', self::REQUEST_PATH)->filter('form')->form());

        $client->request('POST', self::resetPath($token), [
            'password' => self::NEW_PASSWORD,
            'confirmation' => self::NEW_PASSWORD,
            '_csrf_token' => $requestToken,
        ]);

        self::assertResponseRedirects('http://localhost/login', 302, 'Le jeton CSRF de la page de demande a été accepté par la réinitialisation : les deux formulaires partagent leur identifiant.');
        self::assertFalse(self::passwordIsNow(self::NEW_PASSWORD), 'Un jeton CSRF obtenu sur la page publique de demande a changé le mot de passe.');
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    /**
     * Le parcours complet jusqu'au lien reçu : un compte, une demande, et le jeton que
     * l'email porte.
     */
    private static function askForALink(KernelBrowser $client): string
    {
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $client->submit(self::requestForm($client, self::EMAIL));

        return PasswordTokens::sentToken(self::getContainer());
    }

    private static function requestForm(KernelBrowser $client, string $email): Form
    {
        $form = $client->request('GET', self::REQUEST_PATH)->filter('form')->form();

        $form['email'] = $email;

        return $form;
    }

    private static function resetForm(KernelBrowser $client, string $token, string $password, string $confirmation): Form
    {
        $form = $client->request('GET', self::resetPath($token))->filter('form')->form();

        $form['password'] = $password;
        $form['confirmation'] = $confirmation;

        return $form;
    }

    /**
     * Le jeton CSRF d'un formulaire déjà rendu.
     *
     * Il est lu sur les **valeurs** du formulaire et non sur le champ : `Form::get()` rend
     * un champ ou un tableau de champs selon le nom, donc une union que rien ne resserre.
     */
    private static function csrfTokenOf(Form $form): string
    {
        $values = $form->getValues();

        self::assertArrayHasKey('_csrf_token', $values);
        self::assertIsString($values['_csrf_token']);

        return $values['_csrf_token'];
    }

    private static function resetPath(string $token): string
    {
        return '/password/reset/'.rawurlencode($token);
    }

    /**
     * La page rendue, débarrassée du seul fragment qui change légitimement d'une
     * soumission à l'autre.
     */
    private static function normalise(KernelBrowser $client): string
    {
        return (string) preg_replace(
            '/(name="_csrf_token" value=")[^"]*/',
            '$1JETON',
            (string) $client->getResponse()->getContent(),
        );
    }

    private static function fieldErrorText(KernelBrowser $client): string
    {
        return trim($client->getCrawler()->filter('[data-field-error]')->text(''));
    }

    private static function lastMessage(): SendEmail
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $sent = $transport->getSent();
        self::assertNotSame([], $sent);

        $message = end($sent)->getMessage();
        self::assertInstanceOf(SendEmail::class, $message);

        return $message;
    }

    private static function disableTheAccount(): void
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        self::freshUser()->setEnabled(false);

        $entityManager->flush();
    }

    private static function passwordIsNow(string $password): bool
    {
        $user = self::freshUser();

        // L'alias public `when@test` de `config/services.yaml` : le hachage des tests
        // traverse le hacheur du projet, jamais un algorithme écrit à la main ici.
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher->isPasswordValid($user, $password);
    }

    private static function currentHash(): string
    {
        return self::freshUser()->getPassword();
    }

    /**
     * Le compte relu depuis la base, jamais l'objet que le test a créé : le changement de
     * mot de passe a lieu dans une autre requête, sur un autre gestionnaire d'entités.
     */
    private static function freshUser(): User
    {
        $container = self::getContainer();

        $container->get(EntityManagerInterface::class)->clear();

        $user = $container->get(UserRepository::class)->findOneByEmail(self::EMAIL);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Aucun enregistrement de la requête qui vient d'avoir lieu ne porte cette valeur — ni
     * dans son message, ni dans ses données structurées.
     *
     * **Les trois champs, et pas seulement le message.** Monolog range dans `context` ce
     * qu'un appelant lui passe et dans `extra` ce que les processors ajoutent : un jeton
     * qui fuirait passerait très probablement par là — `['token' => …]` à côté d'une phrase
     * anodine — et un contrôle qui ne lit que `message` ne le verrait jamais.
     */
    private static function assertLogsAreFreeOf(string $secret, string $moment): void
    {
        foreach (self::testHandler()->getRecords() as $record) {
            $written = json_encode(
                ['message' => $record->message, 'context' => $record->context, 'extra' => $record->extra],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
            );

            self::assertStringNotContainsString(
                $secret,
                $written,
                \sprintf('Le jeton en clair est passé dans les journaux applicatifs %s.', $moment),
            );
        }
    }

    private static function testHandler(): TestHandler
    {
        $handler = self::getContainer()->get('monolog.handler.testing');

        self::assertInstanceOf(TestHandler::class, $handler, 'Le handler `testing` de `when@test` a disparu : plus rien n\'observe ce que le socle journalise.');

        return $handler;
    }
}
