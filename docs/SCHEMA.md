# Database schema

Generated from the installed MySQL/MariaDB database. Money is DECIMAL(15,2), quantities DECIMAL(15,3), rule values DECIMAL(15,4). Foreign keys prevent deletion of referenced history.

## audit_logs

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | Yes |
| action | varchar(255) | No |
| subject_type | varchar(255) | No |
| subject_id | bigint(20) unsigned | Yes |
| before | longtext | Yes |
| after | longtext | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## cache

| Column | Type | Nullable |
| --- | --- | --- |
| key | varchar(255) | No |
| value | mediumtext | No |
| expiration | int(11) | No |

## cache_locks

| Column | Type | Nullable |
| --- | --- | --- |
| key | varchar(255) | No |
| owner | varchar(255) | No |
| expiration | int(11) | No |

## categories

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## customers

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| phone | varchar(255) | Yes |
| email | varchar(255) | Yes |
| address | text | Yes |
| active | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |
| opening_due | decimal(15,2) | No |

## expense_categories

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| system | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## expenses

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| expense_category_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| sale_id | bigint(20) unsigned | Yes |
| sale_payment_id | bigint(20) unsigned | Yes |
| payment_method_id | bigint(20) unsigned | Yes |
| register_id | bigint(20) unsigned | Yes |
| type | varchar(255) | No |
| status | varchar(255) | No |
| expense_date | date | No |
| reference | varchar(255) | Yes |
| description | text | No |
| amount | decimal(15,2) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## failed_jobs

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| uuid | varchar(255) | No |
| connection | text | No |
| queue | text | No |
| payload | longtext | No |
| exception | longtext | No |
| failed_at | timestamp | No |

## job_batches

| Column | Type | Nullable |
| --- | --- | --- |
| id | varchar(255) | No |
| name | varchar(255) | No |
| total_jobs | int(11) | No |
| pending_jobs | int(11) | No |
| failed_jobs | int(11) | No |
| failed_job_ids | longtext | No |
| options | mediumtext | Yes |
| cancelled_at | int(11) | Yes |
| created_at | int(11) | No |
| finished_at | int(11) | Yes |

## jobs

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| queue | varchar(255) | No |
| payload | longtext | No |
| attempts | tinyint(3) unsigned | No |
| reserved_at | int(10) unsigned | Yes |
| available_at | int(10) unsigned | No |
| created_at | int(10) unsigned | No |

## migrations

| Column | Type | Nullable |
| --- | --- | --- |
| id | int(10) unsigned | No |
| migration | varchar(255) | No |
| batch | int(11) | No |

## password_reset_tokens

| Column | Type | Nullable |
| --- | --- | --- |
| email | varchar(255) | No |
| token | varchar(255) | No |
| created_at | timestamp | Yes |

## payment_charge_rules

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| payment_method_id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| minimum_amount | decimal(15,2) | No |
| maximum_amount | decimal(15,2) | Yes |
| comparison_operator | varchar(255) | No |
| charge_type | varchar(255) | No |
| charge_value | decimal(15,4) | No |
| charge_bearer | varchar(255) | Yes |
| priority | int(11) | No |
| active | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## payment_methods

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| code | varchar(255) | No |
| type | varchar(255) | No |
| charge_bearer | varchar(255) | No |
| active | tinyint(1) | No |
| display_order | int(10) unsigned | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## permission_role

| Column | Type | Nullable |
| --- | --- | --- |
| role_id | bigint(20) unsigned | No |
| permission_id | bigint(20) unsigned | No |

