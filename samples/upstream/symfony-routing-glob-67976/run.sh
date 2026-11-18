#!/usr/bin/env bash
# Reproduce the upstream Symfony behavior from PR #67976.
#
# Source: https://github.com/symfony/symfony/pull/67976
# Merge commit: b82dd4e6f9eef23fe12f5a5512bdb0a70462d1c0 (6.4 branch)
# License: MIT (see LICENSE)
#
# This harness downloads the monorepo tarball at the frozen merge commit,
# extracts the production components changed/consumed by the PR, installs the
# locked dependencies, and exercises the changed classes directly.
#
# It is not the upstream PHPUnit test suite. It is a minimal harness over the
# frozen upstream production code; independence remains SELF_CHECKED.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
commit="b82dd4e6f9eef23fe12f5a5512bdb0a70462d1c0"
work="$(mktemp -d /tmp/xg-symfony-67976-XXXXXX)"

cleanup() {
  if [[ "${KEEP_WORKDIR:-0}" != "1" ]]; then
    rm -rf "$work"
  else
    echo "workdir kept: $work"
  fi
}
trap cleanup EXIT

echo "source_commit=$commit"

curl -sS -L --retry 3 --retry-delay 2 -m 300 -o "$work/symfony.tar.gz" \
  "https://codeload.github.com/symfony/symfony/tar.gz/$commit"

expected_tarball_sha="1bdc0d216a8f119ed9b27dabee4ae2a476fd05239f58ab44a66353bc6123f9e5"
actual_tarball_sha="$(sha256sum "$work/symfony.tar.gz" | awk '{print $1}')"
if [[ "$actual_tarball_sha" != "$expected_tarball_sha" ]]; then
  echo "tarball hash mismatch: $actual_tarball_sha" >&2
  exit 1
fi
echo "sha256 symfony.tar.gz=$actual_tarball_sha"

tar -xzf "$work/symfony.tar.gz" -C "$work" \
  "symfony-$commit/src/Symfony/Component/DependencyInjection" \
  "symfony-$commit/src/Symfony/Component/Routing" \
  "symfony-$commit/src/Symfony/Component/Config" \
  "symfony-$commit/src/Symfony/Component/Filesystem" \
  "symfony-$commit/src/Symfony/Component/VarExporter"

root="$work/symfony-$commit/src/Symfony/Component"
mv "$root/DependencyInjection" "$work/dependency-injection"
mv "$root/Routing" "$work/routing"
mv "$root/Config" "$work/config"
mv "$root/Filesystem" "$work/filesystem"
mv "$root/VarExporter" "$work/var-exporter"

cp "$here/composer.json" "$here/composer.lock" "$here/reproduce.php" "$work/"
cp "$here/LICENSE" "$work/LICENSE"

cd "$work"
composer install --no-interaction --no-progress

php reproduce.php | sed -E 's#/tmp/xg-symfony-67976-[A-Za-z0-9]+#<workdir>#g'
