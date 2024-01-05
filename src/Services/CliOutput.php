<?php

namespace MuckiSearchPlugin\Services;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class CliOutput
{
    final const PROGRESS_BAR_OFFSET = 0;

    public function __construct(
        protected SystemConfigService $_config,
        protected LoggerInterface $logger
    ){}

    public function prepareProductProgressBar(
        Progress $progress,
        int $totalCounter,
        OutputInterface $cliOutput
    ): ProgressBar
    {
        $progressBar = new ProgressBar($cliOutput, $totalCounter);
        $progressBar->setMaxSteps($progress->getTotal());
        $progressBar->setFormat('[%bar%] %current%/%max% indexing');
        $cliOutput->write('done',true);
        $progressBar->start();

        return $progressBar;
    }

    public function prepareProductProgress(int $totalCounter): Progress
    {
        $progress = new Progress(Uuid::randomHex(), Progress::STATE_PROGRESS, self::PROGRESS_BAR_OFFSET);
        $progress->setTotal($totalCounter);
        $progress->setOffset(self::PROGRESS_BAR_OFFSET);

        return $progress;
    }

    public function prepareIndexStructureProgressBar(
        Progress $progress,
        int $totalCounter,
        OutputInterface $cliOutput
    ): ProgressBar
    {
        $progressBar = new ProgressBar($cliOutput, $totalCounter);
        $progressBar->setMaxSteps($progress->getTotal());
        $progressBar->setFormat('[%bar%] %current%/%max% Index Structure');
        $cliOutput->write('done',true);
        $progressBar->start();

        return $progressBar;
    }

    public function prepareIndexStructureProgress(int $totalCounter): Progress
    {
        $progress = new Progress(Uuid::randomHex(), Progress::STATE_PROGRESS, self::PROGRESS_BAR_OFFSET);
        $progress->setTotal($totalCounter);
        $progress->setOffset(self::PROGRESS_BAR_OFFSET);

        return $progress;
    }

    public function printCliOutput(OutputInterface $cliOutput, $message = ''): void
    {
        if($message !== '') {
            $cliOutput->writeln($message);
        }
    }
}