## permissions

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## product_units

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| product_id | bigint(20) unsigned | No |
| unit_id | bigint(20) unsigned | No |
| base_quantity | decimal(15,6) | No |
| converted_quantity | decimal(15,6) | No |
| price | decimal(15,2) | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## products

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| sku | varchar(255) | No |
| barcode | varchar(255) | Yes |
| category_id | bigint(20) unsigned | No |
| unit_id | bigint(20) unsigned | No |
| cost | decimal(15,2) | No |
| price | decimal(15,2) | No |
| stock | decimal(15,3) | No |
| low_stock | decimal(15,3) | No |
| active | tinyint(1) | No |
| image | varchar(255) | Yes |
| created_by | bigint(20) unsigned | Yes |
| updated_by | bigint(20) unsigned | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## purchase_items

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| purchase_id | bigint(20) unsigned | No |
| product_id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| unit | varchar(255) | No |
| quantity | decimal(15,3) | No |
| cost | decimal(15,2) | No |
| total | decimal(15,2) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |
| previous_cost | decimal(15,2) | Yes |
| unit_id | bigint(20) unsigned | Yes |
| base_quantity | decimal(15,3) | Yes |
| base_cost | decimal(15,2) | Yes |

## purchases

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| reference | varchar(255) | No |
| supplier_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| purchase_date | date | No |
| total | decimal(15,2) | No |
| status | varchar(255) | No |
| notes | text | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## register_movements

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| register_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| type | varchar(255) | No |
| amount | decimal(15,2) | No |
| description | varchar(255) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## registers

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| open_user_id | bigint(20) unsigned | Yes |
| opening_cash | decimal(15,2) | No |
| opened_at | timestamp | No |
| closed_at | timestamp | Yes |
| expected_cash | decimal(15,2) | Yes |
| actual_cash | decimal(15,2) | Yes |
| difference | decimal(15,2) | Yes |
| notes | text | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## roles

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| system | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sale_collections

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| sale_id | bigint(20) unsigned | No |
| register_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| payment_method_id | bigint(20) unsigned | No |
| token | char(36) | No |
| method_name | varchar(255) | No |
| method_type | varchar(255) | No |
| amount | decimal(15,2) | No |
| amount_paid | decimal(15,2) | No |
| change | decimal(15,2) | No |
| reference | varchar(255) | Yes |
| collected_at | timestamp | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sale_items

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| sale_id | bigint(20) unsigned | No |
| product_id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| sku | varchar(255) | No |
| unit | varchar(255) | No |
| quantity | decimal(15,3) | No |
| price | decimal(15,2) | No |
| cost | decimal(15,2) | No |
| total | decimal(15,2) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |
| unit_id | bigint(20) unsigned | Yes |
| base_quantity | decimal(15,3) | Yes |
| base_cost | decimal(15,2) | Yes |
| catalog_price | decimal(15,2) | Yes |
| line_subtotal | decimal(15,2) | Yes |
| line_discount | decimal(15,2) | No |
| discount_type | varchar(16) | No |
| discount_value | decimal(15,2) | No |

