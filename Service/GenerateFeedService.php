<?php
declare(strict_types=1);

namespace Pynarae\TiktokFeed\Service;

use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class GenerateFeedService
{
    private FileDriver $fileDriver;
    private string $mediaPath;
    private ProductRepository $productRepo;

    public function __construct(
        FileDriver $fileDriver,
        DirectoryList $dirList,
        ProductRepository $productRepo
    ) {
        $this->fileDriver  = $fileDriver;
        $this->mediaPath   = $dirList->getPath(DirectoryList::MEDIA);
        $this->productRepo = $productRepo;
    }

    /**
     * 生成 TikTok feed CSV
     *
     * @throws FileSystemException
     * @throws LocalizedException
     */
    public function execute(): void
    {
        $feedDir = $this->mediaPath . '/run_as_root/feed';
        $files   = glob($feedDir . '/*en_us*.xml');
        if (empty($files)) {
            throw new \RuntimeException("未找到源 XML，目录：{$feedDir}");
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $src = $files[0];

        $dstDir  = $this->mediaPath . '/feed';
        $this->fileDriver->createDirectory($dstDir);
        $dstFile = $dstDir . '/tiktok_feed.csv';

        $fh = fopen($dstFile, 'w');
        fputcsv($fh, [
            'sku_id','title','description','images','availability',
            'condition','price','link','image_link','additional_image_link',
            'brand','item_group_id','google_product_category','product_type','gtin'
        ]);

        $xml = simplexml_load_file($src);
        foreach ($xml->channel->item as $item) {
            $g       = $item->children('g', true);
            $sku     = (string)$g->id;
            $title   = (string)$g->title;

            $htmlDesc = (string)$g->description;
            if (trim($htmlDesc) === '') {
                $htmlDesc = $this->productRepo->get($sku)->getShortDescription() ?? '';
            }

            [$plainDesc, $images] = $this->parseDescriptionHtml($htmlDesc);

            $images = array_map(
                fn($src) => "https://phayla.com/pub/media/{$src}",
                $images
            );
            $imagesCsv = implode(',', $images);

            $avail    = stripos((string)$g->availability, 'in') !== false ? 'In stock' : 'Out of stock';
            $cond     = 'New';
            preg_match('/^([A-Z]{3})([\d\.]+)/', (string)$g->price, $m);
            $price    = isset($m[2]) ? "{$m[2]} {$m[1]}" : (string)$g->price;
            $link     = (string)$g->link;
	    $link     = preg_replace('#(https?://[^/]+)/admin/#', '$1/', $link);
            $img      = (string)$g->image_link;
            $extraArr = array_slice(
                array_map(fn($i)=>(string)$i, iterator_to_array($g->additional_image_link)),
                0,5
            );
            $extraCsv = implode(',', $extraArr);
            $brand    = $this->productRepo->get($sku)->getAttributeText('brand') ?: 'DefaultBrand';
            $group    = (string)$g->item_group_id ?: $sku;
            $gpc      = (string)($g->google_product_category ?? 'Miscellaneous');
	    $gtin     = (string)($g->gtin ?? '');

            fputcsv($fh, [
                $sku, $title, $plainDesc, $imagesCsv,
                $avail, $cond, $price, $link,
                $img, $extraCsv, $brand, $group, $gpc, $gpc, $gtin
            ]);
        }
        fclose($fh);
    }

    private function parseDescriptionHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $srcs = [];
        foreach ($xpath->query('//img') as $img) {
            $srcAttr = $img->getAttribute('src');
            if ($srcAttr) {
                if (preg_match('/media\/url=([^\}]+)/', $srcAttr, $m)) {
                    $srcs[] = trim($m[1]);
                } else {
                    $srcs[] = ltrim($srcAttr, '/');
                }
            }
        }
        foreach ($xpath->query('//img') as $img) {
            $img->parentNode->removeChild($img);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = trim($body ? $body->textContent : '');
        $text = preg_replace('/\s{2,}/', ' ', $text);

        return [$text, $srcs];
    }
}
