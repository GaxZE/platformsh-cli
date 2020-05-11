<?php

namespace Platformsh\Cli\Command;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


abstract class ExtendedCommandBase extends CommandBase
{
    /**
     * Extend 'validateInput' method.
     *
     * @param InputInterface $input
     * @param bool           $envNotRequired
     * @param bool           $selectDefaultEnv
     * @param bool           $detectCurrent Whether to detect the project/environment from the current working directory.
     */
    protected function validateInput(InputInterface $input, $envNotRequired = false, $selectDefaultEnv = false, $detectCurrent = true)
    {
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Identify the project.
        if ($projectId !== null) {
            /** @var \Platformsh\Cli\Service\Identifier $identifier */
            $identifier = $this->getService('identifier');
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Load the project ID from an environment variable, if available.
        $envPrefix = $this->config()->get('service.env_prefix');
        if ($projectId === null && getenv($envPrefix . 'PROJECT')) {
            $projectId = getenv($envPrefix . 'PROJECT');
            $this->stdErr->writeln(sprintf(
                'Project ID read from environment variable %s: %s',
                $envPrefix . 'PROJECT',
                $projectId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        // Set the --app option.
        if ($input->hasOption('app') && !$input->getOption('app')) {
            // An app ID might be provided from the parsed project URL.
            if (isset($result) && isset($result['appId'])) {
                $input->setOption('app', $result['appId']);
                $this->debug(sprintf(
                    'App name identified as: %s',
                    $input->getOption('app')
                ));
            }
            // Or from an environment variable.
            elseif (getenv($envPrefix . 'APPLICATION_NAME')) {
                $input->setOption('app', getenv($envPrefix . 'APPLICATION_NAME'));
                $this->stdErr->writeln(sprintf(
                    'App name read from environment variable %s: %s',
                    $envPrefix . 'APPLICATION_NAME',
                    $input->getOption('app')
                ), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        // Select the project.
        $this->selectProject($projectId, $projectHost, $detectCurrent);

        // Select the environment.
        $envOptionName = 'environment';
        if ($input->hasArgument($this->envArgName)
            && $input->getArgument($this->envArgName) !== null
            && $input->getArgument($this->envArgName) !== []) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName) !== null) {
                throw new ConsoleInvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        $this->envArgName,
                        $envOptionName
                    )
                );
            }
            $argument = $input->getArgument($this->envArgName);
            if (is_array($argument) && count($argument) == 1) {
                $argument = $argument[0];
            }
            if (!is_array($argument)) {
                $this->debug('Selecting environment based on input argument');
                $this->selectEnvironment($argument, true, $selectDefaultEnv, $detectCurrent);
            }
        } elseif ($input->hasOption($envOptionName)) {
            if ($input->getOption($envOptionName) !== null) {
                $environmentId = $input->getOption($envOptionName);
            }
            $this->selectEnvironment($environmentId, !$envNotRequired, $selectDefaultEnv, $detectCurrent);
        }
    }

    /**
     * Extend addProjectOption() so that the 'title' argument is present whenever
     * the '--project' option is.
     *
     * @return $this
     */
    protected function addProjectOption()
    {
        parent::addProjectOption();
        $this->addArgument('title', InputArgument::OPTIONAL, "The project title, or part of it.");
        return $this;
    }

    /**
     * Several of our bespoke commands rely on this argument.
     *
     * @return $this
     */
    protected function addDirectoryArgument()
    {
        $this->addArgument('directory', InputArgument::OPTIONAL, 'The directory where the project lives locally.');
        return $this;
    }


    /**
     * Build a MySQL-safe slug for project-app.
     */
    protected function getSlug(Project $project, LocalApplication $app)
    {
        // Make sure settings.local.php is up to date for the project.
        $slugify = new Slugify();
        return str_replace('-', '_', ($project->title ?
            $slugify->slugify($project->title) :
            $project->id) . '-' . ($slugify->slugify($app->getId())));
    }

    private function expandTilde($path)
    {
        if (function_exists('posix_getuid') && strpos($path, '~') !== FALSE) {
            $info = posix_getpwuid(posix_getuid());
            $path = str_replace('~', $info['dir'], $path);
        }
        return $path;
    }
}
