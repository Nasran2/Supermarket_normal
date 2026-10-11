# Automatic barcode scanning verification

Verified against a separate SQLite sample database, without changing the operational store database.

- A complete barcode without Enter adds the product once and clears the search.
- An exact code works while another category is selected.
- Rapid Enter scans preserve each product; repeating a barcode increases quantity.
- Products without stock tracking can be scanned.
- Name searches and Enter on a name retain search results without adding an item.
- Unknown/inactive codes do not add products. Out-of-stock products show an explanation.
- Products with different available prices still open the selling-price chooser.
- A sample cash checkout saved the correct three products and quantities. The next sale has an empty cart/search and All products selected.
- The final production build was checked in the browser with no console errors.

Automated validation: 16 JavaScript tests passed (scanner queue, price options and draft handling). The scanner settings feature test passed with 18 assertions. The full POS workflow suite reported 35 passing and 14 failing tests; running the unchanged HEAD test baseline reproduced the same 14 payment-fee-related failures. Those existing failures are outside this change.

Screenshots: `pos-automatic-barcode-cart.png` and `pos-after-sale-reset.png`.
