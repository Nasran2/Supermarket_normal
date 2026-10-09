import test from 'node:test';
import assert from 'node:assert/strict';
import {exactBarcode,needsStockRow,singleStockRow} from '../resources/js/stock-adjustment-picker.js';

test('only a complete barcode auto-adds; partial scans and fuzzy names stay selectable',()=>{
 const products=[{product_id:1,name:'Milk',sku:'MILK-1',barcode:'20000000001'},{product_id:2,name:'Milk powder',barcode:'20000000002'}];
 assert.equal(exactBarcode(products,'2000000000'),undefined);
 assert.equal(exactBarcode(products,'Milk'),undefined);
 assert.equal(exactBarcode(products,'MILK-1'),undefined);
 assert.equal(exactBarcode(products,' 20000000002 ').product_id,2);
});
test('single stock row is automatic only when stock is being removed',()=>{
 const product={expected_stock:'12.500',layers:[{id:42,quantity:'12.500'}]};
 assert.equal(singleStockRow({...product,mode:'REMOVE',quantity:'2'}),'42');
 assert.equal(singleStockRow({...product,mode:'SET',quantity:'0'}),'42');
 assert.equal(singleStockRow({...product,mode:'ADD',quantity:'2'}),'');
 assert.equal(needsStockRow({...product,mode:'REMOVE',quantity:'0'}),false);
 assert.equal(needsStockRow({...product,mode:'SET',quantity:'12.500'}),false);
 assert.equal(needsStockRow({...product,mode:'SET',quantity:'15'}),false);
});
test('multiple stock prices retain an explicit selection when removing stock',()=>{
 const product={mode:'REMOVE',quantity:'1',expected_stock:'12',layers:[{id:1},{id:2}]};
 assert.equal(needsStockRow(product),true);
 assert.equal(singleStockRow(product),'');
});
