#!/usr/bin/env bash
# Builds release/kolle-registration-<version>.zip, ready to upload to a web server.
set -euo pipefail
cd "$(dirname "$0")"
VERSION=$(grep -oP "APP_VERSION', '\K[^']+" includes/bootstrap.php)
OUT="release/kolle-registration-${VERSION}.zip"
mkdir -p release
rm -f "$OUT"
zip -rq "$OUT" . -x '.git/*' 'release/*' 'docs/*' 'config.php' 'install/install.lock' 'build-package.sh' '.gitignore'
echo "Built $OUT"
