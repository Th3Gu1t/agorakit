<?php

namespace Tests;

use App\Setup\EnvironmentFile;
use App\Setup\EnvironmentSchema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The .env file the setup page writes.
 */
class SetupEnvironmentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agorakit-env-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function file(): EnvironmentFile
    {
        return new EnvironmentFile($this->dir . '/.env');
    }

    public function testValuesReadBackExactlyAsWritten()
    {
        $values = [
            'APP_NAME' => 'Les Amis du "Quartier" & co',
            'DB_PASSWORD' => 'p@ss w#rd $HOME \\ \' end',
            'MAIL_FROM_NAME' => 'Été à Liège',
            'MAIL_USERNAME' => 'user=name',
            'MAPBOX_TOKEN' => '',
            'APP_URL' => 'https://agora.example.org/sub',
        ];

        $this->file()->write($values);

        $read = $this->file()->values();
        foreach ($values as $key => $value) {
            $this->assertSame($value, $read[$key], $key);
        }
    }

    public function testEveryOptionOfTheFormIsWritten()
    {
        $this->file()->write(['APP_NAME' => 'Agorakit']);

        $read = $this->file()->values();
        foreach (array_keys(EnvironmentSchema::fields()) as $key) {
            $this->assertArrayHasKey($key, $read);
        }
    }

    public function testKeyIsGeneratedOnceThenKept()
    {
        $this->file()->write(['APP_NAME' => 'First']);
        $key = $this->file()->values()['APP_KEY'];
        $this->assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7))));

        $this->file()->write(['APP_NAME' => 'Second']);

        $this->assertSame($key, $this->file()->values()['APP_KEY']);
    }

    public function testUnknownOptionsAreKeptAndTheOldFileBackedUp()
    {
        file_put_contents($this->dir . '/.env', "APP_NAME=Old\nAPP_KEY=base64:kept\nCUSTOM_OPTION=\"kept value\"\n");

        $this->file()->write(['APP_NAME' => 'New']);

        $read = $this->file()->values();
        $this->assertSame('New', $read['APP_NAME']);
        $this->assertSame('base64:kept', $read['APP_KEY']);
        $this->assertSame('kept value', $read['CUSTOM_OPTION']);
        $this->assertStringContainsString('APP_NAME=Old', file_get_contents($this->dir . '/.env.backup'));
    }

    public function testLineBreaksCannotBeWritten()
    {
        $this->expectException(InvalidArgumentException::class);

        EnvironmentFile::quote("first line\nSECOND=injected");
    }

    public function testFormCoversTheOptionsOfTheExampleFile()
    {
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', file_get_contents(dirname(__DIR__, 2) . '/.env.example'), $matches);
        $expected = array_filter(
            array_unique($matches[1]),
            fn ($key) => $key !== 'APP_KEY' && !str_starts_with($key, 'TESTING_')
        );

        $missing = array_diff($expected, array_keys(EnvironmentSchema::fields()));

        $this->assertSame([], array_values($missing), 'Options of .env.example missing from the setup page');
    }
}
