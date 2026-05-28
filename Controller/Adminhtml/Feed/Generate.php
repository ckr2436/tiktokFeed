<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;
use Pynarae\TiktokFeed\Helper\Config;
use Pynarae\TiktokFeed\Service\GenerateFeedService;

class Generate extends Action
{
    public const ADMIN_RESOURCE = 'Pynarae_TiktokFeed::generate';

    private GenerateFeedService $feedService;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        GenerateFeedService $feedService,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->feedService = $feedService;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if (!$this->config->isEnabled()) {
            $this->messageManager->addWarningMessage(__('TikTok Feed generation is disabled in configuration.'));
            return $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'pynarae_tiktokfeed']);
        }

        try {
            $result = $this->feedService->execute();
            $this->messageManager->addSuccessMessage(__(
                'TikTok feed generated successfully from Magento catalog products. Processed %1 products. Skipped %2 products. Output: %3',
                $result['processed'],
                $result['skipped'],
                $result['destination_file']
            ));
        } catch (\Throwable $exception) {
            $this->logger->critical('Manual TikTok feed generation failed: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
            $this->messageManager->addErrorMessage(__('TikTok feed generation failed: %1', $exception->getMessage()));
        }

        return $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'pynarae_tiktokfeed']);
    }
}
