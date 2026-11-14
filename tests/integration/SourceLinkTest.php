<?php

namespace Tests;

/**
 * The link to the source code of the instance, required by the AGPL for a modified version.
 */
class SourceLinkTest extends BrowserKitTestCase
{
    public function testFooterLinksToTheSourceCodeWhenSet()
    {
        config(['agorakit.source_url' => 'https://github.com/example/agorakit']);

        $this->visit('/login')
            ->seeLink(__('Source code'), 'https://github.com/example/agorakit');
    }

    public function testNoLinkWithoutAnAddress()
    {
        config(['agorakit.source_url' => null]);

        $this->visit('/login')
            ->dontSeeLink(__('Source code'));
    }
}
