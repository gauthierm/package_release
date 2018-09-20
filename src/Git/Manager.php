<?php

namespace Silverorange\PackageRelease\Git;

/**
 * @package   PackageRelease
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2016-2018 silverorange
 * @license   http://www.opensource.org/licenses/mit-license.html MIT License
 */
class Manager
{
    const VERSION_MAJOR = 'major';
    const VERSION_MINOR = 'minor';
    const VERSION_PATCH = 'patch';
    const VERSION_MICRO = 'micro'; // deprecated alias for VERSION_PATCH

    /**
     * @var string
     */
    protected $last_error = [];

    /**
     * Gets the command output of the last failed command
     *
     * @return string
     */
    public function getLastError(): array
    {
        return $this->last_error;
    }

    /**
     * Checks if the current directory is a git repository
     *
     * @return boolean true if the current directory is a git repository,
     *                 otherwise false.
     */
    public function isInGitRepo(): bool
    {
        $package_git_repo = `git rev-parse --is-inside-work-tree 2>/dev/null`;
        return (trim($package_git_repo) === 'true');
    }

    /**
     * Checks if the current directory is a composer package
     *
     * @return boolean true if the current directory is a composer package,
     *                 otherwise false.
     */
    public function isComposerPackage(): bool
    {
        return (file_exists('composer.json') && is_readable('composer.json'));
    }

    /**
     * Checks if the current directory is an NPM package
     *
     * @return boolean true if the current directory is an NPM package,
     *                 otherwise false.
     */
    public function isNpmPackage(): bool
    {
        return (
            !$this->isComposerPackage()
            && file_exists('package.json')
            && is_readable('package.json')
        );
    }

    /**
     * Gets the name of the current git repository
     *
     * @return string the name of the current git repository or null if it
     *                could not be determined.
     */
    public function getRepoName(): string
    {
        $repo = null;

        $remotes = explode(PHP_EOL, `git remote -v`);
        $matches = array();
        $exp = '/^origin\s+.*\/([a-zA-Z0-9_-]+)\.git\s+\((?:fetch|push)\)$/';
        foreach ($remotes as $remote) {
            if (preg_match($exp, $remote, $matches) === 1) {
                $repo = $matches[1];
                break;
            }
        }

        return $repo;
    }

    /**
     * Gets the name of the current Git branch
     *
     * @return string the name of the current Git branch.
     */
    public function getCurrentBranch(): string
    {
        return trim(`git rev-parse --abbrev-ref HEAD`);
    }

    /**
     * Gets the name of the current composer package
     *
     * @return string the name of the current composer package or null if the
     *                name could not be parsed from the composer.json file.
     */
    public function getComposerPackageName(): string
    {
        $name = null;

        $data = json_decode('composer.json', true);
        if (isset($data['name'])) {
            $name = $data['name'];
        }

        return $name;
    }

    /**
     * Gets a git remote's name based on its URL
     *
     * @param string $url the git URL of the remote.
     *
     * @return string the remote name, or null if no such remote exists in the
     *                git repository.
     */
    public function getRemoteByUrl(string $url)
    {
        $the_remote = null;

        $remotes = explode(PHP_EOL, `git remote`);
        foreach ($remotes as $remote) {
            $escaped_remote = escapeshellarg($remote);
            $info = explode(PHP_EOL, `git remote show -n $escaped_remote`);
            $expression = sprintf('/^  Fetch URL: %s/', preg_quote($url, '/'));
            if (preg_match($expression, $info[1]) === 1) {
                $the_remote = $remote;
                break;
            }
        }

        return $the_remote;
    }

