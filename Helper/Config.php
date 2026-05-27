<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const XML_PATH_ENABLED = 'pynarae_tiktokfeed/general/enabled';
    public const XML_PATH_OUTPUT_DIR = 'pynarae_tiktokfeed/general/output_dir';
    public const XML_PATH_OUTPUT_FILENAME = 'pynarae_tiktokfeed/general/output_filename';
    public const XML_PATH_BASE_MEDIA_URL = 'pynarae_tiktokfeed/general/base_media_url';
    public const XML_PATH_DEFAULT_BRAND = 'pynarae_tiktokfeed/product/default_brand';
    public const XML_PATH_BRAND_ATTRIBUTE = 'pynarae_tiktokfeed/product/brand_attribute';
    public const XML_PATH_GTIN_ATTRIBUTE = 'pynarae_tiktokfeed/product/gtin_attribute';
    public const XML_PATH_DEFAULT_GOOGLE_PRODUCT_CATEGORY = 'pynarae_tiktokfeed/product/default_google_product_category';
    public const XML_PATH_MAX_ADDITIONAL_IMAGES = 'pynarae_tiktokfeed/product/max_additional_images';

    private ScopeConfigInterface $scopeConfig;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getOutputDir(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_OUTPUT_DIR, 'feed', $storeId);
    }

    public function getOutputFilename(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_OUTPUT_FILENAME, 'tiktok_feed.csv', $storeId);
    }

    public function getBaseMediaUrl(?int $storeId = null): string
    {
        $configuredUrl = trim($this->getValue(self::XML_PATH_BASE_MEDIA_URL, '', $storeId));

        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/') . '/';
        }

        return rtrim($this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/') . '/';
    }

    public function getDefaultBrand(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_DEFAULT_BRAND, 'MYUPONA', $storeId);
    }

    public function getBrandAttributeCode(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_BRAND_ATTRIBUTE, 'brand', $storeId);
    }

    public function getGtinAttributeCode(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_GTIN_ATTRIBUTE, 'gtin', $storeId);
    }

    public function getDefaultGoogleProductCategory(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_DEFAULT_GOOGLE_PRODUCT_CATEGORY, 'Health & Beauty', $storeId);
    }

    public function getMaxAdditionalImages(?int $storeId = null): int
    {
        $value = (int)$this->getValue(self::XML_PATH_MAX_ADDITIONAL_IMAGES, '5', $storeId);
        return max(0, min(10, $value));
    }

    private function getValue(string $path, string $default = '', ?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        $value = is_scalar($value) ? trim((string)$value) : '';

        return $value !== '' ? $value : $default;
    }
}
