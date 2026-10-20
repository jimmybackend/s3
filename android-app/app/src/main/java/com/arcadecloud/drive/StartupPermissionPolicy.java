package com.arcadecloud.drive;

/**
 * Foreground location is the only required permission for entering Drive.
 * Approximate location is accepted; no contacts or GPS coordinates are read.
 */
public final class StartupPermissionPolicy {
    private StartupPermissionPolicy() {}

    public static boolean hasLocationAccess(boolean coarseGranted, boolean preciseGranted) {
        return coarseGranted || preciseGranted;
    }

    public static boolean canEnterDrive(boolean hasLocationPermission, boolean deviceLocationEnabled) {
        return hasLocationPermission && deviceLocationEnabled;
    }
}
