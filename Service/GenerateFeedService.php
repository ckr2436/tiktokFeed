<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Pynarae\TiktokFeed\Helper\Config;

class GenerateFeedService
{
    private FileDriver $fileDriver;
    private string $mediaPath;
    private ProductRepositoryInterface $productRepository;
    private Config $config;

    public function __construct(
        FileDriver $fileDriver,
        DirectoryList $directoryList,
        ProductRepositoryInterface $productRepository,
        Config $config
    ) {
        $this->fileDriver = $fileDriver;
        $this->mediaPath = $directoryList->getPath(DirectoryList::MEDIA);
        $this->productRepository = $productRepository;
        $this->config = $config;
    }

    /**
     * Generate TikTok product feed CSV from the latest source XML.
     *
     * @return array{source_file:string,destination_file:string,processed:int}
     * @throws FileSystemException
     * @throws LocalizedException
     */
    public function execute(): array
    {
        $sourceFile = $this->getLatestSourceFile();
        $xml = simplexml_load_file($sourceFile, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (!$xml || !isset($xml->channel->item)) {
            throw new \RuntimeException('Invalid source XML or no product items found: ' . $sourceFile);
        }

        $destinationDirectory = $this->getMediaAbsolutePath($this->config->getOutputDir());
        $this->fileDriver->createDirectory($destinationDirectory);

        $destinationFile = $destinationDirectory . DIRECTORY_SEPARATOR . $this->sanitizeFilename($this->config->getOutputFilename());
        $temporaryFile = $destinationFile . '.tmp';

        $handle = fopen($temporaryFile, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open feed file for writing: ' . $temporaryFile);
        }

        $processed = 0;

        try {
            fputcsv($handle, $this->getCsvHeaders());

            foreach ($xml->channel->item as $item) {
                $google = $item->children('g', true);
                $sku = trim((string)$google->id);

                if ($sku === '') {
                    continue;
                }

                $product = $this->getProductBySku($sku);
                $htmlDescription = trim((string)$google->description);

                if ($htmlDescription === '' && $product instanceof ProductInterface) {
                    $htmlDescription = (string)($product->getShortDescription() ?: '');
                }

                [$plainDescription, $descriptionImages] = $this->parseDescriptionHtml($htmlDescription);

                $imageLink = $this->normalizeImageUrl((string)$google->image_link);
                $additionalImages = $this->getAdditionalImages($google);
                $allImages = $this->uniqueNonEmpty(array_merge([$imageLink], $additionalImages, $descriptionImages));

                $brand = $this->resolveBrand($google, $product);
                $googleProductCategory = trim((string)$google->google_product_category);
                if ($googleProductCategory === '') {
                    $googleProductCategory = $this->config->getDefaultGoogleProductCategory();
                }

                fputcsv($handle, [
                    $sku,
                    trim((string)$google->title),
                    $plainDescription,
                    implode(',', $allImages),
                    $this->normalizeAvailability((string)$google->availability),
                    'New',
                    $this->normalizePrice((string)$google->price),
                    $this->normalizeProductLink((string)$google->link),
                    $imageLink,
                    implode(',', array_slice($additionalImages, 0, $this->config->getMaxAdditionalImages())),
                    $brand,
                    trim((string)$google->item_group_id) ?: $sku,
                    $googleProductCategory,
                    $googleProductCategory,
                    trim((string)$google->gtin)
                ]);

                $processed++;
            }
        } finally {
            fclose($handle);
        }

        $this->fileDriver->rename($temporaryFile, $destinationFile);

        return [
            'source_file' => $sourceFile,
            'destination_file' => $destinationFile,
            'processed' => $processed,
        ];
    }

    /**
     * @throws FileSystemException
     */
    private function getLatestSourceFile(): string
    {
        $sourceDirectory = $this->getMediaAbsolutePath($this->config->getSourceDir());
        $pattern = $this->config->getSourcePattern();
        $files = glob($sourceDirectory . DIRECTORY_SEPARATOR . $pattern) ?: [];

        if (empty($files)) {
            throw new \RuntimeException(sprintf(
                'No source XML found. Directory: %s, pattern: %s',
                $sourceDirectory,
                $pattern
            ));
        }

        usort($files, static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
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

    private function getProductBySku(string $sku): ?ProductInterface
    {
        try {
            return $this->productRepository->get($sku, false, null, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveBrand(\SimpleXMLElement $google, ?ProductInterface $product): string
    {
        $brand = trim((string)$google->brand);
        if ($brand !== '') {
            return $brand;
        }

        $attributeCode = $this->config->getBrandAttributeCode();
        if ($product instanceof ProductInterface && $attributeCode !== '') {
            $attributeValue = $product->getAttributeText($attributeCode);
            if (is_array($attributeValue)) {
                $attributeValue = implode(', ', $attributeValue);
            }

            $brand = trim((string)$attributeValue);
            if ($brand === '') {
                $brand = trim((string)$product->getData($attributeCode));
            }
        }

        return $brand !== '' ? $brand : $this->config->getDefaultBrand();
    }

    private function normalizeAvailability(string $availability): string
    {
        $availability = strtolower(trim($availability));

        if ($availability === 'in_stock' || strpos($availability, 'in stock') !== false || $availability === 'in') {
            return 'In stock';
        }

        return 'Out of stock';
    }

    private function normalizePrice(string $price): string
    {
        $price = trim($price);

        if (preg_match('/^([A-Z]{3})\s*([\d,.]+)/', $price, $matches)) {
            return str_replace(',', '', $matches[2]) . ' ' . $matches[1];
        }

        if (preg_match('/^([\d,.]+)\s*([A-Z]{3})/', $price, $matches)) {
            return str_replace(',', '', $matches[1]) . ' ' . $matches[2];
        }

        return $price;
    }

    private function normalizeProductLink(string $link): string
    {
        $link = trim($link);

        if ($this->config->shouldNormalizeAdminLinks()) {
            $link = preg_replace('#(https?://[^/]+)/admin/#i', '$1/', $link) ?: $link;
        }

        return $link;
    }

    private function getAdditionalImages(\SimpleXMLElement $google): array
    {
        $images = [];
        foreach ($google->additional_image_link as $image) {
            $images[] = $this->normalizeImageUrl((string)$image);
        }

        return array_slice($this->uniqueNonEmpty($images), 0, $this->config->getMaxAdditionalImages());
    }

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
            /** @var \DOMElement $imageNode */
            $src = $imageNode->getAttribute('src');
            if ($src !== '') {
                $images[] = $this->normalizeImageUrl($this->extractMediaPath($src));
            }
        }

        foreach ($xpath->query('//img') as $imageNode) {
            if ($imageNode->parentNode) {
                $imageNode->parentNode->removeChild($imageNode);
            }
        }

        $text = trim($dom->textContent ?: '');
        $text = preg_replace('/\s{2,}/', ' ', $text) ?: $text;

        return [$text, $this->uniqueNonEmpty($images)];
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

    private function normalizeImageUrl(string $source): string
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

        return rtrim($this->config->getBaseMediaUrl(), '/') . '/' . $source;
    }

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
