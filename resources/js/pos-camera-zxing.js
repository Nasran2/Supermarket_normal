import BarcodeFormat from '@zxing/library/esm/core/BarcodeFormat.js';
import BinaryBitmap from '@zxing/library/esm/core/BinaryBitmap.js';
import DecodeHintType from '@zxing/library/esm/core/DecodeHintType.js';
import GlobalHistogramBinarizer from '@zxing/library/esm/core/common/GlobalHistogramBinarizer.js';
import MultiFormatOneDReader from '@zxing/library/esm/core/oned/MultiFormatOneDReader.js';
import NotFoundException from '@zxing/library/esm/core/NotFoundException.js';
import RGBLuminanceSource from '@zxing/library/esm/core/RGBLuminanceSource.js';

export function createZXingDecoder() {
  const formats = new Map([
    [BarcodeFormat.CODE_128, 'code_128'],
    [BarcodeFormat.EAN_13, 'ean_13'],
    [BarcodeFormat.EAN_8, 'ean_8'],
    [BarcodeFormat.UPC_A, 'upc_a'],
  ]);
  const hints = new Map([[DecodeHintType.POSSIBLE_FORMATS, [...formats.keys()]]]);
  const reader = new MultiFormatOneDReader(hints);
  let grayscale = new Uint8ClampedArray();
  return ({ data, width, height }) => {
    if (grayscale.length !== width * height) grayscale = new Uint8ClampedArray(width * height);
    for (let i = 0, pixel = 0; i < data.length; i += 4, pixel++) {
      grayscale[pixel] = (data[i] + 2 * data[i + 1] + data[i + 2]) >> 2;
    }
    const bitmap = new BinaryBitmap(
      new GlobalHistogramBinarizer(new RGBLuminanceSource(grayscale, width, height)),
    );
    try {
      const result = reader.decode(bitmap, hints);
      return { value: result.getText(), format: formats.get(result.getBarcodeFormat()) };
    } catch (error) {
      if (!(error instanceof NotFoundException)) throw error;
      return null;
    }
  };
}
