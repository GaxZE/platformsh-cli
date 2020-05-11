<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DrupalDeployCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('drupal:deploy')
            ->setAliases(array('deploy'))
            ->setDescription('Deploy a Drupal Site locally')
            ->addOption('db-sync', 'd', InputOption::VALUE_NONE, "Sync project's database with the daily live backup.")
            ->addOption('core-branch', 'c', InputOption::VALUE_REQUIRED, "The core profile's branch to use during deployment")
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
    }
}