    /**
     * Creates a release branch based on a remote branch name
     *
     * @param string $parent  the parent branch name.
     * @param string $remote  the remote repository name.
     * @param string $version the release version.
     *
     * @return string the name of the new branch, or null if the branch could
     *                not be created.
     */
    public function createReleaseBranch(
        string $parent,
        string $remote,
        string $version,
        string $prefix = 'release'
    ) {
        $release = $prefix . '-' . str_replace('.', '-', $version);

        $escaped_remote = escapeshellarg($remote);
        $escaped_parent = escapeshellarg($parent);
        $escaped_release = escapeshellarg($release);

        // Fetch only the parent branch from the remote.
        $fetch_command = sprintf(
            'git fetch -q %1$s %2$s:refs/remotes/%1$s/%2$s 2>&1',
            $escaped_remote,
            $escaped_parent
        );

        $output = array();
        $return = 0;
        exec($fetch_command, $output, $return);
        if ($return === 0) {
            $checkout_command = sprintf(
                'git checkout -q -b %s %s/%s 2>&1',
                $escaped_release,
                $escaped_remote,
                $escaped_parent
            );

            $output = array();
            $return = 0;
            exec($checkout_command, $output, $return);

            if ($return !== 0) {
                $this->last_error = $output;
                $release = null;
            }
        } else {
            $this->last_error = $output;
            $release = null;
        }

        return $release;
    }

    /**
     * Commits a file to the current branch
     *
     * @param string $filename the file to commit.
     * @param string $message  the commit message to use.
     *
     * @return boolean true on success, otherwise false.
     */
    public function commitFile(
        string $filename,
        string $message
    ) {
        $escaped_filename = escapeshellarg($filename);
        $escaped_message = escapeshellarg($message);

        $fetch_command = sprintf(
            'git commit %s -m %s 2>&1',
            $escaped_filename,
            $escaped_message
        );

        $output = array();
        $return = 0;
        exec($fetch_command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
            $release = null;
        }

        return $release;
    }

    /**
     * Merges a branch from a remote repository into the current branch
     *
     * @param string $branch the branch to merge.
     * @param string $remote the remote repository name.
     *
     * @return boolean true on success, otherwise false.
     */
    public function mergeRemoteBranch(string $branch, string $remote): bool
    {
        $escaped_remote = escapeshellarg($remote);
        $escaped_branch = escapeshellarg($branch);

        // Fetch only the branch from the remote.
        $fetch_command = sprintf(
            'git fetch -q %1$s %2$s:refs/remotes/%1$s/%2$s 2>&1',
            $escaped_remote,
            $escaped_branch
        );

        $output = array();
        $return = 0;
        exec($fetch_command, $output, $return);
        if ($return !== 0) {
            $this->last_error = $output;
            return false;
        }

        $merge_command = sprintf(
            'git merge -q -b %s/%s 2>&1',
            $escaped_remote,
            $escaped_branch
        );

        $output = array();
        $return = 0;
        exec($merge_command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
            return false;
        }

        return true;
    }

    /**
     * Creates a release tag from the current branch
     *
     * @param string $version the release version.
     * @param string $message the release message.
     *
     * @return true on success, false on failure.
     */
    public function createReleaseTag(string $version, string $message): bool
    {
        $escaped_version = escapeshellarg($version);
        $escaped_message = escapeshellarg($message);

        $command = sprintf(
            'git tag -a %s -m %s 2>&1',
            $escaped_version,
            $escaped_message
        );

        $output = array();
        $return = 0;
        exec($command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
        }

        return ($return === 0);
    }

    /**
     * Pushes tag to remote
     *
     * @param string $tag    the name of the tag to push.
     * @param string $remote the name of the remote to push to.
     *
     * @return boolean true on success, false on failure.
     */
    public function pushTagToRemote(string $tag, string $remote): bool
    {
        $escaped_tag = escapeshellarg($tag);
        $escaped_remote = escapeshellarg($remote);

        $command = sprintf(
            'git push -q %s %s 2>&1',
            $escaped_remote,
            $escaped_tag
        );

        $output = array();
        $return = 0;
       // exec($command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
        }

