<?php

namespace App\Setup;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The setup page, /setup.php: installs or upgrades Agorakit from a browser,
 * for hosts where no command can be run.
 *
 * It works without a configured application: it only needs the installed
 * dependencies. It keeps its state in its own PHP session, and each action is
 * a form posted back to the page, answered by a redirection.
 */
class SetupPage
{
    public const LOCALES = ['en', 'fr'];

    private Translator $translator;

    private string $self;

    /**
     * @var array<string, mixed> the request environment ($_SERVER)
     */
    private array $server;

    /**
     * The PHP session of the page, kept between requests; a reference to $_SESSION on the web.
     *
     * @var array<string, mixed>
     */
    private array $session;

    private ?Kernel $kernel = null;

    /**
     * @param array<string, mixed> $session
     * @param Closure(): Kernel $console starts Laravel with the .env file and returns its console kernel
     */
    public function __construct(
        private string $basePath,
        private SetupGuard $guard,
        private EnvironmentFile $environment,
        array &$session,
        private ?Closure $console = null,
    ) {
        $this->session = &$session;
    }

    /**
     * Answers a request.
     *
     * @param array<string, mixed> $query  the query string ($_GET)
     * @param array<string, mixed> $post   the posted form ($_POST)
     * @param array<string, mixed> $server the request environment ($_SERVER)
     */
    public function handle(string $method, array $query, array $post, array $server): SetupResponse
    {
        $this->self = $server['SCRIPT_NAME'] ?? '/setup.php';
        $this->server = $server;
        $this->translator = $this->translator($query, $server);

        if ($this->guard->isLocked()) {
            $this->session = [];
            return $this->render('locked', [], 403);
        }

        try {
            $this->guard->ensureKey();
        } catch (RuntimeException $e) {
            return $this->error(
                'The setup page cannot write the file setup.key at the root of Agorakit. Make that folder writable by the web server.',
                500
            );
        }

        $this->session['csrf'] ??= bin2hex(random_bytes(16));

        if ($method === 'POST' && !hash_equals($this->session['csrf'], (string) ($post['_token'] ?? ''))) {
            return $this->error('This form has expired. Load the page again.', 419);
        }

        if (empty($this->session['authenticated'])) {
            return $method === 'POST' && ($post['action'] ?? '') === 'key'
                ? $this->checkKey($post)
                : $this->render('key');
        }

        if ($method === 'POST') {
            return match ($post['action'] ?? '') {
                'environment' => $this->saveEnvironment((array) ($post['env'] ?? [])),
                'migrate' => $this->migrate(),
                'admin' => $this->createAdmin($post),
                'lock' => $this->lock(),
                default => $this->redirect(),
            };
        }

        return match ($query['step'] ?? '') {
            'environment' => $this->environmentForm(),
            default => $this->overview(),
        };
    }

    /**
     * Where the installation stands, and what to do next.
     */
    private function overview(): SetupResponse
    {
        $configured = $this->environment->exists();
        $databaseError = $configured ? EnvironmentForm::databaseError($this->environment->values()) : null;
        $ready = $configured && $databaseError === null;

        $pending = null;
        $applicationError = null;
        $admins = null;
        if ($ready) {
            try {
                $pending = $this->pendingMigrations();
                $admins = $pending === [] ? $this->adminCount() : null;
            } catch (Throwable $e) {
                $applicationError = $e->getMessage();
            }
        }

        $adminForm = $this->session['admin_form'] ?? ['values' => [], 'errors' => []];
        unset($this->session['admin_form']);

        $output = $this->session['output'] ?? null;
        unset($this->session['output']);

        // the address a web cron service calls, once the .env file has its token
        $values = $configured ? $this->environment->values() : [];
        $cronUrl = ($values['CRON_TOKEN'] ?? '') !== '' && ($values['APP_URL'] ?? '') !== ''
            ? rtrim($values['APP_URL'], '/') . '/cron.php?token=' . $values['CRON_TOKEN']
            : null;

        return $this->render('overview', [
            'checks' => (new Requirements($this->basePath, $this->server))->check(),
            'configured' => $configured,
            'databaseError' => $databaseError,
            'ready' => $ready,
            'applicationError' => $applicationError,
            'pending' => $pending,
            'admins' => $admins,
            'adminForm' => $adminForm,
            'output' => $output,
            'cronUrl' => $cronUrl,
            'cronFile' => $this->basePath . '/public/cron.php',
        ]);
    }

