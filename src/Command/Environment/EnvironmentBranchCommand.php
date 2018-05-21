<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBranchCommand extends CommandBase
{

    protected static $defaultName = 'environment:branch';

    protected $activityMonitor;
    protected $api;
    protected $config;
    protected $git;
    protected $questionHelper;
    protected $selector;
    protected $ssh;

    public function __construct(
        ActivityMonitor $activityMonitor,
        Api $api,
        Config $config,
        Git $git,
        QuestionHelper $questionHelper,
        Selector $selector,
        Ssh $ssh
    ) {
        $this->activityMonitor = $activityMonitor;
        $this->api = $api;
        $this->config = $config;
        $this->git = $git;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['branch'])
            ->setDescription('Branch an environment')
            ->addArgument('id', InputArgument::OPTIONAL, 'The ID (branch name) of the new environment')
            ->addArgument('parent', InputArgument::OPTIONAL, 'The parent of the new environment')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The title of the new environment')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Create the new environment even if the branch cannot be checked out locally"
            )
            ->addOption(
                'no-clone-parent',
                null,
                InputOption::VALUE_NONE,
                "Do not clone the parent branch's data"
            );
        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityMonitor->addWaitOptions($definition);
        $this->ssh->configureInput($definition);
        $this->addExample('Create a new branch "sprint-2", based on "develop"', 'sprint-2 develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->selector->setEnvArgName('parent');
        $selection = $this->selector->getSelection($input);
        $selectedProject = $selection->getProject();
        $parentEnvironment = $selection->getEnvironment();

        $branchName = $input->getArgument('id');
        if (empty($branchName)) {
            if ($input->isInteractive()) {
                // List environments.
                return $this->runOtherCommand(
                    'environments',
                    ['--project' => $selectedProject->id]
                );
            }
            $this->stdErr->writeln("<error>You must specify the name of the new branch.</error>");

            return 1;
        }

        if ($branchName === $parentEnvironment->id) {
            $this->stdErr->writeln('Already on <comment>' . $branchName . '</comment>');

            return 1;
        }

        if ($environment = $this->api->getEnvironment($branchName, $selectedProject)) {
            $checkout = $this->questionHelper->confirm(
                "The environment <comment>$branchName</comment> already exists. Check out?"
            );
            if ($checkout) {
                return $this->runOtherCommand(
                    'environment:checkout',
                    ['id' => $environment->id]
                );
            }

            return 1;
        }

        if (!$this->api->checkEnvironmentOperation('branch', $parentEnvironment)) {
            $this->stdErr->writeln(
                "Operation not available: The environment <error>{$parentEnvironment->id}</error> can't be branched."
            );
            if ($parentEnvironment->is_dirty) {
                $this->api->clearEnvironmentsCache($selectedProject->id);
            }

            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->selector->getProjectRoot();
        if (!$projectRoot && $force) {
            $this->stdErr->writeln(
                "<comment>This command was run from outside your local project root, so the new " . $this->config->get('service.name') . " branch cannot be checked out in your local Git repository."
                . " Make sure to run '" . $this->config->get('application.executable') . " checkout' or 'git checkout' in your local repository to switch to the branch you are expecting.</comment>"
            );
        } elseif (!$projectRoot) {
            $this->stdErr->writeln(
                '<error>You must run this command inside the project root, or specify --force.</error>'
            );

            return 1;
        }

        $this->stdErr->writeln(sprintf(
            'Creating a new environment <info>%s</info>, branched from <info>%s</info>',
            $branchName,
            $parentEnvironment->title
        ));

        $title = $input->getOption('title') ?: $branchName;
        $activity = $parentEnvironment->branch($title, $branchName, !$input->getOption('no-clone-parent'));

        // Clear the environments cache, as branching has started.
        $this->api->clearEnvironmentsCache($selectedProject->id);

        $this->git->setSshCommand($this->ssh->getSshCommand());

        if ($projectRoot) {
            // If the Git branch already exists locally, just check it out.
            $existsLocally = $this->git->branchExists($branchName, $projectRoot);
            if ($existsLocally) {
                $this->stdErr->writeln("Checking out <info>$branchName</info> locally");
                if (!$this->git->checkOut($branchName, $projectRoot)) {
                    $this->stdErr->writeln('<error>Failed to check out branch locally: ' . $branchName . '</error>');
                    if (!$force) {
                        return 1;
                    }
                }
            } else {
                // Create a new branch, using the parent if it exists locally.
                $parent = $this->git->branchExists($parentEnvironment->id, $projectRoot) ? $parentEnvironment->id : null;
                $this->stdErr->writeln("Creating local branch <info>$branchName</info>");

                if (!$this->git->checkOutNew($branchName, $parent, null, $projectRoot)) {
                    $this->stdErr->writeln('<error>Failed to create branch locally: ' . $branchName . '</error>');
                    if (!$force) {
                        return 1;
                    }
                }
            }
        }

        $remoteSuccess = true;
        if ($this->activityMonitor->shouldWait($input)) {
            $remoteSuccess = $this->activityMonitor->waitAndLog(
                $activity,
                "The environment <info>$branchName</info> has been created.",
                '<error>Branching failed</error>'
            );

            // Set the local branch to track the remote branch. This requires
            // first fetching the new branch from the remote.
            if ($remoteSuccess && $projectRoot) {
                $upstreamRemote = $this->config->get('detection.git_remote_name');
                $this->git->fetch($upstreamRemote, $branchName, $projectRoot);
                $this->git->setUpstream($upstreamRemote . '/' . $branchName, $branchName, $projectRoot);
            }
        }

        $this->api->clearEnvironmentsCache($selectedProject->id);

        return $remoteSuccess ? 0 : 1;
    }
}
