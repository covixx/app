<?php

namespace App\Command;

use App\Base\BaseCommand;
use App\Manager\RefreshManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RefreshCommand extends BaseCommand
{
    /**
     * @var RefreshManager
     */
    private $refreshManager;

    /**
     * @param RefreshManager $refreshManager
     */
    public function __construct(RefreshManager $refreshManager)
    {
        parent::__construct();

        $this->refreshManager = $refreshManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('refresh')
            ->setDescription('Refresh structures and volunteers based on Pegass cache');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        date_default_timezone_set('UTC');

        // Refresh everything
        $this->refreshManager->refresh();
    }
}