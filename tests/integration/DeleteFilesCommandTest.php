<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;

/**
 * The definition of agorakit:deletefiles, without running it: it deletes files.
 */
class DeleteFilesCommandTest extends BrowserKitTestCase
{
    public function testForceIsAnOptionAndNoArgumentIsNeeded()
    {
        $definition = Artisan::all()['agorakit:deletefiles']->getDefinition();

        $this->assertTrue($definition->hasOption('force'));
        $this->assertSame(0, $definition->getArgumentRequiredCount());
    }
}
