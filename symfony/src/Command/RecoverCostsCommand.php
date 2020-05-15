<?php

namespace App\Command;

use App\Base\BaseCommand;
use App\Manager\CostManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RecoverCostsCommand extends BaseCommand
{
    /**
     * @var CostManager
     */
    private $costManager;

    public function __construct(CostManager $costManager)
    {
        parent::__construct();

        $this->costManager = $costManager;
    }

    protected function configure()
    {
        $this
            ->setName('twilio:recover')
            ->setDescription('Reconcile messages & call costs');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->costManager->recoverCosts();

        return 0;
    }
}