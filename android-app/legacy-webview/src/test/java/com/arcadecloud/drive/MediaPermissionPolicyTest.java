package com.arcadecloud.drive;

import org.junit.Test;
import static org.junit.Assert.*;

public final class MediaPermissionPolicyTest {
    @Test public void firstRunAsksForCapturePermissions() {
        assertTrue(MediaPermissionPolicy.shouldPromptAtLaunch(false, false, false));
        assertTrue(MediaPermissionPolicy.shouldPromptAtLaunch(false, true, false));
        assertTrue(MediaPermissionPolicy.shouldPromptAtLaunch(false, false, true));
    }

    @Test public void doesNotAskWhenAlreadyGranted() {
        assertFalse(MediaPermissionPolicy.shouldPromptAtLaunch(false, true, true));
    }

    @Test public void doesNotRepeatStartupPromptAfterRefusalOrRotation() {
        assertFalse(MediaPermissionPolicy.shouldPromptAtLaunch(true, false, false));
        assertFalse(MediaPermissionPolicy.shouldPromptAtLaunch(true, true, false));
        assertFalse(MediaPermissionPolicy.shouldPromptAtLaunch(true, false, true));
        assertFalse(MediaPermissionPolicy.shouldPromptAtLaunch(true, true, true));
    }
}
