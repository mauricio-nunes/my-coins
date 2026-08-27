set -e

test -z "$(git status --porcelain)" || {
  echo "O repositório possui alterações não commitadas. Interrompendo o release."
  exit 1
}

LOCAL_RELEASE_DIR="$(mktemp -d -t my-coins-ubuntu.XXXXXX)"
git archive HEAD | tar -x -C "$LOCAL_RELEASE_DIR"

APP_UID="$(id -u)" APP_GID="$(id -g)" docker-compose run --rm --no-deps \
  -v "$LOCAL_RELEASE_DIR:/release" \
  app composer --working-dir=/release install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

npm ci --prefix "$LOCAL_RELEASE_DIR"
npm run build --prefix "$LOCAL_RELEASE_DIR"

test -f "$LOCAL_RELEASE_DIR/vendor/autoload.php"
test -f "$LOCAL_RELEASE_DIR/public/build/manifest.json"
test ! -f "$LOCAL_RELEASE_DIR/public/hot"

tar --exclude='./node_modules' --exclude='./tests' \
  -czf my-coins-release.tar.gz -C "$LOCAL_RELEASE_DIR" .

tar -tzf my-coins-release.tar.gz | grep -Fx './vendor/autoload.php'
tar -tzf my-coins-release.tar.gz | grep -Fx './public/build/manifest.json'

sha256sum my-coins-release.tar.gz
echo "Release criado e validado com sucesso."