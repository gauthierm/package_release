<?php

namespace Silverorange\PackageRelease\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Silverorange\PackageRelease\Git\Manager;
use Silverorange\PackageRelease\Console\Formatter\Style;
use Silverorange\PackageRelease\Console\Formatter\LineWrapper;
use Silverorange\PackageRelease\Console\Formatter\OutputFormatter as PackageReleaseOutputFormatter;
use Silverorange\PackageRelease\Console\Question\ConfirmationPrompt;
use Silverorange\PackageRelease\Tool\Npm;

/**
 * @package   PackageRelease
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2017-2018 silverorange
 * @license   http://www.opensource.org/licenses/mit-license.html MIT License
 */
class PackageReleaseCommand extends Command
{
    /**
     * @var Silverorange\PackageRelease\Manager
     */
    protected $manager = null;

    /**
     * @var Silverorange\PackageRelease\Style
     */
    protected $style = null;

    public function __construct(Manager $manager, Style $style)
    {
        parent::__construct();
        $this->setManager($manager);
        $this->setStyle($style);
    }

    public function setManager(Manager $manager): self
    {
        $this->manager = $manager;
        return $this;
    }

    public function setStyle(Style $style): self
    {
        $this->style = $style;
        return $this;
    }

    protected function configure(): void
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('package-release')

            // the short description shown while running "php bin/console list"
            ->setDescription(
                'Releases new versions of Composer or NPM packages.'
            )

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp(
                'This tool is used to release new versions of Composer or NPM '
                . 'packages. It uses Semver 2.0 to automatically pick the '
                . 'next version number and tag the release on GitHub.'
            )
            ->setDefinition(
                new InputDefinition(array(
                    new InputOption(
                        'branch',
                        'b',
                        InputOption::VALUE_REQUIRED,
                        'Remote branch to use for release.',
                        'master'
                    ),
                    new InputOption(
                        'message',
                        'm',
                        InputOption::VALUE_REQUIRED,
                        'Message to use for the release tag.'
                    ),
                    new InputOption(
                        'type',
                        't',
                        InputOption::VALUE_REQUIRED,
                        'Release type. Must be one of "major", "minor", or '
                        . '"patch". Semver 2.0 (https://semver.org/) is used '
                        . 'to pick the next release number.',
                        'minor'
                    ),
                ))
            );
        ;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $this->validateInputOptions($input, $output);

        // Use custom OutputFormatter that works with mbstring function
        // overloading enabled.
        $formatter = $output->getFormatter();
        $output->setFormatter(
            new PackageReleaseOutputFormatter($formatter->isDecorated())
        );

        $this->style->execute($input, $output);

        if (!$this->manager->isInGitRepo()) {
            $output->writeln([
                'This tool must be run from a git repository.',
                '',
            ]);
            return 1;
        }

        if (!$this->manager->isComposerPackage()
            && !$this->manager->isNpmPackage()
        ) {
            $output->writeln([
                'Could not find <variable>composer.json</variable> or '
                . '<variable>package.json</variable>. Make sure you are in '
                . ' the project root and the project is a Composer or '
                . ' NPM package.',
                '',
            ]);
            return 1;
        }

        $repo_name = $this->manager->getRepoName();
        if ($repo_name === null) {
            $output->writeln([
                'Could not find git repository name. Git repository '
                . 'must have a remote named <variable>origin</variable>.',
                '',
            ]);
            return 1;
        }

        $remote_url = sprintf(
            'git@github.com:silverorange/%s.git',
            $repo_name
        );
        $remote = $this->manager->getRemoteByUrl($remote_url);
        if ($remote === null) {
            $output->writeln([
                sprintf(
                    'Could not find silverorange remote. A remote with the '
                    . 'URL <variable>%s</variable> must exist.',
                    OutputFormatter::escape($remote_url)
                ),
                ''
            ]);
            return 1;
        }

