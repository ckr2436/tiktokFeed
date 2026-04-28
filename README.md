# Pynarae TikTok Feed for Magento 2

This module converts the latest Magento product XML feed into a TikTok-compatible CSV feed.

## Main Features

- Generates `pub/media/feed/tiktok_feed.csv` by default.
- Uses the newest source XML matching `pub/media/run_as_root/feed/*en_us*.xml` by default.
- Runs automatically by Magento cron. Default schedule: every 2 hours.
- Provides an admin configuration page under **Stores > Configuration > Pynarae > TikTok Feed**.
- Provides a manual generation menu under **Marketing > TikTok Feed**.
- Uses Magento Base Media URL automatically. No hardcoded domain is required.
- Supports custom media/CDN URL, custom source directory, custom output filename, default brand, category fallback, and max additional images.
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
| Source XML Directory under pub/media | `run_as_root/feed` | Source feed directory. |
| Source XML Filename Pattern | `*en_us*.xml` | The newest matching XML is used. |
| Output Directory under pub/media | `feed` | Destination directory. |
| Output CSV Filename | `tiktok_feed.csv` | Destination CSV filename. |
| Custom Base Media URL | empty | Leave empty to use Magento Base Media URL. |
| Default Brand | `MYUPONA` | Fallback when brand is missing. |
| Brand Attribute Code | `brand` | Magento product attribute code. |
| Default Google Product Category | `Health & Beauty` | Fallback category. |
| Maximum Additional Images | `5` | Allowed range: 0-10. |
| Remove /admin/ from Product Links | Yes | Prevents admin URLs in the feed. |

## Manual Generation

Go to:

```text
Marketing > TikTok Feed
```

The module will immediately generate the CSV and show a success or error message.

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

## Important Notes

- This module does not call TikTok APIs directly.
- It only generates a CSV file that can be used for TikTok Catalog / product feed upload.
- The source XML feed must already exist.
- Product images are normalized to absolute URLs.
- Description HTML images are extracted into the `images` field and removed from the plain text description.
