// Bamini glyph assignments verified against aril by Sanjaiyan Parthipan:
// https://github.com/digital-tamil/aril/blob/main/crates/aril-core/src/encodings/bamini.rs
// The complete table includes Tamil, Grantha, க்ஷ and alternate legacy glyphs.
import mapping from '../data/bamini.json' with { type: 'json' };

const keys = Object.keys(mapping).sort((a, b) => b.length - a.length);

export function baminiToUnicode(raw) {
  let result = '';
  for (let offset = 0; offset < raw.length;) {
    const key = keys.find((candidate) => {
      if (!raw.startsWith(candidate, offset)) return false;
      const next = raw[offset + candidate.length];
      // A following sign makes s a separate ள (e.g. nts;sk; = வெள்ளம்).
      if ((candidate.startsWith('n') || candidate === 'xs') && candidate.endsWith('s') && /[hpP;{_+]/.test(next ?? '')) return false;
      // hp / hP / h; are alternate ரி / ரீ / ர் glyphs.
      if (candidate.endsWith('h') && /[pP;]/.test(next ?? '')) return false;
      return true;
    });
    if (key) {
      result += mapping[key];
      offset += key.length;
    } else {
      // Pass existing Unicode through exactly; never normalize saved names.
      result += raw[offset++];
    }
  }
  return result;
}
