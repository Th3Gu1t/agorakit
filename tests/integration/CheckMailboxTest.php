<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;

/**
 * agorakit:checkmailbox runs every minute: when post by email is off, it must stop before connecting to
 * anything.
 */
class CheckMailboxTest extends BrowserKitTestCase
{
    private function checkMailbox(array $settings): int
    {
        config(array_merge([
            'agorakit.inbox_driver' => 'imap',
            'agorakit.inbox_host' => 'imap.example.org',
            'agorakit.inbox_username' => 'inbox',
            'agorakit.inbox_password' => 'secret',
        ], $settings));

        return Artisan::call('agorakit:checkmailbox');
    }

    public function testDisabledWhenNoDriverIsSet()
    {
        $status = $this->checkMailbox(['agorakit.inbox_driver' => null]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Email checking disabled in settings', Artisan::output());
    }

    public function testDisabledWhenNoHostIsSet()
    {
        $status = $this->checkMailbox(['agorakit.inbox_host' => null]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Email checking disabled in settings', Artisan::output());
    }
}
