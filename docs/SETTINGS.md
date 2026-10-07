# Settings

Stored as JSON key/value rows, cached for one hour and invalidated when updated. Business configuration remains editable without source changes. Payment methods and charge rules use normalized CRUD tables.

## Business

| Key | Label | Initial default |
| --- | --- | --- |
| `business_name` | Business name | `"Twinsofte Supermarket"` |
| `legal_name` | Legal name | `""` |
| `address` | Address | `""` |
| `city` | City | `""` |
| `phone` | Telephone | `""` |
| `mobile` | Mobile | `""` |
| `whatsapp` | WhatsApp | `""` |
| `email` | Email | `""` |
| `website` | Website | `""` |
| `registration_number` | Registration number | `""` |
| `tax_number` | Tax / VAT number | `""` |
| `currency` | Currency code | `"LKR"` |
| `currency_symbol` | Currency symbol | `"Rs."` |
| `logo` | Business logo (PNG, JPG, WEBP · max 2 MB) | `null` |

## Pos

| Key | Label | Initial default |
| --- | --- | --- |
| `invoice_prefix` | Invoice prefix | `"INV-"` |
| `next_invoice_number` | Next invoice number | `1` |
| `default_payment_method` | Default payment method | `null` |
| `allow_discount` | Allow discounts | `true` |
| `max_discount_percent` | Maximum cashier discount (%) | `10` |
| `auto_print` | Automatically print receipt | `false` |
| `show_receipt` | Show receipt after sale | `true` |
| `barcode_enabled` | Enable barcode scanner | `true` |
| `search_mode` | Product search mode | `"all"` |
| `default_customer` | Default customer (optional) | `null` |

## Receipt

| Key | Label | Initial default |
| --- | --- | --- |
| `paper_width` | Thermal paper width | `"80"` |
| `show_logo_receipt` | Show logo on receipt | `true` |
| `show_cashier` | Show cashier | `true` |
| `show_customer` | Show customer | `true` |
| `show_sku` | Show barcode / SKU | `false` |
| `show_unit` | Show units | `true` |
| `show_payment_method` | Show payment method | `true` |
| `show_surcharge` | Show customer processing charge detail | `true` |
| `show_merchant_fee` | Show internal business-paid fee amount | `false` |
| `receipt_footer` | Receipt footer | `"Thank you for shopping with us."` |
| `receipt_message` | Custom receipt message | `""` |

## Stock

| Key | Label | Initial default |
| --- | --- | --- |
| `sell_zero_stock` | Allow selling products with zero stock | `false` |
| `negative_stock` | Allow negative stock | `false` |

## System

| Key | Label | Initial default |
| --- | --- | --- |
| `timezone` | Time zone | `"Asia\/Colombo"` |
| `date_format` | Date format | `"d\/m\/Y"` |
| `time_format` | Time format | `"h:i A"` |
| `number_decimals` | Minimum money display decimals (fractions stay visible) | `2` |
| `quantity_decimals` | Quantity decimal places | `3` |

