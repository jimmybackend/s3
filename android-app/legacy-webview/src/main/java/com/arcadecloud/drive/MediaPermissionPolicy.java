package com.arcadecloud.drive;

/**
 * Controls the initial capture permission request separately from permissions
 * requested later through an explicit recording gesture in the Drive UI.
 */
public final class MediaPermissionPolicy {
    private MediaPermissionPolicy() {}

    public static boolean shouldPromptAtLaunch(boolean alreadyAsked,
                                               boolean cameraGranted,
                                               boolean microphoneGranted) {
        return !alreadyAsked && (!cameraGranted || !microphoneGranted);
    }
}
