<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Console\Command;

use Pynarae\TiktokFeed\Service\GenerateFeedService;
use Magento\Framework\Console\Cli;
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
             ->setDescription('生成 TikTok 产品 Feed CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->feedService->execute();
            $output->writeln('<info>✅ TikTok feed 已生成：pub/media/feed/tiktok_feed.csv</info>');
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>生成失败：'.$e->getMessage().'</error>');
            return Cli::RETURN_FAILURE;
        }
    }
}
