package com.arcadecloud.drive;

import org.junit.Test;

import static org.junit.Assert.*;

public final class StartupPermissionPolicyTest {
    @Test public void acceptsCoarseOrFineForegroundLocation() {
        assertTrue(StartupPermissionPolicy.hasLocationAccess(true, false));
        assertTrue(StartupPermissionPolicy.hasLocationAccess(false, true));
        assertTrue(StartupPermissionPolicy.hasLocationAccess(true, true));
        assertFalse(StartupPermissionPolicy.hasLocationAccess(false, false));
    }

    @Test public void missingPermissionOrServicesBlockAppUsage() {
        assertFalse(StartupPermissionPolicy.canEnterDrive(false, true));
        assertFalse(StartupPermissionPolicy.canEnterDrive(true, false));
        assertFalse(StartupPermissionPolicy.canEnterDrive(false, false));
        assertTrue(StartupPermissionPolicy.canEnterDrive(true, true));
    }

    @Test public void contactsRequestIsOneTimeAndOptionalForEntry() {
        assertTrue(StartupPermissionPolicy.shouldAskContacts(false, false));
        assertFalse(StartupPermissionPolicy.shouldAskContacts(true, false));
        assertFalse(StartupPermissionPolicy.shouldAskContacts(false, true));
        // Contacts do not affect the ability to access Drive.
        assertTrue(StartupPermissionPolicy.canEnterDrive(true, true));
    }
}
