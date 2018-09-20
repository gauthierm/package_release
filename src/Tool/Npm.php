<?php

namespace Silverorange\PackageRelease\Tool;

use Symfony\Component\Console\Output\OutputInterface;
use Silverorange\PackageRelease\Console\ProcessRunner;

/**
 * @package   PackageRelease
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2018 silverorange
 * @license   http://www.opensource.org/licenses/mit-license.html MIT License
 */
class Npm
{
    public static function install(OutputInterface $output): bool
    {
        $command = (static::isYarn())
            ? 'yarn install --silent'
            : 'npm install --no-package-lock --quiet';

        return (new ProcessRunner(
            $output,
            $command,
            'installing npm dependencies',
            'installed npm dependencies',
            'failed to install npm dependencies'
        ))->run();
    }

    public static function build(OutputInterface $output): bool
    {
        $command = (static::isYarn())
            ? 'yarn build --silent'
            : 'npm build --quiet';

        return (new ProcessRunner(
            $output,
            $command,
            'building project',
            'built project',
            'failed to build project'
        ))->run();
    }

    public static function version(
        OutputInterface $output,
        string $version
    ): bool {
        $command = 'npm '
            . '--no-shrinkwrap'
            . '--no-git-tag-version '
            . '--quiet '
            . 'version '
            . escapeshellarg($version);

        return (new ProcessRunner(
            $output,
            $command,
            'setting package version',
            'set package version',
            'failed to set package version'
        ))->run();
    }

    public static function publish(
        OutputInterface $output,
        string $directory
    ): bool {
        $command = 'npm '
        . 'publish '
        . '--quiet '
        . escapeshellarg($directory);

        return (new ProcessRunner(
            $output,
            $command,
            'publishing package',
            'published package',
            'failed to publish package'
        ))->run();
    }

    protected static function isYarn(): bool
    {
        return file_exists('yarn.lock');
    }
}