    /**
     * Laravel, started with the .env file. Only once the configuration works.
     */
    private function console(): Kernel
    {
        if (!$this->kernel) {
            if (!$this->console) {
                throw new RuntimeException('The setup page cannot start Laravel.');
            }
            $this->kernel = ($this->console)();
        }

        return $this->kernel;
    }

    /**
     * Names of the migrations not run yet, all of them in an empty database.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $this->console();
        $migrator = Container::getInstance()->make('migrator');
        $paths = array_merge($migrator->paths(), [$this->basePath . '/database/migrations']);
        $files = array_keys($migrator->getMigrationFiles($paths));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_diff($files, $ran));
    }

    private function adminCount(): int
    {
        $this->console();

        return \App\User::where('admin', 1)->count();
    }

    /**
     * Creates the first administrator of the site.
     *
     * Agorakit makes the first account that registers an administrator: on a
     * site open on the web, a visitor could register before its owner. The
     * setup page creates that account instead, before the site is in use.
     */
    private function createAdmin(array $post): SetupResponse
    {
        if ($this->adminCount() > 0) {
            $this->flash('error', 'An administrator account exists already.');
            return $this->redirect();
        }

        $values = [
            'name' => trim((string) ($post['name'] ?? '')),
            'email' => trim((string) ($post['email'] ?? '')),
        ];
        $password = (string) ($post['password'] ?? '');

        $errors = [];
        if ($values['name'] === '' || mb_strlen($values['name']) > 255) {
            $errors['name'] = 'Enter a name.';
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'The password needs at least 8 characters.';
        } elseif ($password !== (string) ($post['password_confirmation'] ?? '')) {
            $errors['password'] = 'The two passwords differ.';
        }

        if (!$errors) {
            $user = new \App\User();
            $user->name = $values['name'];
            $user->email = $values['email'];
            $user->password = \Illuminate\Support\Facades\Hash::make($password);
            $user->verified = 1;
            $user->admin = 1;
            if ($user->save()) {
                $this->flash('success', 'The administrator account is created: sign in on the site with it.');
                return $this->redirect();
            }
            $errors['email'] = $user->getErrors()->first() ?: 'The account cannot be created.';
        }

        // shown again on the overview, the password excepted
        $this->session['admin_form'] = ['values' => $values, 'errors' => $errors];

        return $this->redirect();
    }

    /**
     * Runs the migrations, as "php artisan migrate --force" does, and shows what it printed.
     */
    private function migrate(): SetupResponse
    {
        try {
            $status = $this->console()->call('migrate', ['--force' => true]);
            $this->session['output'] = trim($this->console()->output());
        } catch (Throwable $e) {
            $this->flash('error', 'The migrations failed: :error', ['error' => $e->getMessage()]);
            return $this->redirect();
        }

        $status === 0
            ? $this->flash('success', 'The database is up to date.')
            : $this->flash('error', 'The migrations failed: :error', ['error' => 'exit code ' . $status]);

        return $this->redirect();
    }

    /**
     * The form that writes the .env file.
     *
     * @param array<string, string> $values values to show instead of the current ones
     * @param array<string, string> $errors translation keys of the errors, by option
     */
    private function environmentForm(array $values = [], array $errors = [], ?string $error = null): SetupResponse
    {
        $form = $this->environmentFormFor();
        $kept = [];
        foreach (EnvironmentSchema::fields() as $key => $field) {
            $kept[$key] = $field['type'] === EnvironmentSchema::PASSWORD && $form->hasValue($key);
        }

        return $this->render('environment', [
            'sections' => EnvironmentSchema::sections(),
            'values' => $values + $form->values(),
            'errors' => $errors,
            'error' => $error,
            'kept' => $kept,
        ], $errors || $error ? 422 : 200);
    }

    /**
     * Checks the submitted options and the database, then writes the .env file.
     */
    private function saveEnvironment(array $input): SetupResponse
    {
        [$values, $errors] = $this->environmentFormFor()->submit($input);

        // passwords are never sent back to the browser
        $shown = $values;
        foreach (EnvironmentSchema::fields() as $key => $field) {
            if ($field['type'] === EnvironmentSchema::PASSWORD) {
                $shown[$key] = '';
            }
        }

        if ($errors) {
            return $this->environmentForm($shown, $errors, $this->t('Some options need to be corrected.'));
        }

        $databaseError = EnvironmentForm::databaseError($values);
        if ($databaseError !== null) {
            return $this->environmentForm(
                $shown,
                [],
                $this->t('The database cannot be reached with these options: :error', ['error' => $databaseError])
            );
        }

        try {
            $this->environment->write($values);
        } catch (RuntimeException | InvalidArgumentException $e) {
            return $this->environmentForm($shown, [], $this->t('The .env file cannot be written: :error', ['error' => $e->getMessage()]));
        }

        $this->flash('success', 'The configuration is saved in the .env file.');

        return $this->redirect();
    }

