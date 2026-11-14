<?php

namespace App\Setup;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use RuntimeException;

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
     * The PHP session of the page, kept between requests; a reference to $_SESSION on the web.
     *
     * @var array<string, mixed>
     */
    private array $session;

    /**
     * @param array<string, mixed> $session
     */
    public function __construct(private string $basePath, private SetupGuard $guard, array &$session)
    {
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
                'lock' => $this->lock(),
                default => $this->redirect(),
            };
        }

        return $this->render('overview');
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
