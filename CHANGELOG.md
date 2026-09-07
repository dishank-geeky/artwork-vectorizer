# Changelog

All notable changes to this package are documented here. This project follows
[Semantic Versioning](https://semver.org/).

## [1.1.1] - 2026-09-07

### Fixed
- Allow Symfony 8. The constraints were `^6.4 || ^7.0`, which made the package
  uninstallable in any newly created Symfony project — `composer require` failed
  with "the package is fixed to v8.1.6 (lock file version)". Now
  `^6.4 || ^7.0 || ^8.0`, with the suite verified against 8.1.

## [Unreleased]

### Changed
- Documentation: both potrace and VTracer are now stated as required for
  production, not optional. They are not interchangeable — the `auto` preset
  picks between them per artwork, and with one installed every job goes through
  it whether it suits or not. Includes measured figures for both on the test
  fixture, and real install instructions for VTracer, which ships no apt/apk/brew
  package and needs its static release binary.
- CI installs VTracer as well as potrace, so the matrix exercises both engines.

### Added
- `ArtworkVectorizerBundle`, so a Symfony application gets the whole pipeline
  from `composer require` plus one line in `config/bundles.php`. Previously
  every host had to hand-write ~28 lines of service definitions, including
  knowing which four classes must be excluded from autowiring.
- Configuration tree under `artwork_vectorizer`: `tmp_dir`, `palette`,
  `palette_file`, `binaries.*` and `timeouts.*`.
- `ImageMagick::compareRmse()`.
- Test suite covering ink separation, Pantone snapping, SVG compliance and a
  deviation ceiling; CI on PHP 8.2, 8.3 and 8.4.

### Fixed
- `measureDeviation()` invoked a bare `compare` binary, ignoring the configured
  ImageMagick path. A custom install would silently return `null` instead of a
  deviation figure. It now derives the call from the resolved binary, handling
  both ImageMagick 7 (`magick compare`) and 6.x (a separate `compare`).
- `ImageMagick::resolveBinary()` cached its result in a method `static`, which
  in PHP is shared across every instance of the class. The first instance to
  resolve pinned the binary for all later ones, so a second instance
  constructed with a different path was silently ignored. Now per-instance.

### Removed
- `psr/log` from `require`; nothing in the package ever imported it.

## [1.0.0] - 2026-09-04

### Added
- Initial release: raster artwork to colour-separated, print-ready SVG, one
  editable layer per ink, snapped to a Pantone Coated palette.
