<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollectionFactory;
use Pynarae\TiktokFeed\Helper\Config;

class GenerateFeedService
{
    private const EXPORT_PRODUCT_TYPES = [
        ProductType::TYPE_SIMPLE,
    ];

    private FileDriver $fileDriver;
    private string $mediaPath;
    private ProductCollectionFactory $productCollectionFactory;
    private ProductRepositoryInterface $productRepository;
    private StoreManagerInterface $storeManager;
    private StockRegistryInterface $stockRegistry;
    private ConfigurableType $configurableType;
    private UrlRewriteCollectionFactory $urlRewriteCollectionFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private Config $config;

    /** @var array<int, CatalogProduct|null> */
    private array $parentProductCache = [];

    /** @var array<string, string> */
    private array $categoryPathCache = [];

    public function __construct(
        FileDriver $fileDriver,
        DirectoryList $directoryList,
        ProductCollectionFactory $productCollectionFactory,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        StockRegistryInterface $stockRegistry,
        ConfigurableType $configurableType,
        UrlRewriteCollectionFactory $urlRewriteCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        Config $config
    ) {
        $this->fileDriver = $fileDriver;
        $this->mediaPath = $directoryList->getPath(DirectoryList::MEDIA);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->stockRegistry = $stockRegistry;
        $this->configurableType = $configurableType;
        $this->urlRewriteCollectionFactory = $urlRewriteCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->config = $config;
    }

    /**
     * Generate TikTok product feed CSV directly from Magento catalog products.
     *
     * @return array{source:string,destination_file:string,processed:int,skipped:int}
     * @throws FileSystemException
     */
    public function execute(): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();
        $currencyCode = (string)$store->getCurrentCurrencyCode();

        $destinationDirectory = $this->getMediaAbsolutePath($this->config->getOutputDir($storeId));
        $this->fileDriver->createDirectory($destinationDirectory);

        $destinationFile = $destinationDirectory . DIRECTORY_SEPARATOR . $this->sanitizeFilename($this->config->getOutputFilename($storeId));
        $temporaryFile = $destinationFile . '.tmp';

        $handle = fopen($temporaryFile, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open feed file for writing: ' . $temporaryFile);
        }

        $processed = 0;
        $skipped = 0;

        try {
            fputcsv($handle, $this->getCsvHeaders());

            foreach ($this->getProductCollection($storeId) as $product) {
                if (!$product instanceof CatalogProduct) {
                    $skipped++;
                    continue;
                }

                $product->setStoreId($storeId);
                $parentIds = $this->configurableType->getParentIdsByChild((int)$product->getId());
                $parentProduct = $this->getBestParentProduct($parentIds, $storeId);

                if (!$this->isExportableProduct($product, $parentProduct, $parentIds)) {
                    $skipped++;
                    continue;
                }

                $row = $this->buildCsvRow($product, $parentProduct, $storeId, $currencyCode);
                if ($row === null) {
                    $skipped++;
                    continue;
                }

                fputcsv($handle, $row);
                $processed++;
            }
        } finally {
            fclose($handle);
        }

        $this->fileDriver->rename($temporaryFile, $destinationFile);

