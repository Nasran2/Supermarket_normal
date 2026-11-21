import test from 'node:test';
import assert from 'node:assert/strict';
import { baminiToUnicode as convert } from '../resources/js/bamini.js';
import { createProductNameState } from '../resources/js/product-name.js';
import { matchesProductSearch } from '../resources/js/product-search.js';

test('Bamini examples, independent vowels, consonants and Grantha combinations', () => {
  assert.equal(convert('tzf;fk; jkpo;'), 'வணக்கம் தமிழ்');
  assert.equal(convert('m M , < c C v V I x X xs /'), 'அ ஆ இ ஈ உ ஊ எ ஏ ஐ ஒ ஓ ஔ ஃ');
  for (const [key, letter] of Object.entries({ f:'க', q:'ங', r:'ச', Q:'ஞ', l:'ட', z:'ண', j:'த', e:'ந', g:'ப', k:'ம', a:'ய', u:'ர', y:'ல', t:'வ', o:'ழ', s:'ள', w:'ற', d:'ன', '[':'ஜ', '\\':'ஷ', ']':'ஸ', '`':'ஹ', 'f;\\':'க்ஷ' })) {
    for (const [raw, sign] of [[key,''], [key+'h','ா'], [key+'p','ி'], [key+'P','ீ'], ['n'+key,'ெ'], ['N'+key,'ே'], ['i'+key,'ை'], ['n'+key+'h','ொ'], ['N'+key+'h','ோ'], ['n'+key+'s','ௌ'], [key+';','்']]) {
      assert.equal(convert(raw), letter + sign, raw);
    }
  }
  assert.equal(convert('F $ R # L ^ Z Z} J J} E E} G G+ K % A A+ U & Y Y} T T+ O * S Sh W W} D D}'),
    'கு கூ சு சூ டு டூ ணு ணூ து தூ நு நூ பு பூ மு மூ யு யூ ரு ரூ லு லூ வு வூ ழு ழூ ளு ளூ று றூ னு னூ');
  assert.equal(convert('q{ q_ Qc Qc} [{ [_ \\{ \\_ ]{ ]_ `{ `_ = hp hP h; H'), 'ஙு ஙூ ஞு ஞூ ஜு ஜூ ஷு ஷூ ஸு ஸூ ஹு ஹூ ஸ்ரீ ரி ரீ ர் ர்');
  assert.equal(convert('nts;sk;'), 'வெள்ளம்');
});

test('English mode preserves input and temporary switching preserves both raw and Unicode', () => {
  const state = createProductNameState();
  state.input('Tea 200g');
  assert.equal(state.value, 'Tea 200g');
  state.switchMode('bamini');
  assert.equal(state.value, 'Tea 200g');
  state.input('Tea 200g tzf;fk;');
  assert.equal(state.value, 'Tea 200g வணக்கம்');
  state.switchMode('english');
  assert.equal(state.text, 'Tea 200g வணக்கம்');
  state.switchMode('bamini');
  assert.equal(state.text, 'Tea 200g tzf;fk;');
  assert.equal(state.value, 'Tea 200g வணக்கம்');
});

test('partial input, deletion, replacement and paste are recalculated from raw text', () => {
  const state = createProductNameState('', 'bamini');
  for (const [text, value] of [['n','ெ'], ['nf','கெ'], ['nfh','கொ'], ['nf','கெ'], ['Nf','கே'], ['tzf;fk;','வணக்கம்'], ['jkpo;','தமிழ்']]) {
    state.input(text);
    assert.equal(state.value, value);
    assert.equal(state.text, text);
  }
});

test('unchanged existing Unicode and English names remain literal, including decomposed Unicode', () => {
  const original = 'கொ Tea 200g';
  const state = createProductNameState(original, 'bamini');
  assert.equal(state.value, original);
  state.input(original + ' jkpo;');
  assert.equal(state.value, original + ' தமிழ்');
  state.input('jkpo;');
  assert.equal(state.value, 'தமிழ்');
  assert.equal(convert('கொ'), 'கொ');
});

test('selection ranges preserve literal characters when identical keys are inserted', () => {
  const state = createProductNameState('ff', 'bamini');
  state.input('fff', {start:0, end:0});
  assert.equal(state.value, 'கff');
  state.input('ff', {start:0, end:1});
  assert.equal(state.value, 'ff');
  state.input('fff', {start:0, end:0});
  state.input('ff', {start:1, end:1, inputType:'deleteContentBackward'});
  assert.equal(state.value, 'ff');
});

test('Quick Add prefills stay literal and new Bamini typing still converts', () => {
  const state = createProductNameState('', 'bamini');
  state.prefill('தமிழ் Part');
  state.input('தமிழ் Part f;');
  assert.equal(state.value, 'தமிழ் Part க்');
});

test('purchase product searches accept English, Unicode and case-sensitive Bamini without converting codes', () => {
  const tamil = { name: 'வணக்கம் தமிழ் கோ', sku: 'TAMIL-SEARCH', barcode: '1234567890123' };
  for (const query of ['tz', 'tzf;fk;', 'jkpo;', 'Nfh', 'தமிழ்', 'TAMIL-SEARCH', '1234567890123']) {
    assert.equal(matchesProductSearch(tamil, query, true), true, query);
  }
  assert.equal(matchesProductSearch(tamil, 'tz', false), false);
  assert.equal(matchesProductSearch({ name: 'Tea 200g' }, 'TEA', true), true);
  assert.equal(matchesProductSearch({ name: 'Other', sku: 'வணக்கம்', barcode: 'தமிழ்' }, 'tzf;fk;', true), false);
});