        return ($return === 0);
    }

    /**
     * Pushes a local branch to a remote branch
     *
     * @param string $source_branch      the name of the branch to push from.
     * @param string $destination_branch the name of the branch to push to.
     * @param string $remote             the name of the remote to push to.
     *
     * @return boolean true on success, false on failure.
     */
    public function pushBranchToRemote(
        string $source_branch,
        string $destination_branch,
        string $remote
    ): bool {
        $escaped_source = escapeshellarg($source_branch);
        $escaped_destination = escapeshellarg($destination_branch);
        $escaped_remote = escapeshellarg($remote);

        $command = sprintf(
            'git push -q %s %s:%s 2>&1',
            $escaped_remote,
            $escaped_source,
            $escaped_destination
        );

        $output = array();
        $return = 0;
       // exec($command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
        }

        return ($return === 0);
    }

    /**
     * Exports a tag to a local directory
     *
     * @param string $tag       the name of the tag to export.
     * @param string $directory the directory in which to export the tag.
     *
     * @return boolean true on success, false on failure.
     */
    public function exportTag(string $tag, string $directory): bool
    {
        $directory = rtrim($directory, '/');
        $escaped_tag = escapeshellarg($tag);
        $escaped_directory = escapeshellarg($directory);

        if (file_exists($directory)) {
            $this->last_error = 'Directory ' . $directory . ' must not exist.';
            return false;
        }

        if (!mkdir($directory, 0777, true)) {
            $this->last_error = 'Could not create export directory: '
                . $directory;

            return false;
        }

        $command = 'git-archive '
            . '--format=tar '
            . '--prefix=' . $escaped_directory . '/ '
            . $escaped_tag
            . ' | tar -xf -';

        $output = array();
        $return = 0;
        exec($command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
        }

        return ($return === 0);
    }

    /**
     * Deletes a branch
     *
     * @param string $branch the name of the branch to delete.
     *
     * @return boolean true on success, false on failure.
     */
    public function deleteBranch(string $branch): bool
    {
        if ($branch === 'master') {
            // can't delete master
            return false;
        }

        $escaped_branch = escapeshellarg($branch);

        `git checkout -q @{-1}`;

        $command = sprintf('git branch -D %s 2>&1', $escaped_branch);
        $output = array();
        $return = 0;
        exec($command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
        }

        return ($return === 0);
    }

    /**
     * Gets the most recent version tag from the specified remote
     *
     * @param string $remote the name of the remote.
     *
     * @return string the most recent version tag, or 0.0.0 if no release
     *                exists.
     */
    public function getCurrentVersionFromRemote(string $remote): string
    {
        $remote = escapeshellarg($remote);

        // get remote tags
        $tags = `git ls-remote --tags --refs $remote`;
        $tags = explode(PHP_EOL, $tags);

        // filter out version rows and strip out commit ids
        $tags = array_filter(
            array_map(
                function ($line) {
                    $matches = array();
                    preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', $line, $matches);

                    if (count($matches) === 2) {
                        return $matches[1];
                    }

                    return null;
                },
                $tags
            ),
            function ($line) {
                return ($line !== null);
            }
        );

        // sort by version number
        usort(
            $tags,
            'version_compare'
        );

        if (count($tags) === 0) {
            $tag = '0.0.0';
        } else {
            // get last tag
            $tag = end($tags);
        }

        return $tag;
    }

    /**
     * Recursively removes a directory
     *
     * @param string $directory the directory to remove.
     *
     * @return boolean true on success, false on failure.
     */
    public function removeDirectory(string $directory): bool
    {
        $directory = rtrim($directory, '/');
        $escaped_directory = escapeshellarg($directory);

        $command = 'rm -rf ' . $escaped_directory;

        $output = array();
        $return = 0;
        exec($command, $output, $return);

        if ($return !== 0) {
            $this->last_error = $output;
        }

        return ($return === 0);
    }

    /**
     * Gets the next version for a release based on the specified version and
     * release type
     *
     * @param string $current_version the version of the current release.
     * @param string $type            optional. The release type. Should be
     *                                one of
     *                                {@link PackageRelease::VERSION_MAJOR},
     *                                {@link PackageRelease::VERSION_MINOR}, or
     *                                {@link PackageRelease::VERSION_PATCH}. If
     *                                not specified, a minor release is
     *                                used.
     *
     * @return string the next release version.
     */
    public function getNextVersion(
        string $current_version,
        string $type = self::VERSION_MINOR
    ): string {
        $parts = explode('.', $current_version);

        if (count($parts) !== 3) {
            $next = '0.1.0';
        } else {
            switch ($type) {
                case self::VERSION_MAJOR:
                    $next = ($parts[0] + 1) . '.0.0';
                    break;
                case self::VERSION_PATCH:
                case self::VERSION_MICRO:
                    $next = $parts[0] . '.' . $parts[1] . '.' . ($parts[2] + 1);
                    break;
                case self::VERSION_MINOR:
                default:
                    $next = $parts[0] . '.' . ($parts[1] + 1) . '.0';
                    break;
            }
        }

        return $next;
    }
}