        $current_version = $this->manager->getCurrentVersionFromRemote(
            $remote
        );
        if ($current_version === '0.0.0') {
            $output->writeln([
                '<tip>No existing release. Next release will be first '
                . 'release.</tip>',
                '',
            ]);
        }

        $next_version = $this->manager->getNextVersion(
            $current_version,
            $input->getOption('type')
        );

        // Prompt to continue release.
        if ($input->isInteractive() && !$output->isQuiet()) {
            $prompt = new ConfirmationPrompt($this->getHelper('question'));
            $continue = $prompt->ask(
                $input,
                $output,
                sprintf(
                    'Ready to release new %s version <variable>%s</variable>. '
                    . 'Continue? <prompt>[Y/N]</prompt>',
                    $input->getOption('type'),
                    OutputFormatter::escape($next_version)
                )
            );

            if (!$continue) {
                $output->writeln([
                    '<bold>Got it. Not releasing.</bold>',
                    ''
                ]);
                return 0;
            }
        }

        $output->writeln([
            sprintf(
                '<header>Releasing version %s:</header>',
                OutputFormatter::escape($next_version)
            ),
            '',
        ]);

        $branch = $input->getOption('branch');
        $this->startCommand($output);
        $release_branch = $this->manager->createReleaseBranch(
            $branch,
            $remote,
            $next_version
        );
        if ($release_branch === null) {
            $this->handleError(
                $output,
                sprintf(
                    'could not create release branch from '
                    . '<variable>%s</variable>',
                    OutputFormatter::escape($branch)
                ),
                $this->manager->getLastError()
            );
            return 1;
        } else {
            $this->handleSuccess(
                $output,
                sprintf(
                    'created release branch <variable>%s</variable>',
                    OutputFormatter::escape($release_branch)
                )
            );
        }

        // Update package.json and commit to current branch.
        if ($this->manager->isNpmPackage()) {
            if (!Npm::version($output, $next_version)) {
                return 1;
            }
        }

        $message = $input->getOption('message');
        if ($message === '') {
            $message = sprintf(
                'Release version %s.',
                $next_version
            );
        }
        $this->startCommand($output);
        $success = $this->manager->createReleaseTag(
            $next_version,
            $message
        );
        if ($success) {
            $this->handleSuccess(
                $output,
                sprintf(
                    'tagged release with message <variable>%s</variable>',
                    OutputFormatter::escape($message)
                )
            );
        } else {
            $this->handleError(
                $output,
                sprintf(
                    'failed to create release tag for <variable>%s</variable>',
                    OutputFormatter::escape($next_version)
                ),
                $this->manager->getLastError()
            );
            return 1;
        }

        $this->startCommand($output);
        if ($this->manager->pushTagToRemote($next_version, $remote)) {
            $this->handleSuccess(
                $output,
                sprintf(
                    'pushed tag to <variable>%s</variable>',
                    OutputFormatter::escape($remote)
                )
            );
        } else {
            $this->handleError(
                $output,
                sprintf(
                    'could not push tag <variable>%s</variable> to remote '
                    . '<variable>%s</variable>',
                    OutputFormatter::escape($next_version),
                    OutputFormatter::escape($remote)
                ),
                $this->manager->getLastError()
            );
            return 1;
        }

        if ($this->manager->isNpmPackage()) {
            $this->startCommand($output);

            $destination_branch = 'master';
            $success = $this->manager->pushBranchToRemote(
                $release_branch,
                $destination_branch,
                $remote
            );

            if ($success) {
                $this->handleSuccess(
                    $output,
                    sprintf(
                        'pushed release branch <variable>%s</variable> to '
                        . '<variable>%s:%s</variable>',
                        OutputFormatter::escape($release_branch),
                        OutputFormatter::escape($remote),
                        OutputFormatter::escape($destination_branch)

                    )
                );
            } else {
                $this->handleError(
                    $output,
                    sprintf(
                        'could not push release branch <variable>%s</variable> '
                        . 'to <variable>%s:%s</variable>',
                        OutputFormatter::escape($release_branch),
                        OutputFormatter::escape($remote),
                        OutputFormatter::escape($destination_branch)
                    ),
                    $this->manager->getLastError()
                );
                return 1;
            }
        }

