<?php

declare(strict_types=1);

namespace HouseholdTracker;

/**
 * The deployed site's own domain root -- distinct from APP_URL, which
 * includes the PHP app's fixed '/app' path prefix (see "Deployment" in
 * the top-level README). Used anywhere a link needs to point at the
 * static frontend rather than a php-app/ route.
 */
final class SiteUrl
{
    public static function root(): string
    {
        $siteUrl = trim((string) Config::get('SITE_URL', ''));
        if ($siteUrl !== '') {
            return rtrim($siteUrl, '/');
        }

        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        return str_ends_with($appUrl, '/app') ? substr($appUrl, 0, -4) : $appUrl;
    }
}
