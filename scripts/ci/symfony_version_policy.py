from __future__ import annotations

import re

NON_FRAMEWORK_RELEASE_PACKAGES = frozenset(
    {
        "symfony/flex",
        "symfony/stimulus-bundle",
        "symfony/ux-turbo",
    }
)


def is_supported_symfony_package_version(name: str, version: str) -> bool:
    if not name.startswith("symfony/"):
        return True
    if name.startswith("symfony/polyfill-") or name.endswith("-contracts"):
        return True
    if name in NON_FRAMEWORK_RELEASE_PACKAGES:
        return True

    return re.fullmatch(r"v?7\.4\.[0-9]+", version) is not None
