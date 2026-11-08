const formats = ['code_128', 'ean_13', 'ean_8', 'upc_a'];

function usableBarcode(value, format) {
  const code = value.trim();
  return formats.includes(format) && code && code.length <= 255 ? { value: code, format } : null;
}

export function alternateRetailCode({ value, format }) {
  if (format === 'upc_a' && /^\d{12}$/.test(value)) return `0${value}`;
  if (format === 'ean_13' && /^0\d{12}$/.test(value)) return value.slice(1);
  return null;
}

// A held label has to be absent for several frames before it can add another item.
export function createScanGate() {
  const held = new Map();
  let previous = new Set();
  return (barcodes, time) => {
    const keys = barcodes.map((barcode) => {
      const alternate = alternateRetailCode(barcode);
      return alternate && barcode.format === 'upc_a' ? alternate : barcode.value;
    });
    const visible = new Set(keys);
    for (const [key, absentSince] of held) {
      if (visible.has(key)) held.set(key, null);
      else if (absentSince === null) held.set(key, time);
      else if (time - absentSince >= 1000) held.delete(key);
    }
    const index = barcodes.findIndex(
      (barcode, i) =>
        !held.has(keys[i]) && (barcode.format !== 'code_128' || previous.has(barcode.value)),
    );
    previous = new Set(
      barcodes.filter(({ format }) => format === 'code_128').map(({ value }) => value),
    );
    if (index === -1) return null;
    held.set(keys[index], null);
    return barcodes[index];
  };
}

async function nativeDetector() {
  try {
    const Detector = globalThis.BarcodeDetector;
    if (!Detector) return null;
    const supported = await Detector.getSupportedFormats();
    const available = formats.filter((format) => supported.includes(format));
    return available.includes('code_128') ? new Detector({ formats: available }) : null;
  } catch {
    return null;
  }
}

export async function createFrameDecoder(video, frame) {
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', { willReadFrequently: true });
  if (!context) throw new Error('Canvas is unavailable.');
  let native = await nativeDetector();
  let fallback;
  return async () => {
    // Match the scan frame in the object-fit: cover preview, even for portrait video.
    const scale = Math.max(
      video.clientWidth / video.videoWidth,
      video.clientHeight / video.videoHeight,
    );
    const width = Math.round(frame.clientWidth / scale);
    const height = Math.round(frame.clientHeight / scale);
    if (!(width > 0 && height > 0)) return [];
    const resolution = Math.min(1, 1440 / width, 640 / height);
    const targetWidth = Math.max(1, Math.round(width * resolution));
    const targetHeight = Math.max(1, Math.round(height * resolution));
    if (canvas.width !== targetWidth || canvas.height !== targetHeight) {
      canvas.width = targetWidth;
      canvas.height = targetHeight;
    }
    context.drawImage(
      video,
      (video.videoWidth - width) / 2,
      (video.videoHeight - height) / 2,
      width,
      height,
      0,
      0,
      targetWidth,
      targetHeight,
    );

    let detected = [];
    if (native) {
      let timer;
      try {
        const results = await Promise.race([
          native.detect(canvas),
          new Promise((_, reject) => {
            timer = setTimeout(reject, 400);
          }),
        ]);
        detected = results
          .map(({ rawValue, format }) => usableBarcode(rawValue, format))
          .filter(Boolean);
      } catch {
        native = null;
      } finally {
        clearTimeout(timer);
      }
    }
    if (!detected.length) {
      fallback ??= import('./pos-camera-zxing.js').then(({ createZXingDecoder }) =>
        createZXingDecoder(),
      );
      const decode = await fallback;
      const barcode = decode(context.getImageData(0, 0, targetWidth, targetHeight));
      const usable = barcode && usableBarcode(barcode.value, barcode.format);
      if (usable) detected.push(usable);
    }
    return detected.sort(
      (a, b) => Number(b.format === 'code_128') - Number(a.format === 'code_128'),
    );
  };
}
