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
        if ($input->hasArgument('directory')) {
            if ($directory = $input->getArgument('directory')) {
                $this->setProjectRoot($directory);
            }
            if (!($project = $this->getCurrentProject())) {
                $this->stdErr->writeln("\n<error>No project found at " . (!empty($directory) ?
                    $directory : getcwd()) . "</error>");
                return 1;
            }
        }

        if ($input->hasOption('environment')) {
            if (!$input->getOption('environment')) {
                $envNotRequired = TRUE;
            }
        }

        parent::validateInput($input, $envNotRequired);

        // Some config.
        $this->profilesRootDir = $this->expandTilde($this->config()->get('local.drupal.profiles_dir'));
        $this->sitesRootDir = $this->expandTilde($this->config()->get('local.drupal.sites_dir'));
        $this->selectEnvironment($this->config()->get('local.deploy.remote_environment'));
        $this->extCurrentProject['internal_site_code'] = $this->getSelectedEnvironment()->getVariable($this->config()->get('local.deploy.internal_site_code_variable'))->value;

        if (!($root = $this->getProjectRoot())) {
            $root = $this->sitesRootDir . '/' . $this->extCurrentProject['internal_site_code'];
        }
        if (file_exists($root) && is_dir($root)) {
            // The 'currentProject' array is defined as a protected attribute of the
            // base class ExtendedCommandBase.
            $this->extCurrentProject['root_dir'] = $root;
            // Set more info about the current project.
            $this->extCurrentProject['legacy'] = $this->getService('local.project')->getLegacyProjectRoot() !== FALSE;
            $this->extCurrentProject['repository_dir'] = $this->extCurrentProject['legacy'] ?
                $this->extCurrentProject['root_dir'] . '/repository' :
                $this->extCurrentProject['root_dir'];
            $this->extCurrentProject['www_dir'] = $this->extCurrentProject['legacy'] ?
                $this->extCurrentProject['root_dir'] . '/www' :
                $this->extCurrentProject['root_dir'] . '/_www';
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