        $this->startCommand($output);
        if ($this->manager->deleteBranch($release_branch)) {
            $this->handleSuccess(
                $output,
                sprintf(
                    'removed release branch <variable>%s</variable>',
                    OutputFormatter::escape($release_branch)
                )
            );
        } else {
            $this->handleError(
                $output,
                sprintf(
                    'could not delete release branch <variable>%s</variable>',
                    OutputFormatter::escape($release_branch)
                ),
                $this->manager->getLastError()
            );
            return 1;
        }

        if ($this->manager->isNpmPackage()) {
            $this->startCommand($output);

            $export_directory = $release_branch . '-export';
            if ($this->manager->exportTag($next_version, $export_directory)) {
                $this->handleSuccess(
                    $output,
                    sprintf(
                        'exported release tag to '
                        . '<variable>%s</variable>',
                        OutputFormatter::escape($export_directory)

                    )
                );
            } else {
                $this->handleError(
                    $output,
                    sprintf(
                        'could not export release tag to '
                        . '<variable>%s</variable>',
                        OutputFormatter::escape($export_directory)
                    ),
                    $this->manager->getLastError()
                );
                return 1;
            }

            if (!Npm::publish($output, export_directory)) {
                return 1;
            }

            $this->startCommand($output);
            if ($this->manager->removeDirectory($export_directory)) {
                $this->handleSuccess(
                    $output,
                    sprintf(
                        'removed export directory <variable>%s</variable>',
                        OutputFormatter::escape($export_directory)

                    )
                );
            } else {
                $this->handleError(
                    $output,
                    sprintf(
                        'could not remove export directory '
                        . '<variable>%s</variable>',
                        OutputFormatter::escape($export_directory)
                    ),
                    $this->manager->getLastError()
                );
                return 1;
            }
        }

        $output->writeln([
            '',
            '<info>Success!</info>',
            '',
        ]);

        if ($this->isNpmPackage()) {
            $output->writeln([
                'The NPM registry is updated and the release will appear at '
                . '<link>https://npmrepository/</link>.',
                ''
            ]);
        } else {
            $output->writeln([
                'The composer repository will automatically update. It may '
                . 'take a few minutes for the release to appear at '
                . '<link>https://composer.silverorange.com/</link>.',
                ''
            ]);
        }
    }

    protected function validateInputOptions(
        InputInterface $input,
        OutputInterface $output
    ): void {
        $type = $input->getOption('type');

        if (!in_array($type, ['major', 'minor', 'patch', 'micro'])) {
            throw new InvalidOptionException(
                sprintf(
                    'Option "type" must be one of the following: "major", '
                    . '"minor", "patch" (got "%s").',
                    $type
                )
            );
        }
    }

    protected function startCommand(OutputInterface $output): void
    {
        $output->write('<waiting>…</waiting> ');
    }

    protected function handleSuccess(
        OutputInterface $output,
        string $message
    ): void {
        $output->writeln(
            sprintf(
                "\r<success>✓</success> %s",
                $message
            )
        );
    }

    protected function handleError(
        OutputInterface $output,
        string $message,
        array $debug_output
    ): void {
        $output->writeln([
            sprintf(
                "\r<failure>✗</failure> %s",
                $message
            ),
            ''
        ]);

        $wrapped_lines = (new LineWrapper())->wrap($debug_output, 76, '  ');
        $output->writeln(array_map(function ($line) {
            return sprintf(
                '<output>%s</output>',
                OutputFormatter::escape($line)
            );
        }, $wrapped_lines));
        $output->writeln('');
    }
}
