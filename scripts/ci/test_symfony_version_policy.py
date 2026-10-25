from __future__ import annotations

import unittest

from symfony_version_policy import is_supported_symfony_package_version


class SymfonyVersionPolicyTest(unittest.TestCase):
    def test_framework_components_must_use_the_supported_lts_line(self) -> None:
        self.assertTrue(is_supported_symfony_package_version("symfony/clock", "v7.4.21"))
        self.assertFalse(is_supported_symfony_package_version("symfony/clock", "v8.1.0"))

    def test_independently_versioned_tools_and_ux_packages_are_not_framework_components(self) -> None:
        self.assertTrue(is_supported_symfony_package_version("symfony/flex", "v2.11.0"))
        self.assertTrue(is_supported_symfony_package_version("symfony/stimulus-bundle", "v2.36.0"))
        self.assertTrue(is_supported_symfony_package_version("symfony/ux-turbo", "v2.36.0"))

    def test_contracts_and_polyfills_keep_their_independent_versions(self) -> None:
        self.assertTrue(is_supported_symfony_package_version("symfony/cache-contracts", "v3.7.1"))
        self.assertTrue(is_supported_symfony_package_version("symfony/polyfill-php85", "v1.43.0"))


if __name__ == "__main__":
    unittest.main()
