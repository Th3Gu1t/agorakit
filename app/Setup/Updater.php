<?php

namespace App\Setup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * Updates the code of Agorakit to a newer release, for the setup page: on hosts
 * without SSH, the alternative is to send thousands of files through FTP.
 *
 * Release archives record, in their VERSION file, their version and the GitHub
 * repository they were built from. The update takes the latest release of that
 * repository, in three steps, each a request of its own:
 *
 * 1. prepare: downloads the archive and its SHA-256 checksum into
 *    storage/framework/update, unpacks it there and checks it, without
 *    changing the installation: checksum, paths, version and repository of the
 *    archive, PHP version it needs, folders that must be written to;
 * 2. apply: puts the site in maintenance and moves the release into place.
 *    Each entry of the archive replaces the one of the installation, which
 *    moves to a backup folder: folders as a whole (app, vendor...), so no file
 *    of the previous version stays behind, by renaming, so it takes seconds.
 *    If a move fails, the moves already made are undone;
 * 3. finish: when the setup page is locked, the site goes back online, the
 *    backup becomes storage/framework/update/previous, kept until the next
 *    update, and the downloaded files are deleted.
 *
 * What the release does not ship is never touched: .env, setup.lock and
 * setup.key, files added to the installation, links such as public/storage.
 * The root folder and public/ are merged entry by entry for that reason, and
 * storage/, which holds the data of the site (uploaded files, sessions,
 * cache, logs), only receives what it lacks.
 */
class Updater
{
    /**
     * Folders merged entry by entry: they hold files the release does not ship.
     */
    private const MERGED = ['public'];

    /**
     * Folders the update only adds to: the data of the site.
     */
    private const DATA = ['storage'];

    private string $dir;

    private MaintenanceMode $maintenance;

    public function __construct(private string $basePath, private HttpClient $http, ?MaintenanceMode $maintenance = null)
    {
        $this->dir = $basePath . '/storage/framework/update';
        $this->maintenance = $maintenance ?? new MaintenanceMode($basePath);
        // apply() may need them while app/ is moved away: load them now
        class_exists(UpdateException::class);
    }

    /**
     * The version, commit and repository of the installed release, or null outside a release archive.
     *
     * @return array{version: string, commit: string, repository: string}|null
     */
    public function installed(): ?array
    {
        return self::release($this->basePath);
    }

    /**
     * Why updates cannot run on this installation, or null if they can.
     */
    public function unavailable(): ?UpdateException
    {
        $installed = $this->installed();
        if ($installed === null) {
            return new UpdateException('This installation does not come from a release archive: it has no VERSION file.');
        }
        if (!self::isRepository($installed['repository'])) {
            return new UpdateException(
                'Updates come from the releases of a GitHub repository; this installation was built from :repository.',
                ['repository' => $installed['repository']]
            );
        }
        if (!class_exists(ZipArchive::class)) {
            return new UpdateException('The zip extension of PHP is needed to unpack the archive.');
        }
        if (!HttpClient::available()) {
            return new UpdateException('PHP cannot make HTTPS requests on this server: it needs the curl extension or allow_url_fopen.');
        }

        return null;
    }

    /**
     * Whether the site is in maintenance, as apply() leaves it until finish().
     */
    public function inMaintenance(): bool
    {
        return $this->maintenance->isOn();
    }

    /**
     * The tag of the latest release of the repository.
     *
     * @throws UpdateException
     */
    public function latest(): string
    {
        $repository = $this->repository();
        $location = $this->http->location($repository . '/releases/latest');
        // without a release, GitHub redirects to the list of releases instead
        if ($location === null || !preg_match('#/releases/tag/([^/?\#]+)$#', $location, $match)) {
            throw new UpdateException('The repository :repository has no release.', ['repository' => $repository]);
        }
        $tag = rawurldecode($match[1]);
        self::checkTag($tag);

        return $tag;
    }

    /**
     * Whether a release is newer than the installed one.
     */
    public function isNewer(string $tag): bool
    {
        $installed = ltrim($this->installed()['version'] ?? '', 'v');
        // an archive built by hand, named "test" for instance: any release is newer
        if (!preg_match('/^\d+(\.\d+)*/', $installed)) {
            return true;
        }

        return version_compare(ltrim($tag, 'v'), $installed, '>');
    }

    /**
     * The page of a release on GitHub, with its notes.
     */
    public function notesUrl(string $tag): string
    {
        return $this->repository() . '/releases/tag/' . rawurlencode($tag);
    }