    private function environmentFormFor(): EnvironmentForm
    {
        return new EnvironmentForm($this->environment, $this->server, $this->translator->getLocale());
    }

    /**
     * A message for the next page.
     */
    private function flash(string $type, string $message, array $replace = []): void
    {
        $this->session['flash'][] = ['type' => $type, 'text' => $this->t($message, $replace)];
    }

    private function checkKey(array $post): SetupResponse
    {
        if (!$this->guard->checkKey((string) ($post['key'] ?? ''))) {
            // slows down guessing; the key is long enough not to be guessed anyway
            sleep(1);
            return $this->render('key', ['error' => $this->t('This is not the key written in setup.key.')], 403);
        }

        $this->session['authenticated'] = true;
        $response = $this->redirect();
        $response->regenerateSession = true;

        return $response;
    }

    private function lock(): SetupResponse
    {
        // After an upgrade, cached configuration, routes and compiled views would
        // still be those of the previous version: clear them, as "php artisan
        // optimize:clear" does. A site that cannot start yet has nothing cached.
        if ($this->environment->exists() && EnvironmentForm::databaseError($this->environment->values()) === null) {
            try {
                $this->console()->call('optimize:clear');
            } catch (Throwable $e) {
                // locking matters more than clearing the caches
            }
        }

        try {
            $this->guard->lock();
        } catch (RuntimeException $e) {
            return $this->error(
                'The setup page cannot write the file setup.lock at the root of Agorakit. Make that folder writable by the web server.',
                500
            );
        }

        $this->session = [];

        return $this->render('done');
    }

    /**
     * Back to the page, after an action.
     */
    private function redirect(): SetupResponse
    {
        return SetupResponse::redirect($this->self);
    }

    private function translator(array $query, array $server): Translator
    {
        if (in_array($query['lang'] ?? null, self::LOCALES, true)) {
            $this->session['locale'] = $query['lang'];
        }
        $locale = $this->session['locale'] ?? $this->preferredLocale((string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? ''));

        $translator = new Translator(new FileLoader(new Filesystem(), $this->basePath . '/resources/lang'), $locale);
        $translator->setFallback('en');

        return $translator;
    }

    /**
     * The first supported language of an Accept-Language header, English by default.
     */
    private function preferredLocale(string $header): string
    {
        $languages = [];
        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';q=', trim($part));
            $language = strtolower(substr(trim($pieces[0]), 0, 2));
            $quality = isset($pieces[1]) ? (float) $pieces[1] : 1.0;
            // equal qualities keep the order of the header
            $languages[] = [$quality, -$position, $language];
        }
        rsort($languages);

        foreach ($languages as [, , $language]) {
            if (in_array($language, self::LOCALES, true)) {
                return $language;
            }
        }

        return 'en';
    }

    /**
     * A page that only shows a message, translated.
     */
    private function error(string $message, int $status): SetupResponse
    {
        return $this->render('error', ['message' => $this->t($message)], $status);
    }

    private function t(string $key, array $replace = []): string
    {
        return $this->translator->get($key, $replace);
    }

    /**
     * Renders a view of resources/views/setup in the layout of the page.
     */
    private function render(string $view, array $data = [], int $status = 200): SetupResponse
    {
        $flash = $this->session['flash'] ?? [];
        unset($this->session['flash']);

        $data += [
            'self' => $this->self,
            'token' => $this->session['csrf'] ?? '',
            'locale' => $this->translator->getLocale(),
            't' => fn (string $key, array $replace = []) => $this->t($key, $replace),
            'e' => fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
        $data['flash'] = $flash;
        $data['content'] = $this->template($view, $data);

        return new SetupResponse($status, $this->template('layout', $data));
    }

    private function template(string $name, array $data): string
    {
        $file = $this->basePath . '/resources/views/setup/' . $name . '.php';

        return (static function (string $__file, array $__data): string {
            extract($__data);
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        })($file, $data);
    }
}
