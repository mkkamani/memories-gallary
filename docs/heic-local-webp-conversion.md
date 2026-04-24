# Local HEIC -> WebP Conversion (No FFmpeg, No Imagick)

This script runs on your local machine with Node.js and converts `.heic/.heif` files into `.webp` using:

- `heic-convert` (HEIC decode)
- `sharp` (WebP encode)

## Install dependencies

```bash
npm install
```

## Convert one file

```bash
npm run heic:webp:local -- --input ""C:/Users/PC-64/Downloads/IMG_7064.HEIC"" --output "C:/Users/PC-64/Downloads/converted" --quality 92
```

## Convert a full folder recursively

```bash
npm run heic:webp:local -- --input "C:/photos/heic-batch" --output "C:/photos/webp-batch" --quality 90
```

## Overwrite existing output files

```bash
npm run heic:webp:local -- --input "C:/photos/heic-batch" --output "C:/photos/webp-batch" --overwrite
```

## Notes

- This script does not require FFmpeg or PHP Imagick.
- It does not update database records by itself.
- After generating files locally, upload/deploy WebP files to your server paths as needed.
