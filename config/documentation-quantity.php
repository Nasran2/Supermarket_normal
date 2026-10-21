<?php

// Written guidance used when the optional unit module is disabled.
return [
    'make-a-sale' => ['steps' => [2 => ['text' => 'Use + or − beside the product, or enter the quantity. Scan again to add another of the same item. A product default discount is added automatically. Check any line discount under that item; a fixed amount is taken off the whole line.']]],
    'receive-a-purchase' => ['steps' => [1 => ['text' => 'Search or scan each product. Enter the actual quantity received. Add a new product first if it is missing from the product list.'], 2 => ['text' => 'Cost is what the store pays for each item. Selling price is what the customer pays. Check both before continuing. New delivery prices do not rewrite old stock prices.']]],
    'add-a-product' => ['description' => 'Give an item a name, starting stock and selling price.', 'steps' => [3 => ['text' => 'Opening stock means the goods already in your shop. Enter quantity, cost and selling price. Use Add another price for a separate opening price row. If Maintain stock is off, enter only cost and selling price. Do not enter a supplier delivery twice.']]],
    'common-questions' => ['steps' => [5 => ['text' => 'Check quantity, price, discounts, payment charges and selected dates. For returns, also check original due reduction and any replacement value. If still unsure, show the bill number to the manager.']]],
    'default-product-discounts' => ['steps' => [2 => ['text' => 'Choose Fixed amount and enter the amount, such as 10. Two items at Rs. 100 make Rs. 200 before discount. Rs. 10 is removed once from that whole line, so the customer pays Rs. 190.']]],
    'maintain-product-stock' => ['steps' => [0 => ['text' => 'Keep Maintain stock ticked for goods you count. Enter the correct opening quantity when creating the product.'], 2 => ['text' => 'Check the quantity, price and discount, then save. Correct counted goods through a stock adjustment.']]],
];