    /**
     * The update in progress, prepared or applied, or null.
     *
     * @return array{tag: string, from: string, php: string, applied: bool, maintenance?: bool}|null
     */
    public function state(): ?array
    {
        $json = @file_get_contents($this->dir . '/state.json');
        $state = $json === false ? null : json_decode($json, true);

        return is_array($state) && isset($state['tag'], $state['applied']) ? $state : null;
    }

    /**
     * Downloads a release, unpacks it in storage/framework/update and checks it.
     *
     * @return array{tag: string, from: string, php: string, applied: bool}
     * @throws UpdateException when the release cannot be installed here; nothing is left behind
     */
    public function prepare(string $tag): array
    {
        if ($reason = $this->unavailable()) {
            throw $reason;
        }
        if ($this->state()['applied'] ?? false) {
            throw new UpdateException('An update is installed but not finished: lock the setup page first.');
        }
        self::checkTag($tag);
        @set_time_limit(600);

        $this->discard();
        $this->makeDirectory($this->dir);

        try {
            $name = 'agorakit-' . $tag;
            $zip = $this->dir . '/' . $name . '.zip';
            $url = $this->repository() . '/releases/download/' . rawurlencode($tag) . '/' . $name . '.zip';
            $this->http->download($url, $zip);
            $this->http->download($url . '.sha256', $zip . '.sha256');
            $this->checkChecksum($zip);

            $staged = $this->extract($zip, $name);
            $this->checkRelease($staged, $tag);
            $php = $this->requiredPhp($staged, $tag);
            $this->checkWritable($staged);
        } catch (Throwable $e) {
            $this->discard();
            throw $e instanceof UpdateException
                ? $e
                : new UpdateException('The update cannot be prepared: :error', ['error' => $e->getMessage()]);
        }

        $state = ['tag' => $tag, 'from' => $this->installed()['version'], 'php' => $php, 'applied' => false];
        $this->writeState($state);

        return $state;
    }

