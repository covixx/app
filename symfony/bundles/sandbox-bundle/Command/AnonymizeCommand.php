<?php

namespace Bundles\SandboxBundle\Command;

use App\Base\BaseCommand;
use Bundles\SandboxBundle\Manager\AnonymizeManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AnonymizeCommand extends BaseCommand
{
    /**
     * @var AnonymizeManager
     */
    private $anonymizeManager;

    /**
     * @param AnonymizeManager $anonymizeManager
     */
    public function __construct(AnonymizeManager $anonymizeManager)
    {
        parent::__construct();

        $this->anonymizeManager = $anonymizeManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('anonymize')
            ->addOption('volunteer', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Volunteer nivol to anonymize')
            ->setDescription('Anonymize a specified volunteer, or the whole RedCall database');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($volunteers = $input->getOption('volunteer')) {
            foreach ($volunteers as $nivol) {
                $this->anonymizeManager->anonymizeVolunteer(ltrim($nivol, '0'));
            }
        } else {
            $this->anonymizeManager->anonymizeDatabase();
        }

        return 0;
    }
}