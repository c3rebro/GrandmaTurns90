# Bootstrap assets

This directory stores the locally vendored Bootstrap files used by the PHP templates.

## Contents

- `css/bootstrap.min.css`
- `js/bootstrap.bundle.min.js`
- `LICENSE.md` (Bootstrap license)

## Update steps

1. Pick the new Bootstrap version (for example `5.3.3`).
2. Download the compiled assets and replace the files:
   - `https://cdn.jsdelivr.net/npm/bootstrap@<version>/dist/css/bootstrap.min.css`
   - `https://cdn.jsdelivr.net/npm/bootstrap@<version>/dist/js/bootstrap.bundle.min.js`
3. Update `LICENSE.md` from the matching Bootstrap tag:
   - `https://raw.githubusercontent.com/twbs/bootstrap/v<version>/LICENSE`
4. Confirm the templates in `public/*.php` still reference `assets/bootstrap/...` paths.
