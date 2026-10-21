# Settings

Stored as JSON key/value rows, cached for one hour and invalidated when updated. Business configuration remains editable without source changes. Payment methods and charge rules use normalized CRUD tables.

## Business

Optional **Appearance & branding** has separate display-name, color and login-image preferences. It is available only with `COLORS=true` and Business settings permission. See [complete appearance settings](APPEARANCE-AND-BRANDING.md). The staff guide, search entries and related instructions follow the same flag; see [documentation visibility](DOCUMENTATION.md).

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
| `logo_secondary` | Second bill logo (PNG, JPG, WEBP · max 2 MB) | `null` |

Each business detail has an independent **Show on bill** option. Hiding a detail does not erase its saved value. Enabled logos print side by side. Receipt footer and message visibility are also independent.

## Appearance and branding

| Key | Purpose | Default |
| --- | --- | --- |
| `brand_name` | Sidebar display name; blank uses business name | blank |
| `brand_subtitle` | Sidebar second name | `SUPERMARKET POS` |
| `login_brand_text` | Login heading; blank uses main name + POS | blank |
| `login_title` | Login welcome title | `Welcome back` |
| `login_description` | Login subtitle | `Sign in to continue to your POS.` |
| `theme_color` | App accent | `#047857` |
| `brand_name_color` | Main name text | `#172b27` |
| `brand_subtitle_color` | Second name text | `#96a39b` |
| `login_accent_color` | Login accent | `#0db168` |
| `login_background_color` | Login background | `#e6fcf0` |
| `login_title_color` | Welcome title | `#111827` |
| `login_description_color` | Description | `#6b7280` |
| `login_icon_mode` | Business logo, built-in icon or custom image | `business-logo` |
| `login_icon` | Selected built-in icon | `shopping-basket` |
| `login_logo` | Separate uploaded login image | `null` |

## Cheque settings and bank accounts

With `CHEQUE=true` and the appropriate permission, **Settings → Overview** has a separate **Cheque settings & bank accounts** card. It sits within the settings grid and is also available from the Settings sidebar. Manage allowed sources, required branch, attachments, alert/action days, dashboard alerts, default return reason and bank account references. Bank accounts are not bank balance ledgers. The dashboard alert is hidden when no visible pending cheque is due soon or overdue. See [cheque management](CHEQUE_MANAGEMENT.md).

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

