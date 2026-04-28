<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Cron;

use Pynarae\TiktokFeed\Service\GenerateFeedService;
use Psr\Log\LoggerInterface;

class GenerateTikTokFeed
{
    private GenerateFeedService $feedService;
    private LoggerInterface $logger;

    public function __construct(
        GenerateFeedService $feedService,
        LoggerInterface $logger
    ) {
        $this->feedService = $feedService;
        $this->logger      = $logger;
    }

    public function execute(): void
    {
        $this->logger->info('TikTokFeed cron 开始');
        try {
            $this->feedService->execute();
            $this->logger->info('✅ TikTokFeed cron 完成：文件已生成');
        } catch (\Throwable $e) {
            $this->logger->error('TikTokFeed cron 错误：'.$e->getMessage());
        }
    }
}
