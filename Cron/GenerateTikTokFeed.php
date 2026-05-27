<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Cron;

use Psr\Log\LoggerInterface;
use Pynarae\TiktokFeed\Helper\Config;
use Pynarae\TiktokFeed\Service\GenerateFeedService;

class GenerateTikTokFeed
{
    private GenerateFeedService $feedService;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        GenerateFeedService $feedService,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->feedService = $feedService;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info('TikTokFeed cron skipped: module is disabled.');
            return;
        }

        $this->logger->info('TikTokFeed cron started. Source: Magento catalog products.');

        try {
            $result = $this->feedService->execute();
            $this->logger->info(sprintf(
                'TikTokFeed cron completed. Processed %d products. Skipped %d products. Destination: %s.',
                $result['processed'],
                $result['skipped'],
                $result['destination_file']
            ));
        } catch (\Throwable $exception) {
            $this->logger->critical('TikTokFeed cron failed: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
