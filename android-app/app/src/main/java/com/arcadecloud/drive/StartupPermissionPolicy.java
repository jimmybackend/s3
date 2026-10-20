package com.arcadecloud.drive;

/**
 * Android user-consent decision logic. Permits either approximate or precise
 * foreground location, and never treats contacts as mandatory for Drive access.
 */
public final class StartupPermissionPolicy {
    private StartupPermissionPolicy() {}

    public static boolean hasLocationAccess(boolean coarseGranted, boolean preciseGranted) {
        return coarseGranted || preciseGranted;
    }

    public static boolean canEnterDrive(boolean hasLocationPermission, boolean deviceLocationEnabled) {
        return hasLocationPermission && deviceLocationEnabled;
    }

    public static boolean shouldAskContacts(boolean alreadyAsked, boolean contactsGranted) {
        return !alreadyAsked && !contactsGranted;
    }
}
