<?php
namespace PhpBrew\Tasks;

use RuntimeException;
use PhpBrew\CommandBuilder;
use PhpBrew\Config;
use PhpBrew\VariantBuilder;
use PhpBrew\Build;


/**
 * Task to run `make`
 */
class ConfigureTask extends BaseTask
{
    public $optimizationLevel;

    public function setLogPath($path)
    {
        $this->logPath = $path;
    }

    public function setOptimizationLevel($optimizationLevel)
    {
        $this->optimizationLevel = $optimizationLevel;
    }

    public function configure(Build $build)
    {
        $variantBuilder = new VariantBuilder;
        $extra = $build->getExtraOptions();

        if (!file_exists( $build->getSourceDirectory() . DIRECTORY_SEPARATOR . 'configure')) {
            $this->debug("configure file not found, running buildconf script...");
            $lastline = system('./buildconf');
            if ($lastline !== false) {
                throw new RuntimeException("buildconf error: $lastline", 1);
            }
        }

        $prefix = $build->getInstallPrefix();

        // append cflags
        if ($this->optimizationLevel) {
            $o = $this->optimizationLevel;
            $cflags = getenv('CFLAGS');
            putenv("CFLAGS=$cflags -O$o");
            $_ENV['CFLAGS'] = "$cflags -O$o";
        }

        $args = array();
        $args[] = "--prefix=" . $prefix;
        $args[] = "--with-config-file-path={$prefix}/etc";
        $args[] = "--with-config-file-scan-dir={$prefix}/var/db";
        $args[] = "--with-pear={$prefix}/lib/php";

        $variantOptions = $variantBuilder->build($build);

        if ($variantOptions) {
            $args = array_merge($args, $variantOptions);
        }

        $this->debug('Enabled variants: ' . join(', ', array_keys($build->getVariants())));
        $this->debug('Disabled variants: ' . join(', ', array_keys($build->getDisabledVariants())));

        foreach ((array) $this->options->patch as $patchPath) {
            // copy patch file to here
            $this->info("===> Applying patch file from $patchPath ...");

            // Search for strip parameter
            for ($i = 0; $i <= 16; $i++) {
                ob_start();
                system("patch -p$i --dry-run < $patchPath", $return);
                ob_end_clean();

                if ($return === 0) {
                    system("patch -p$i < $patchPath");
                    break;
                }
            }
        }

        // let's apply patch for libphp{php version}.so (apxs)
        if ($build->isEnabledVariant('apxs2')) {
            $apxs2Checker = new \PhpBrew\Tasks\Apxs2CheckTask($this->logger);
            $apxs2Checker->check($build, $this->options);

            $apxs2Patch = new \PhpBrew\Tasks\Apxs2PatchTask($this->logger);
            $apxs2Patch->patch($build, $this->options);
        }

        foreach ($extra as $a) {
            $args[] = $a;
        }

        $cmd = new CommandBuilder('./configure');
        $cmd->args($args);

        $buildLogPath = $build->getBuildLogPath();
        if (file_exists($buildLogPath)) {
            $newPath = $buildLogPath . '.' . filemtime($buildLogPath);
            $this->info("Found existing build.log, renaming it to $newPath");
            rename($buildLogPath,$newPath);
        }

        $this->info("===> Configuring {$build->version}...");
        $cmd->setAppendLog(true);
        $cmd->setLogPath($buildLogPath);

        $this->logger->info("\n");
        $this->logger->info("Use tail command to see what's going on:");
        $this->logger->info("   $ tail -f $buildLogPath\n\n");

        $this->debug($cmd->getCommand());

        if ($this->options->nice) {
            $cmd->nice($this->options->nice);
        }

        if (!$this->options->dryrun) {
            $code = $cmd->execute();
            if ($code != 0)
                throw new RuntimeException("Configure failed. $code", 1);
                
        }

        if (!$this->options->{'no-patch'}) {
            $patch64bit = new \PhpBrew\Tasks\Patch64BitSupportTask($this->logger, $this->options);
            if ($patch64bit->match($build)) {
                $patch64bit->patch($build);
            }
        }
        $build->setState(Build::STATE_CONFIGURE);
    }
}
