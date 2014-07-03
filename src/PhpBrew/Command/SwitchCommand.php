<?php
namespace PhpBrew\Command;

class SwitchCommand extends VirtualCommand
{

    public function arguments($args) {
        $args->add('installed php')
            ->validValues('PhpBrew\\Config::getInstalledPhpVersions')
            ;
    }


    public function brief()
    {
        return 'switch default php version.';
    }
}
