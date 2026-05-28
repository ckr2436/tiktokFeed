# Pynarae TikTok Feed for Magento 2

This module generates a TikTok-compatible CSV product feed directly from Magento catalog products.

## Main Features

- Generates `pub/media/feed/tiktok_feed.csv` by default.
- Reads enabled Magento catalog products directly. No source XML file is required.
- Exports visible standalone simple products and simple variants that belong to visible enabled configurable products.
- Runs automatically by Magento cron. Default schedule: every 2 hours.
- Provides an admin configuration page under **Stores > Configuration > Pynarae > TikTok Feed**.
- Provides a manual generation menu under **Marketing > TikTok Feed**.
- Uses Magento Base Media URL and Store Base URL automatically.
- Supports custom media/CDN URL, custom output filename, default brand, brand attribute, GTIN attribute, category fallback, and max additional images.
- Writes to a temporary file first, then renames it to the final CSV to avoid partially generated feed files.

## Default Output URL

If your store domain is `https://example.com`, the default CSV URL is:

```text
https://example.com/pub/media/feed/tiktok_feed.csv
```

For MYUPONA, this is expected to be:

```text
https://myupona.com/pub/media/feed/tiktok_feed.csv
```

## Installation

Place the module in:

```text
app/code/Pynarae/TiktokFeed
```

Then run:

```bash
php bin/magento module:enable Pynarae_TiktokFeed
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

For production mode, also run:

```bash
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

## Configuration

Go to:

```text
Stores > Configuration > Pynarae > TikTok Feed
```

Recommended default values:

| Field | Default | Notes |
| --- | --- | --- |
| Enable Feed Generation | Yes | Cron skips generation when disabled. |
| Cron Schedule | `0 */2 * * *` | Every 2 hours. |
| Output Directory under pub/media | `feed` | Destination directory. |
| Output CSV Filename | `tiktok_feed.csv` | Destination CSV filename. |
| Custom Base Media URL | empty | Leave empty to use Magento Base Media URL. |
| Default Brand | `MYUPONA` | Used when the product brand attribute is empty. |
| Brand Attribute Code | `brand` | Magento product attribute code. |
| GTIN Attribute Code | `gtin` | The generator also checks `upc`, `ean`, and `barcode` when GTIN is empty. |
| Default Google Product Category | `Health & Beauty` | Category fallback. |
| Maximum Additional Images | `5` | Allowed range: 0-10. |

## Manual Generation

Go to:

```text
Marketing > TikTok Feed
```

The module immediately generates the CSV from Magento catalog products and shows processed/skipped counts.

## CLI Generation

```bash
php bin/magento pynarae:tiktokfeed:generate
```

## Cron

Make sure Magento cron is installed and running:

```bash
php bin/magento cron:install
php bin/magento cron:run
```

Check logs if the feed is not generated:

```text
var/log/system.log
var/log/exception.log
```

## CSV Columns

The generated CSV contains:

```text
sku_id,title,description,images,availability,condition,price,link,image_link,additional_image_link,brand,item_group_id,google_product_category,product_type,gtin
```

## Generation Rules

- Only enabled simple products are exported.
- A simple product linked to a configurable product is exported only when the parent configurable product is enabled and visible.
- Standalone simple products are exported only when visible on the storefront.
- `item_group_id` uses the parent configurable SKU when a parent exists, otherwise it uses the product SKU.
- Product links point to the parent configurable product when a parent exists, otherwise to the simple product.
- Main image uses the child product image first, then the parent product image.
- Additional images include child gallery images, parent gallery images, and images found in product description HTML.
- Description HTML images are extracted into the image list and removed from the plain-text description.
- Stock status is exported as `In stock` or `Out of stock`.
- Price is exported as `0.00 USD` format using the store currency.

## Important Notes

- This module does not call TikTok APIs directly.
- It only generates a CSV file that can be used for TikTok Catalog / product feed upload.
- No source XML file is required.
- Product images are normalized to absolute URLs.