        return [
            'source' => 'Magento catalog products',
            'destination_file' => $destinationFile,
            'processed' => $processed,
            'skipped' => $skipped,
        ];
    }

    private function getProductCollection(int $storeId): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('type_id', ['in' => self::EXPORT_PRODUCT_TYPES]);
        $collection->addFinalPrice();
        $collection->addMediaGalleryData();

        return $collection;
    }

    /**
     * @param int[] $parentIds
     */
    private function getBestParentProduct(array $parentIds, int $storeId): ?CatalogProduct
    {
        foreach ($parentIds as $parentId) {
            $parentId = (int)$parentId;
            if ($parentId <= 0) {
                continue;
            }

            if (!array_key_exists($parentId, $this->parentProductCache)) {
                $this->parentProductCache[$parentId] = $this->loadParentProduct($parentId, $storeId);
            }

            $parentProduct = $this->parentProductCache[$parentId];
            if ($parentProduct instanceof CatalogProduct && $this->isVisibleEnabledProduct($parentProduct)) {
                return $parentProduct;
            }
        }

        return null;
    }

    private function loadParentProduct(int $productId, int $storeId): ?CatalogProduct
    {
        try {
            $product = $this->productRepository->getById($productId, false, $storeId, true);
            if ($product instanceof CatalogProduct) {
                $product->setStoreId($storeId);
                return $product;
            }
        } catch (\Throwable $exception) {
            return null;
        }

        return null;
    }

    /**
     * @param int[] $parentIds
     */
    private function isExportableProduct(CatalogProduct $product, ?CatalogProduct $parentProduct, array $parentIds): bool
    {
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            return false;
        }

        if (!empty($parentIds)) {
            return $parentProduct instanceof CatalogProduct;
        }

        return (int)$product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE;
    }

    private function isVisibleEnabledProduct(CatalogProduct $product): bool
    {
        return (int)$product->getStatus() === Status::STATUS_ENABLED
            && (int)$product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE;
    }

    /**
     * @return string[]|null
     */
    private function buildCsvRow(CatalogProduct $product, ?CatalogProduct $parentProduct, int $storeId, string $currencyCode): ?array
    {
        $displayProduct = $parentProduct ?: $product;
        $sku = trim((string)$product->getSku());
        $title = $this->buildTitle($product, $parentProduct);

        if ($sku === '' || $title === '') {
            return null;
        }

        $htmlDescription = $this->resolveDescriptionHtml($product, $parentProduct);
        [$plainDescription, $descriptionImages] = $this->parseDescriptionHtml($htmlDescription);

        $imageLink = $this->resolveMainImageUrl($product, $parentProduct, $storeId);
        $additionalImages = $this->resolveAdditionalImages($product, $parentProduct, $descriptionImages, $imageLink, $storeId);
        $allImages = $this->uniqueNonEmpty(array_merge([$imageLink], $additionalImages));

        $brand = $this->resolveBrand($product, $parentProduct, $storeId);
        $googleProductCategory = $this->config->getDefaultGoogleProductCategory($storeId);
        $productType = $this->resolveProductType($displayProduct, $storeId);
        $gtin = $this->resolveGtin($product, $parentProduct, $storeId);

        return [
            $sku,
            $title,
            $plainDescription,
            implode(',', $allImages),
            $this->resolveAvailability($product),
            'New',
            $this->formatPrice($product, $displayProduct, $currencyCode),
            $this->resolveProductUrl($displayProduct, $storeId),
            $imageLink,
            implode(',', array_slice($additionalImages, 0, $this->config->getMaxAdditionalImages($storeId))),
            $brand,
            trim((string)$displayProduct->getSku()) ?: $sku,
            $googleProductCategory,
            $productType,
            $gtin,
        ];
    }

    private function buildTitle(CatalogProduct $product, ?CatalogProduct $parentProduct): string
    {
        $productName = trim((string)$product->getName());
        if (!$parentProduct instanceof CatalogProduct) {
            return $productName !== '' ? $productName : trim((string)$product->getSku());
        }

        $parentName = trim((string)$parentProduct->getName());
        if ($productName !== '' && strcasecmp($productName, $parentName) !== 0) {
            return $productName;
        }

        $optionLabels = $this->getVariantOptionLabels($product, $parentProduct);
        if (!empty($optionLabels)) {
            return trim($parentName . ' - ' . implode(' / ', $optionLabels));
        }

        return $parentName !== '' ? $parentName : trim((string)$product->getSku());
    }

    /**
     * @return string[]
     */
    private function getVariantOptionLabels(CatalogProduct $product, CatalogProduct $parentProduct): array
    {
        $labels = [];

        try {
            $configurableAttributes = $this->configurableType->getConfigurableAttributes($parentProduct);
            foreach ($configurableAttributes as $configurableAttribute) {
                $attribute = $configurableAttribute->getProductAttribute();
                if (!$attribute) {
                    continue;
                }

                $label = $this->resolveAttributeDisplayValue($product, (string)$attribute->getAttributeCode());
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        } catch (\Throwable $exception) {
            return [];
        }

        return $this->uniqueNonEmpty($labels);
    }

    private function resolveDescriptionHtml(CatalogProduct $product, ?CatalogProduct $parentProduct): string
    {
        $fields = ['description', 'short_description'];
        foreach ($fields as $field) {
            $value = trim((string)$product->getData($field));
            if ($value !== '') {
                return $value;
            }
        }

        if ($parentProduct instanceof CatalogProduct) {
            foreach ($fields as $field) {
                $value = trim((string)$parentProduct->getData($field));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @return array{0:string,1:string[]}
     */
    private function parseDescriptionHtml(string $html): array
    {
        if (trim($html) === '') {
            return ['', []];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $images = [];

        foreach ($xpath->query('//img') as $imageNode) {
            if (!$imageNode instanceof \DOMElement) {
                continue;
            }

            $src = $imageNode->getAttribute('src');
            if ($src !== '') {
                $images[] = $this->normalizeMediaUrl($this->extractMediaPath($src));
            }
        }

        foreach ($xpath->query('//img') as $imageNode) {
            if ($imageNode->parentNode) {
                $imageNode->parentNode->removeChild($imageNode);
            }
        }

        $text = trim((string)$dom->textContent);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s{2,}/', ' ', $text) ?: $text;

        return [$text, $this->uniqueNonEmpty($images)];
    }

    private function resolveMainImageUrl(CatalogProduct $product, ?CatalogProduct $parentProduct, int $storeId): string
    {
        $imageUrl = $this->getProductImageUrl($product, $storeId);
        if ($imageUrl !== '') {
            return $imageUrl;
        }

        if ($parentProduct instanceof CatalogProduct) {
            return $this->getProductImageUrl($parentProduct, $storeId);
        }

        return '';
    }

    private function getProductImageUrl(CatalogProduct $product, int $storeId): string
    {
        foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
            $image = trim((string)$product->getData($attributeCode));
            if ($image !== '' && $image !== 'no_selection') {
                return $this->buildCatalogImageUrl($image, $storeId);
            }
        }

        return '';
    }

    /**
     * @param string[] $descriptionImages
     * @return string[]
     */
    private function resolveAdditionalImages(
        CatalogProduct $product,
        ?CatalogProduct $parentProduct,
        array $descriptionImages,
        string $mainImage,
        int $storeId
    ): array {
        $images = array_merge(
            $this->getGalleryImageUrls($product, $storeId),
            $parentProduct instanceof CatalogProduct ? $this->getGalleryImageUrls($parentProduct, $storeId) : [],
            $descriptionImages
        );

        $images = $this->uniqueNonEmpty($images);
        return array_values(array_filter($images, static function (string $image) use ($mainImage): bool {
            return $image !== '' && $image !== $mainImage;
        }));
    }

    /**
     * @return string[]
     */
    private function getGalleryImageUrls(CatalogProduct $product, int $storeId): array
    {
        $urls = [];
        $galleryImages = $product->getMediaGalleryImages();

        if (!$galleryImages) {
            return [];
        }

        foreach ($galleryImages as $galleryImage) {
            $file = trim((string)($galleryImage->getFile() ?: $galleryImage->getData('file')));
            if ($file !== '') {
                $urls[] = $this->buildCatalogImageUrl($file, $storeId);
                continue;
            }

            $url = trim((string)$galleryImage->getUrl());
            if ($url !== '') {
                $urls[] = $this->normalizeMediaUrl($url, $storeId);
            }
        }

        return $this->uniqueNonEmpty($urls);
    }

    private function buildCatalogImageUrl(string $image, int $storeId): string
    {
        $image = trim($image);
        if ($image === '' || $image === 'no_selection') {
            return '';
        }

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        $image = ltrim($image, '/');
        $image = preg_replace('#^(pub/)?media/#i', '', $image) ?: $image;
        $image = preg_replace('#^catalog/product/#i', '', $image) ?: $image;

        return rtrim($this->config->getBaseMediaUrl($storeId), '/') . '/catalog/product/' . $image;
    }

    private function normalizeMediaUrl(string $source, ?int $storeId = null): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $source)) {
            return $source;
        }

        $source = ltrim($source, '/');
        $source = preg_replace('#^(pub/)?media/#i', '', $source) ?: $source;

        return rtrim($this->config->getBaseMediaUrl($storeId), '/') . '/' . $source;
    }

    private function extractMediaPath(string $source): string
    {
        $source = trim(html_entity_decode($source, ENT_QUOTES, 'UTF-8'));

        if (preg_match('/\{\{\s*media\s+url=["\']?([^"\'\}]+)["\']?\s*\}\}/i', $source, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/media\/url=([^\}]+)/i', $source, $matches)) {
            return trim($matches[1], ' "\'');
        }

        return $source;
    }

    private function resolveAvailability(CatalogProduct $product): string
    {
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku((string)$product->getSku());
            if ($stockItem && (bool)$stockItem->getIsInStock()) {
                if (!$stockItem->getManageStock()) {
                    return 'In stock';
                }

                return (float)$stockItem->getQty() > 0 ? 'In stock' : 'Out of stock';
            }
        } catch (\Throwable $exception) {
            return $product->isSaleable() ? 'In stock' : 'Out of stock';
        }

        return 'Out of stock';
    }

    private function formatPrice(CatalogProduct $product, CatalogProduct $displayProduct, string $currencyCode): string
    {
        $price = (float)$product->getFinalPrice();
        if ($price <= 0.0) {
            $price = (float)$displayProduct->getFinalPrice();
        }

        return number_format(max(0.0, $price), 2, '.', '') . ' ' . strtoupper($currencyCode);
    }

    private function resolveProductUrl(CatalogProduct $product, int $storeId): string
    {
        $requestPath = $this->getProductRequestPath((int)$product->getId(), $storeId);
        if ($requestPath !== '') {
            return rtrim($this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/') . '/' . ltrim($requestPath, '/');
        }

        $url = trim((string)$product->getProductUrl());
        return preg_replace('#(https?://[^/]+)/admin/#i', '$1/', $url) ?: $url;
    }

    private function getProductRequestPath(int $productId, int $storeId): string
    {
        try {
            $collection = $this->urlRewriteCollectionFactory->create();
            $collection->addFieldToFilter('entity_type', 'product');
            $collection->addFieldToFilter('entity_id', $productId);
            $collection->addFieldToFilter('store_id', $storeId);
            $collection->addFieldToFilter('redirect_type', 0);
            $collection->setPageSize(1);

            return trim((string)$collection->getFirstItem()->getRequestPath());
        } catch (\Throwable $exception) {
            return '';
        }
    }

    private function resolveBrand(CatalogProduct $product, ?CatalogProduct $parentProduct, int $storeId): string
    {
        $attributeCode = $this->config->getBrandAttributeCode($storeId);
        $brand = $this->resolveAttributeDisplayValue($product, $attributeCode);

        if ($brand === '' && $parentProduct instanceof CatalogProduct) {
            $brand = $this->resolveAttributeDisplayValue($parentProduct, $attributeCode);
        }

        return $brand !== '' ? $brand : $this->config->getDefaultBrand($storeId);
    }

    private function resolveGtin(CatalogProduct $product, ?CatalogProduct $parentProduct, int $storeId): string
    {
        $attributeCodes = array_unique(array_filter([
            $this->config->getGtinAttributeCode($storeId),
            'gtin',
            'upc',
            'ean',
            'barcode',
        ]));

        foreach ($attributeCodes as $attributeCode) {
            $value = $this->resolveAttributeDisplayValue($product, $attributeCode);
            if ($value !== '') {
                return $value;
            }

            if ($parentProduct instanceof CatalogProduct) {
                $value = $this->resolveAttributeDisplayValue($parentProduct, $attributeCode);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolveAttributeDisplayValue(CatalogProduct $product, string $attributeCode): string
    {
        $attributeCode = trim($attributeCode);
        if ($attributeCode === '') {
            return '';
        }

        try {
            $attributeText = $product->getAttributeText($attributeCode);
            if (is_array($attributeText)) {
                $attributeText = implode(', ', $attributeText);
            }

            if (is_scalar($attributeText)) {
                $attributeText = trim((string)$attributeText);
                if ($attributeText !== '') {
                    return $attributeText;
                }
            }
        } catch (\Throwable $exception) {
            // Continue with raw attribute data fallback.
        }

        $value = $product->getData($attributeCode);
        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function resolveProductType(CatalogProduct $product, int $storeId): string
    {
        $categoryPath = $this->getProductCategoryPath($product, $storeId);
        return $categoryPath !== '' ? $categoryPath : $this->config->getDefaultGoogleProductCategory($storeId);
    }

    private function getProductCategoryPath(CatalogProduct $product, int $storeId): string
    {
        $categoryIds = array_map('intval', $product->getCategoryIds() ?: []);
        $categoryIds = array_values(array_filter(array_unique($categoryIds)));
        if (empty($categoryIds)) {
            return '';
        }

        $cacheKey = $storeId . ':' . implode(',', $categoryIds);
        if (array_key_exists($cacheKey, $this->categoryPathCache)) {
            return $this->categoryPathCache[$cacheKey];
        }

        try {
            $categoryCollection = $this->categoryCollectionFactory->create();
            $categoryCollection->setStoreId($storeId);
            $categoryCollection->addAttributeToSelect(['name', 'is_active', 'level', 'path']);
            $categoryCollection->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
            $categoryCollection->addAttributeToFilter('is_active', 1);

            $bestCategory = null;
            foreach ($categoryCollection as $category) {
                if ($bestCategory === null || (int)$category->getLevel() > (int)$bestCategory->getLevel()) {
                    $bestCategory = $category;
                }
            }

            if ($bestCategory === null) {
                return $this->categoryPathCache[$cacheKey] = '';
            }

            $pathIds = array_map('intval', explode('/', (string)$bestCategory->getPath()));
            $pathCollection = $this->categoryCollectionFactory->create();
            $pathCollection->setStoreId($storeId);
            $pathCollection->addAttributeToSelect(['name', 'is_active', 'level']);
            $pathCollection->addAttributeToFilter('entity_id', ['in' => $pathIds]);
            $pathCollection->addAttributeToFilter('is_active', 1);

            $namesById = [];
            foreach ($pathCollection as $category) {
                $level = (int)$category->getLevel();
                $name = trim((string)$category->getName());
                if ($level <= 1 || $name === '' || in_array($name, ['Root Catalog', 'Default Category'], true)) {
                    continue;
                }

                $namesById[(int)$category->getId()] = $name;
            }

            $names = [];
            foreach ($pathIds as $pathId) {
                if (isset($namesById[$pathId])) {
                    $names[] = $namesById[$pathId];
                }
            }

            return $this->categoryPathCache[$cacheKey] = implode(' > ', $this->uniqueNonEmpty($names));
        } catch (\Throwable $exception) {
            return $this->categoryPathCache[$cacheKey] = '';
        }
    }

    /**
     * @throws FileSystemException
     */
    private function getMediaAbsolutePath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '' || strpos($relativePath, '..') !== false) {
            throw new \RuntimeException('Invalid media relative path: ' . $relativePath);
        }

        return $this->mediaPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename(trim($filename));

        if ($filename === '' || strpos($filename, '..') !== false) {
            return 'tiktok_feed.csv';
        }

        return $filename;
    }

    /**
     * @return string[]
     */
    private function getCsvHeaders(): array
    {
        return [
            'sku_id',
            'title',
            'description',
            'images',
            'availability',
            'condition',
            'price',
            'link',
            'image_link',
            'additional_image_link',
            'brand',
            'item_group_id',
            'google_product_category',
            'product_type',
            'gtin',
        ];
    }

    /**
     * @param array<int, mixed> $values
     * @return string[]
     */
    private function uniqueNonEmpty(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
