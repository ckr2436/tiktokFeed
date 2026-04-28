<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Pynarae\TiktokFeed\Helper\Config;
use Pynarae\TiktokFeed\Service\GenerateFeedService;
use Psr\Log\LoggerInterface;

class Generate extends Action
{
    public const ADMIN_RESOURCE = 'Pynarae_TiktokFeed::generate';

    private GenerateFeedService $feedService;
    private Config $config;
    private LoggerInterface $logger;
    private BackendUrlInterface $backendUrl;

    public function __construct(
        Context $context,
        GenerateFeedService $feedService,
        Config $config,
        LoggerInterface $logger,
        BackendUrlInterface $backendUrl
    ) {
        parent::__construct($context);
        $this->feedService = $feedService;
        $this->config = $config;
        $this->logger = $logger;
        $this->backendUrl = $backendUrl;
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
                'TikTok feed generated successfully. Processed %1 products. Output: %2',
                $result['processed'],
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
