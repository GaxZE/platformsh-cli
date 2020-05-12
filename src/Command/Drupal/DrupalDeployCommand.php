<?php

namespace Platformsh\Cli\Command\Drupal;

use Drush\Make\Parser\ParserIni;
use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DrupalDeployCommand extends ExtendedCommandBase
{

    protected function configure()
    {
        $this
            ->setName('drupal:deploy')
            ->setAliases(array('deploy'))
            ->setDescription('Deploy a Drupal Site locally')
            ->addOption('db-sync', 'd', InputOption::VALUE_NONE, "Sync project's database with the daily live backup.")
            ->addOption('core-branch', 'c', InputOption::VALUE_REQUIRED, "The core profile's branch to use during deployment")
            ->addOption('no-archive', "N", InputOption::VALUE_NONE, 'Do not create or use a build archive. Run \'platform help build\' for more info.')
            ->addOption('environment', 'e', InputOption::VALUE_OPTIONAL, "The environment ID to clone. Defaults to local:deploy:git_default_branch's value in the config.yaml")->addOption('no-deploy-hooks', 'D', InputOption::VALUE_NONE, 'Do not run deployment hooks (drush commands).')
            ->addOption('no-sanitize', 'S', InputOption::VALUE_NONE, 'Do not perform database sanitization.');
        $this->addAppOption();
        $this->addProjectOption();
        $this->addExample('Deploy Drupal project', '-p myproject123')
            ->addExample('Deploy Drupal project refreshing the database from the backup', '-d -p myproject123')
            ->addExample('Deploy Drupal project rereshing the database from the backup but skipping sanitization', '-d -S -p myproject123');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $this->stdErr->writeln("<info>[*]</info> Deployment started for <info>" . $project->getProperty('title') . "</info> ($project->id)");

        // If this directory does not exist, create it.
        if (!is_dir($this->profilesRootDir)) {
            mkdir($this->profilesRootDir);
        }

        // If this directory does not exist, create it.
        if (!is_dir($this->sitesRootDir)) {
            mkdir($this->sitesRootDir);
        }
        // The 'currentProject' array is defined as a protected attribute of the
        // base class ExtendedCommandBase.
        $this->extCurrentProject['root_dir'] = $this->sitesRootDir . '/' . $this->extCurrentProject['internal_site_code'];

        // If the project was never deployed before.
        if (!is_dir($this->extCurrentProject['root_dir'] . '/www') && !is_dir($this->extCurrentProject['root_dir'] . '/_www')) {
            $this->fetchSite($project, $input->getOption('environment'));
            echo "here";
            // DB sync is required the first time the project is deployed.
            $input->setOption('db-sync', InputOption::VALUE_NONE);
            $siteJustFetched = TRUE;
        }

        // Set project root.
        $this->setProjectRoot($this->extCurrentProject['root_dir']);

        // Set more info about the current project.
        $this->extCurrentProject['legacy'] = $this->getService('local.project')->getLegacyProjectRoot() !== FALSE;
        $this->extCurrentProject['repository_dir'] = $this->extCurrentProject['legacy'] ?
            $this->extCurrentProject['root_dir'] . '/repository' :
            $this->extCurrentProject['root_dir'];
        $this->extCurrentProject['www_dir'] = $this->extCurrentProject['legacy'] ?
            $this->extCurrentProject['root_dir'] . '/www' :
            $this->extCurrentProject['root_dir'] . '/_www';

        $apps = $input->getOption('app');
        var_dump($this->getProjectRoot());
        foreach (LocalApplication::getApplications($this->getProjectRoot(), $this->config()) as $app) {
            // If --app was specified, only allow those apps.
            if (!empty($apps) && !in_array($app->getId(), $apps)) {
                continue;
            }

            // Only work with Drupal apps.
            if ($app->getConfig()['build']['flavor'] != 'drupal') {
                continue;
            }

            // Check for profile info.
            $profile = $this->getProfileInfo($app);

            // // If the project refers to a profile and this was never fetched before.
            // if (is_array($profile) && (!is_dir($this->profilesRootDir . "/" . $profile['name']))) {
            //   $this->fetchProfile($profile);
            //   $profileJustFetched[$profile['name']] = TRUE;
            // }

            // // Update repositories, if not requested otherwise.
            // if (!$input->getOption('no-git-pull')) {
            //   if ($input->getOption('core-branch')) {
            //     $this->stdErr->writeln('<info>[*]</info> Ignoring local profile repository. Using remote with branch <info>' . $input->getOption('core-branch') . '</info>');
            //   }
            //   // If we are not to fetch from a remote core branch.
            //   else {
            //     if (is_array($profile) && empty($profileJustFetched[$profile['name']])) {
            //       $this->updateRepository($this->profilesRootDir . "/" . $profile['name']);
            //       // We only need to do this once for the project, not for every app;
            //       // in case more than one app uses the same profile.
            //       $profileJustFetched[$profile['name']] = TRUE;
            //     }
            //   }
            //   // Update site repo only if the site has not just been fetched
            //   // for the first time.
            //   if (!$siteJustFetched) {
            //     // GitHub integration can be activated any time, so we must be ready
            //     // to apply it.
            //     $this->checkGitHubIntegration();
            //     // Update the repo.
            //     $this->updateRepository($this->extCurrentProject['repository_dir'], $input->getOption('environment'));
            //     // We only need to do this once for the project, not for every app;
            //     // all apps are in the same repository.
            //     $siteJustFetched = TRUE;
            //   }
        }
    }

    /**
     * Fetches a site for the first time.
     * @param $project
     * @throws \Exception
     */
    private function fetchSite(Project $project, $env = NULL)
    {
        $this->stdErr->writeln("<info>[*]</info> Fetching <info>" . $project->getProperty('title') . "</info> (" . $project->id . ") for the first time...");
        $this->runOtherCommand('project:get', [
            '--yes' => TRUE,
            '--environment' => $this->config()->get('local.deploy.git_default_branch'),
            'project' => $project->id,
            'directory' => $this->extCurrentProject['root_dir'],
        ]);
        if (!empty($env)) {
            /** @var $git GitHelper */
            $git = $this->getService('git');
            $git->ensureInstalled();
            $git->execute(array('reset', '--hard'), $this->extCurrentProject['root_dir']);
            chdir($this->extCurrentProject['root_dir']);
            $this->runOtherCommand('environment:checkout', ['id' => $env]);
        }
    }

    /**
     * Get information on the profile used by the project, if one is specified.
     */
    private function getProfileInfo(LocalApplication $app)
    {
        $makefile = $app->getRoot() . '/project.make';

        if (file_exists($makefile)) {
            $ini = new ParserIni();
            $makeData = $ini->parse(file_get_contents($makefile));
            foreach ($makeData['projects'] as $projectName => $projectInfo) {
                if (($projectInfo['type'] == 'profile') && ($projectInfo['download']['type'] == 'git' || ($projectInfo['download']['type'] == 'copy'))) {
                    $p = $projectInfo['download'];
                    $p['name'] = $projectName;
                    unset($p['type']);
                    return $p;
                }
            }
        }
        return NULL;
    }
}
