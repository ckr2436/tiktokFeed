<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Console\Command;

use Magento\Framework\Console\Cli;
use Pynarae\TiktokFeed\Service\GenerateFeedService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTikTokFeed extends Command
{
    private GenerateFeedService $feedService;

    public function __construct(
        GenerateFeedService $feedService
    ) {
        parent::__construct();
        $this->feedService = $feedService;
    }

    protected function configure(): void
    {
        $this->setName('pynarae:tiktokfeed:generate')
             ->setDescription('Generate TikTok product feed CSV directly from Magento catalog products');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->feedService->execute();
            $output->writeln(sprintf(
                '<info>TikTok feed generated. Processed: %d. Skipped: %d. Output: %s</info>',
                $result['processed'],
                $result['skipped'],
                $result['destination_file']
            ));
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Generation failed: ' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }
}
