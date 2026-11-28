# Noto Sans Bengali (self-hosted)

- Licence: SIL Open Font License 1.1 (`OFL.txt`), Copyright The Noto Project Authors.
- Source: `ofl/notosansbengali/NotoSansBengali[wdth,wght].ttf` from
  https://github.com/google/fonts (downloaded 2026-11-28).
- `NotoSansBengali-wght.woff2` is a variable font: width fixed at 100,
  weight 400–800, Bengali characters only, every OpenType layout feature kept
  so conjuncts and vowel signs shape correctly. Latin text uses the system font.

Rebuild with fonttools and brotli (`pip install fonttools brotli`):

```sh
fonttools varLib.instancer 'NotoSansBengali[wdth,wght].ttf' wdth=100 wght=400:800 -o instanced.ttf
pyftsubset instanced.ttf \
    --unicodes='U+0020,U+00A0,U+0951-0952,U+0964-0965,U+0980-09FE,U+1CD0-1CF9,U+200C-200D,U+20B9,U+25CC,U+A8F1' \
    --layout-features='*' --flavor=woff2 --output-file=NotoSansBengali-wght.woff2
```

The `@font-face` rule is in `src/styles/fonts.css`.