    /**
     * Puts the site in maintenance and moves the prepared release into place.
     *
     * @throws UpdateException when the release cannot be moved into place; the previous version is then put back
     */
    public function apply(): void
    {
        $state = $this->state();
        if ($state === null || $state['applied']) {
            throw new UpdateException('No update is ready to install.');
        }

        $staged = $this->dir . '/staging/agorakit-' . $state['tag'];
        $backup = $this->dir . '/backup';
        $this->removeTree($backup);
        $this->makeDirectory($backup);
        $plan = $this->plan($staged);

        $wasInMaintenance = $this->maintenance->isOn();
        $this->maintenance->on();

        /** @var list<array{string, bool, bool}> $moves path, saved to the backup, moved into place */
        $moves = [];
        try {
            foreach ($plan as $path) {
                $target = $this->basePath . '/' . $path;
                $saved = file_exists($target) || is_link($target);
                if ($saved) {
                    $this->makeDirectory(dirname($backup . '/' . $path));
                    $this->move($target, $backup . '/' . $path);
                }
                $moves[] = [$path, $saved, false];
                $this->makeDirectory(dirname($target));
                $this->move($staged . '/' . $path, $target);
                $moves[count($moves) - 1][2] = true;
            }
        } catch (Throwable $e) {
            $problems = $this->rollback($moves, $staged, $backup);
            if (!$wasInMaintenance) {
                $this->maintenance->off();
            }
            $error = $e instanceof UpdateException ? $e : $e->getMessage();
            if ($problems) {
                throw new UpdateException(
                    'The update failed: :error. These entries could not be put back: :paths. The previous version is in :folder.',
                    ['error' => $error, 'paths' => implode(', ', $problems), 'folder' => $backup]
                );
            }
            throw new UpdateException('The update failed, and the previous version is back in place: :error', ['error' => $error]);
        }

        $state['applied'] = true;
        $state['maintenance'] = !$wasInMaintenance;
        $this->writeState($state);

        // the PHP files changed under the same paths: do not serve the compiled previous ones
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Ends an update when the setup page is locked: the site goes back online, the
     * previous version is kept until the next update, the downloads are deleted.
     * Also drops an update that was prepared but not installed.
     */
    public function finish(): void
    {
        $state = $this->state();
        if ($state !== null && $state['applied']) {
            if (!empty($state['maintenance'])) {
                $this->maintenance->off();
            }
            $previous = $this->dir . '/previous';
            $this->removeTree($previous);
            @rename($this->dir . '/backup', $previous);
        }
        $this->discard(true);
    }

    /**
     * Deletes a prepared update that is not installed.
     */
    public function discard(bool $evenApplied = false): void
    {
        if (!$evenApplied && ($this->state()['applied'] ?? false)) {
            return;
        }
        $this->removeTree($this->dir . '/staging');
        $this->removeTree($this->dir . '/backup');
        foreach (glob($this->dir . '/agorakit-*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->dir . '/state.json');
    }

    /**
     * The VERSION file of a release: version, commit, repository.
     *
     * @return array{version: string, commit: string, repository: string}|null
     */
    private static function release(string $root): ?array
    {
        $lines = @file($root . '/VERSION', FILE_IGNORE_NEW_LINES) ?: [];
        if (count($lines) < 3) {
            return null;
        }

        return ['version' => trim($lines[0]), 'commit' => trim($lines[1]), 'repository' => trim($lines[2])];
    }

    /**
     * Whether releases can be fetched from this repository: a GitHub repository over
     * HTTPS. A repository on this machine is accepted too, for tests.
     */
    private static function isRepository(string $url): bool
    {
        if (preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $url)) {
            return true;
        }

        return (bool) preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $url);
    }

    private static function checkTag(string $tag): void
    {
        if (!preg_match('/^v\d+(\.\d+){1,3}(-[0-9A-Za-z.]+)?$/', $tag)) {
            throw new UpdateException('This is not a release tag: :tag', ['tag' => $tag]);
        }
    }

    private function repository(): string
    {
        return $this->installed()['repository'] ?? '';
    }

    private function checkChecksum(string $zip): void
    {
        $line = (string) @file_get_contents($zip . '.sha256');
        if (!preg_match('/^([0-9a-f]{64})\s+\*?(\S+)/', $line, $match) || $match[2] !== basename($zip)) {
            throw new UpdateException('The checksum file of the archive cannot be read.');
        }
        if (!hash_equals($match[1], (string) hash_file('sha256', $zip))) {
            throw new UpdateException('The archive does not match its checksum: the download is damaged, try again.');
        }
    }

    /**
     * Unpacks the archive into storage/framework/update/staging, once every path in it is checked.
     *
     * @return string the folder of the release
     */
    private function extract(string $zip, string $name): string
    {
        $archive = new ZipArchive();
        if ($archive->open($zip) !== true) {
            throw new UpdateException('The archive cannot be opened.');
        }

        try {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $entry = (string) $archive->getNameIndex($i);
                $segments = explode('/', $entry);
                if (
                    !str_starts_with($entry, $name . '/')
                    || str_contains($entry, '\\')
                    || str_contains($entry, "\0")
                    || in_array('..', $segments, true)
                ) {
                    throw new UpdateException('The archive holds an unexpected path: :path', ['path' => $entry]);
                }
            }

            $staging = $this->dir . '/staging';
            $this->makeDirectory($staging);
            if (!$archive->extractTo($staging)) {
                throw new UpdateException('The archive cannot be unpacked into :folder.', ['folder' => $staging]);
            }
        } finally {
            $archive->close();
        }

        return $staging . '/' . $name;
    }

    private function checkRelease(string $staged, string $tag): void
    {
        $release = self::release($staged);
        $repository = $this->repository();
        if ($release === null || $release['version'] !== $tag || $release['repository'] !== $repository) {
            throw new UpdateException('The archive is not the release :tag of :repository.', ['tag' => $tag, 'repository' => $repository]);
        }

        foreach (['artisan', 'bootstrap/app.php', 'public/index.php', 'public/setup.php', 'vendor/autoload.php'] as $file) {
            if (!is_file($staged . '/' . $file)) {
                throw new UpdateException('The archive is incomplete: :file is missing.', ['file' => $file]);
            }
        }
    }

    /**
     * The PHP version the release needs, as the check Composer runs at every request tells it.
     */
    private function requiredPhp(string $staged, string $tag): string
    {
        $check = (string) @file_get_contents($staged . '/vendor/composer/platform_check.php');
        $required = preg_match('/PHP_VERSION_ID >= (\d+)/', $check, $match) ? (int) $match[1] : 0;
        $version = intdiv($required, 10000) . '.' . intdiv($required % 10000, 100) . '.' . $required % 100;

        if (PHP_VERSION_ID < $required) {
            throw new UpdateException(
                'Version :tag needs PHP :required or later; this server runs PHP :current. '
                . 'Choose a newer PHP version in the control panel of your host first.',
                ['tag' => $tag, 'required' => $version, 'current' => PHP_VERSION]
            );
        }

        return $version;
    }

