<?php

namespace App\Setup;

/**
 * The options of the .env file the setup page lets people set, grouped in
 * sections. Labels and help texts are translation keys.
 *
 * Every option of .env.example is here, except APP_KEY, which the page
 * generates, and the TESTING_* options, which only serve the test suite.
 * A test keeps the two files in step.
 */
class EnvironmentSchema
{
    public const TEXT = 'text';
    public const URL = 'url';
    public const EMAIL = 'email';
    public const NUMBER = 'number';
    public const PASSWORD = 'password';
    public const SELECT = 'select';
    public const BOOLEAN = 'boolean';

    /**
     * Languages Agorakit is translated into, as in config/app.php, by their own names.
     */
    public const LOCALES = [
        'en' => 'English',
        'fr' => 'Français',
        'nl' => 'Nederlands',
        'de' => 'Deutsch',
        'es' => 'Español',
        'it' => 'Italiano',
        'ru' => 'Русский',
        'eo' => 'Esperanto',
    ];

    /**
     * @return list<array{title: string, help?: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return [
            [
                'title' => 'Site',
                'fields' => [
                    ['key' => 'APP_NAME', 'label' => 'Name of the site', 'type' => self::TEXT, 'default' => 'Agorakit', 'required' => true],
                    [
                        'key' => 'APP_URL', 'label' => 'Address of the site', 'type' => self::URL, 'required' => true,
                        'help' => 'For instance https://agora.example.org, without a slash at the end.',
                    ],
                    [
                        'key' => 'SOURCE_URL', 'label' => 'Address of the source code', 'type' => self::URL,
                        'help' => 'Agorakit is under the AGPL licence: the visitors of a modified version must be able '
                            . 'to get its source code. The footer of the site links to this address.',
                    ],
                    [
                        'key' => 'APP_DEFAULT_LOCALE', 'label' => 'Default language', 'type' => self::SELECT, 'default' => 'en',
                        'options' => self::LOCALES,
                        'help' => 'Used when the language of a visitor cannot be detected.',
                    ],
                    [
                        'key' => 'TIME_ZONE', 'label' => 'Time zone', 'type' => self::TEXT, 'default' => 'Europe/Brussels', 'required' => true,
                        'help' => 'For instance Europe/Paris.',
                    ],
                    [
                        'key' => 'APP_ENV', 'label' => 'Environment', 'type' => self::SELECT, 'default' => 'production',
                        'options' => ['production' => 'production', 'local' => 'local'],
                        'help' => 'production for a site in use.',
                    ],
                    [
                        'key' => 'APP_DEBUG', 'label' => 'Show detailed errors', 'type' => self::BOOLEAN, 'default' => 'false',
                        'help' => 'Only to investigate a problem, then turn it off: errors would show details of the configuration to visitors.',
                    ],
                    [
                        'key' => 'APP_LOG', 'label' => 'Log files', 'type' => self::SELECT, 'default' => 'daily',
                        'options' => ['daily' => 'daily', 'single' => 'single'],
                        'help' => 'daily writes one file per day, single always the same file.',
                    ],
                ],
            ],
            [
                'title' => 'Database',
                'help' => 'Create the database and its user in the control panel of your host first.',
                'fields' => [
                    [
                        'key' => 'DB_CONNECTION', 'label' => 'Kind of database', 'type' => self::SELECT, 'default' => 'mysql',
                        'options' => ['mysql' => 'MySQL / MariaDB'],
                    ],
                    ['key' => 'DB_HOST', 'label' => 'Server', 'type' => self::TEXT, 'default' => 'localhost', 'required' => true],
                    ['key' => 'DB_PORT', 'label' => 'Port', 'type' => self::NUMBER, 'default' => '3306', 'required' => true],
                    ['key' => 'DB_DATABASE', 'label' => 'Name of the database', 'type' => self::TEXT, 'required' => true],
                    ['key' => 'DB_USERNAME', 'label' => 'User', 'type' => self::TEXT, 'required' => true],
                    ['key' => 'DB_PASSWORD', 'label' => 'Password', 'type' => self::PASSWORD],
                ],
            ],
            [
                'title' => 'Sending emails',
                'help' => 'Agorakit sends emails to confirm accounts, send login links, summaries and reminders.',
                'fields' => [
                    [
                        'key' => 'MAIL_DRIVER', 'label' => 'Method', 'type' => self::SELECT, 'default' => 'smtp',
                        'options' => [
                            'smtp' => 'SMTP',
                            'sendmail' => 'sendmail',
                            'mail' => 'PHP mail()',
                            'log' => 'log (no email sent, for tests)',
                        ],
                        'help' => 'SMTP with the mail server of your host is the most reliable.',
                    ],
                    ['key' => 'MAIL_HOST', 'label' => 'SMTP server', 'type' => self::TEXT],
                    ['key' => 'MAIL_PORT', 'label' => 'SMTP port', 'type' => self::NUMBER, 'default' => '587'],
                    [
                        'key' => 'MAIL_ENCRYPTION', 'label' => 'SMTP encryption', 'type' => self::SELECT, 'default' => 'tls',
                        'options' => ['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'null' => 'none'],
                    ],
                    ['key' => 'MAIL_USERNAME', 'label' => 'SMTP user', 'type' => self::TEXT],
                    ['key' => 'MAIL_PASSWORD', 'label' => 'SMTP password', 'type' => self::PASSWORD],
                    [
                        'key' => 'MAIL_FROM', 'label' => 'Sender address', 'type' => self::EMAIL, 'required' => true,
                        'help' => 'An address of your domain, so that emails are not taken for spam.',
                    ],
                    ['key' => 'MAIL_FROM_NAME', 'label' => 'Sender name', 'type' => self::TEXT, 'default' => 'Agorakit'],
                    [
                        'key' => 'MAIL_NOREPLY', 'label' => 'No-reply address', 'type' => self::EMAIL, 'required' => true,
                        'help' => 'Sender of the notifications nobody should answer.',
                    ],
                ],
            ],
            [
                'title' => 'Receiving emails (optional)',
                'help' => 'Lets members post in a group by email. Needs the PHP extension imap and the package ddeboer/imap, '
                    . 'which the release archive does not include.',
                'fields' => [
                    [
                        'key' => 'INBOX_DRIVER', 'label' => 'Method', 'type' => self::SELECT, 'default' => 'null',
                        'options' => ['null' => 'Disabled', 'imap' => 'IMAP'],
                    ],
                    ['key' => 'INBOX_HOST', 'label' => 'IMAP server', 'type' => self::TEXT],
                    ['key' => 'INBOX_PORT', 'label' => 'IMAP port', 'type' => self::NUMBER, 'default' => '993'],
                    ['key' => 'INBOX_FLAGS', 'label' => 'IMAP flags', 'type' => self::TEXT, 'default' => '/imap/ssl/novalidate-cert'],
                    ['key' => 'INBOX_USERNAME', 'label' => 'IMAP user', 'type' => self::TEXT],
                    ['key' => 'INBOX_PASSWORD', 'label' => 'IMAP password', 'type' => self::PASSWORD],
                    [
                        'key' => 'INBOX_PREFIX', 'label' => 'Address prefix', 'type' => self::TEXT,
                        'help' => 'Group addresses are prefix + group + suffix.',
                    ],
                    ['key' => 'INBOX_SUFFIX', 'label' => 'Address suffix', 'type' => self::TEXT],
                ],
            ],
            [
                'title' => 'Other settings',
                'fields' => [
                    [
                        'key' => 'MAX_FILE_SIZE', 'label' => 'Largest file members can upload, in kilobytes',
                        'type' => self::NUMBER, 'default' => '10000',
                    ],
                    [
                        'key' => 'DATA_RETENTION', 'label' => 'Days deleted content is kept before it is erased',
                        'type' => self::NUMBER, 'default' => '30',
                    ],
                    ['key' => 'MAPBOX_TOKEN', 'label' => 'Mapbox token (optional)', 'type' => self::TEXT, 'help' => 'Places addresses on maps.'],
                    [
                        'key' => 'ONLYOFFICE_URL', 'label' => 'OnlyOffice server (optional)', 'type' => self::URL,
                        'help' => 'Lets members edit office documents online.',
                    ],
                    [
                        'key' => 'CACHE_DRIVER', 'label' => 'Cache', 'type' => self::SELECT, 'default' => 'file',
                        'options' => ['file' => 'file', 'database' => 'database'],
                    ],
                    [
                        'key' => 'SESSION_DRIVER', 'label' => 'Sessions', 'type' => self::SELECT, 'default' => 'file',
                        'options' => ['file' => 'file', 'database' => 'database'],
                    ],
                    [
                        'key' => 'QUEUE_DRIVER', 'label' => 'Queue', 'type' => self::SELECT, 'default' => 'sync',
                        'options' => ['sync' => 'sync', 'database' => 'database'],
                        'help' => 'sync sends emails at once, without a worker process.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Every field, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        $fields = [];
        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }
}
