<?php

namespace Drakelid\NmsDashWidgets\Support;

use Composer\InstalledVersions;

/**
 * The installed package version, ready to display.
 *
 * Read from composer's runtime data rather than a `version` field in composer.json.
 * That field is discouraged for VCS-published packages precisely because it drifts from
 * the git tag -- v1.1.0 once shipped while composer.json still claimed 1.0.1.
 */
final class Version
{
    public const PACKAGE = 'drakelid/librenms-dashboard-widgets';

    /** Shown when the package was not installed through composer. */
    public const FALLBACK = 'dev';

    public static function current(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return self::FALLBACK;
        }

        try {
            return self::format((string) InstalledVersions::getPrettyVersion(self::PACKAGE));
        } catch (\Throwable) {
            // Not installed via composer (a development checkout, for example).
            return self::FALLBACK;
        }
    }

    /**
     * Normalise a raw version string for display.
     *
     * getPrettyVersion() reports the git tag verbatim, so a tag of `v1.1.2` arrives with
     * its own leading "v" -- templates must not add another, which is what produced
     * "vv1.1.2". Exactly one "v" is added here, and only for versions that start with a
     * digit, so a branch install stays readable as "dev-main" rather than "vdev-main".
     */
    public static function format(string $version): string
    {
        $version = trim($version);

        if ($version === '') {
            return self::FALLBACK;
        }

        $bare = preg_replace('/^v(?=\d)/i', '', $version) ?? $version;

        return preg_match('/^\d/', $bare) === 1 ? 'v' . $bare : $bare;
    }
}