    /**
     * Checks, before anything moves, that every move apply() will make can be made.
     */
    private function checkWritable(string $staged): void
    {
        foreach ([$this->basePath, $this->basePath . '/public', $this->dir] as $folder) {
            if (!is_writable($folder)) {
                throw new UpdateException('The folder :folder cannot be written to.', ['folder' => $folder]);
            }
        }
        foreach ($this->plan($staged) as $path) {
            $target = $this->basePath . '/' . $path;
            // moving a folder to another parent rewrites its link to the parent
            if (is_dir($target) && !is_link($target) && !is_writable($target)) {
                throw new UpdateException('The folder :folder cannot be written to.', ['folder' => $target]);
            }
            if (is_dir(dirname($target)) && !is_writable(dirname($target))) {
                throw new UpdateException('The folder :folder cannot be written to.', ['folder' => dirname($target)]);
            }
        }

        // the moves are renames: storage/ must be on the same disk as the rest
        $probe = $this->dir . '/move-check';
        $moved = $this->basePath . '/.agorakit-update-check';
        @file_put_contents($probe, '');
        $ok = @rename($probe, $moved);
        @unlink($probe);
        @unlink($moved);
        if (!$ok) {
            throw new UpdateException('Files cannot be moved from :from to :to.', ['from' => $this->dir, 'to' => $this->basePath]);
        }
    }

    /**
     * The entries of the release to move into place, relative to the release folder.
     *
     * @return list<string>
     */
    private function plan(string $staged, string $relative = ''): array
    {
        $plan = [];
        foreach ($this->entries($staged . ($relative === '' ? '' : '/' . $relative)) as $name) {
            $path = ltrim($relative . '/' . $name, '/');
            $source = $staged . '/' . $path;
            if (in_array($path, self::DATA, true)) {
                $plan = array_merge($plan, $this->missing($staged, $path));
            } elseif (in_array($path, self::MERGED, true) && is_dir($source) && !is_link($source)) {
                $plan = array_merge($plan, $this->plan($staged, $path));
            } else {
                $plan[] = $path;
            }
        }

        return $plan;
    }

    /**
     * The entries of a data folder of the release that the installation lacks.
     *
     * @return list<string>
     */
    private function missing(string $staged, string $path): array
    {
        $target = $this->basePath . '/' . $path;
        if (!file_exists($target) && !is_link($target)) {
            return [$path];
        }
        if (!is_dir($staged . '/' . $path) || !is_dir($target)) {
            return [];
        }

        $missing = [];
        foreach ($this->entries($staged . '/' . $path) as $name) {
            $missing = array_merge($missing, $this->missing($staged, $path . '/' . $name));
        }

        return $missing;
    }

    /**
     * Undoes the moves of apply(), last first.
     *
     * @param list<array{string, bool, bool}> $moves
     * @return list<string> the moves that could not be undone
     */
    private function rollback(array $moves, string $staged, string $backup): array
    {
        $problems = [];
        foreach (array_reverse($moves) as [$path, $saved, $movedIn]) {
            $target = $this->basePath . '/' . $path;
            if ($movedIn && !@rename($target, $staged . '/' . $path)) {
                $problems[] = $path;
                continue;
            }
            if ($saved && !@rename($backup . '/' . $path, $target)) {
                $problems[] = $path;
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function entries(string $folder): array
    {
        $entries = @scandir($folder) ?: [];

        return array_values(array_diff($entries, ['.', '..']));
    }

    private function move(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new UpdateException('Files cannot be moved from :from to :to.', ['from' => $from, 'to' => $to]);
        }
    }

    private function makeDirectory(string $folder): void
    {
        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new UpdateException('The folder :folder cannot be written to.', ['folder' => $folder]);
        }
    }

    /**
     * Deletes a folder and what it holds, without following links.
     */
    private function removeTree(string $folder): void
    {
        if (is_link($folder) || is_file($folder)) {
            @unlink($folder);
            return;
        }
        if (!is_dir($folder)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($folder);
    }

    private function writeState(array $state): void
    {
        if (@file_put_contents($this->dir . '/state.json', json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new UpdateException('Cannot write :file.', ['file' => $this->dir . '/state.json']);
        }
    }
}