## sale_payments

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| sale_id | bigint(20) unsigned | No |
| payment_method_id | bigint(20) unsigned | No |
| payment_charge_rule_id | bigint(20) unsigned | Yes |
| method_name | varchar(255) | No |
| method_type | varchar(255) | No |
| rule_name | varchar(255) | Yes |
| charge_type | varchar(255) | Yes |
| charge_value | decimal(15,4) | No |
| charge_bearer | varchar(255) | Yes |
| sale_amount | decimal(15,2) | No |
| processing_charge | decimal(15,2) | No |
| customer_payable | decimal(15,2) | No |
| amount_paid | decimal(15,2) | No |
| change | decimal(15,2) | No |
| reference | varchar(255) | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sale_return_items

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| sale_return_id | bigint(20) unsigned | No |
| sale_item_id | bigint(20) unsigned | No |
| quantity | decimal(15,3) | No |
| base_quantity | decimal(15,3) | No |
| amount | decimal(15,2) | No |
| cost_total | decimal(15,2) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sale_returns

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| sale_id | bigint(20) unsigned | No |
| register_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| token | char(36) | No |
| reference | varchar(255) | No |
| reason | text | No |
| amount | decimal(15,2) | No |
| cost_total | decimal(15,2) | No |
| due_reduction | decimal(15,2) | No |
| refund_amount | decimal(15,2) | No |
| payment_method_id | bigint(20) unsigned | Yes |
| method_name | varchar(255) | Yes |
| method_type | varchar(255) | Yes |
| returned_at | timestamp | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sale_revisions

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| sale_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| token | char(36) | No |
| before | longtext | No |
| after | longtext | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sales

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| invoice | varchar(255) | No |
| checkout_token | char(36) | No |
| user_id | bigint(20) unsigned | No |
| customer_id | bigint(20) unsigned | Yes |
| register_id | bigint(20) unsigned | No |
| subtotal | decimal(15,2) | No |
| discount | decimal(15,2) | No |
| sale_amount | decimal(15,2) | No |
| processing_charge | decimal(15,2) | No |
| customer_payable | decimal(15,2) | No |
| cost_total | decimal(15,2) | No |
| status | varchar(255) | No |
| sold_at | timestamp | No |
| voided_by | bigint(20) unsigned | Yes |
| voided_at | timestamp | Yes |
| void_reason | text | Yes |
| notes | text | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## sessions

| Column | Type | Nullable |
| --- | --- | --- |
| id | varchar(255) | No |
| user_id | bigint(20) unsigned | Yes |
| ip_address | varchar(45) | Yes |
| user_agent | text | Yes |
| payload | longtext | No |
| last_activity | int(11) | No |

## settings

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| group | varchar(255) | No |
| key | varchar(255) | No |
| value | text | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## stock_adjustment_items

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| stock_adjustment_id | bigint(20) unsigned | No |
| product_id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| sku | varchar(255) | No |
| unit_id | bigint(20) unsigned | No |
| unit | varchar(255) | No |
| stock_before | decimal(15,3) | No |
| stock_after | decimal(15,3) | No |
| quantity_change | decimal(15,3) | No |
| price_before | decimal(15,2) | No |
| price_after | decimal(15,2) | No |
| cost_before | decimal(15,2) | No |
| cost_after | decimal(15,2) | No |
| price_changed | tinyint(1) | No |
| cost_changed | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## stock_adjustments

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| reference | varchar(255) | No |
| reason | varchar(255) | No |
| user_id | bigint(20) unsigned | No |
| status | varchar(16) | No |
| revision | int(10) unsigned | No |
| voided_at | timestamp | Yes |
| voided_by | bigint(20) unsigned | Yes |
| void_reason | text | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## stock_movements

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| product_id | bigint(20) unsigned | No |
| user_id | bigint(20) unsigned | No |
| quantity | decimal(15,3) | No |
| balance | decimal(15,3) | No |
| reason | varchar(255) | No |
| reference | varchar(255) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## suppliers

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| phone | varchar(255) | Yes |
| email | varchar(255) | Yes |
| address | text | Yes |
| active | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## unit_preset_conversions

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| unit_preset_id | bigint(20) unsigned | No |
| unit_id | bigint(20) unsigned | No |
| base_quantity | decimal(15,6) | No |
| converted_quantity | decimal(15,6) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## unit_presets

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| unit_id | bigint(20) unsigned | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## units

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| short_name | varchar(20) | No |
| allow_decimal | tinyint(1) | No |
| active | tinyint(1) | No |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |

## users

| Column | Type | Nullable |
| --- | --- | --- |
| id | bigint(20) unsigned | No |
| name | varchar(255) | No |
| email | varchar(255) | No |
| email_verified_at | timestamp | Yes |
| password | varchar(255) | No |
| remember_token | varchar(100) | Yes |
| created_at | timestamp | Yes |
| updated_at | timestamp | Yes |
| role_id | bigint(20) unsigned | Yes |
| active | tinyint(1) | No |
| username | varchar(64) | Yes |
