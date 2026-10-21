package com.arcadecloud.drive
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.arcadecloud.drive.data.SessionVault
import com.arcadecloud.drive.ui.ArcadeTheme
import com.arcadecloud.drive.ui.LoginScreen
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
@RunWith(AndroidJUnit4::class)
class NativeSmokeTest {
    @get:Rule val compose = createComposeRule()
    @Test fun loginUsesNativeControlsAndReturnsEnteredCredentialsOnlyOnTap() {
        var submitted = ""
        compose.setContent { ArcadeTheme { LoginScreen(false,null) { node,email,password -> submitted = "$node|$email|$password" } } }
        compose.onNodeWithText("Iniciar sesión").assertIsDisplayed()
        compose.onNodeWithText("Correo").performTextInput("native@example.test")
        compose.onNodeWithText("Contraseña").performTextInput("sample-password")
        assertEquals("",submitted)
        compose.onNodeWithText("Iniciar sesión").performScrollTo().performClick()
        assertEquals("https://drive.esforzados.com/|native@example.test|sample-password", submitted)
    }
    @Test fun vaultPersistsEncryptedSessionAndCanRevokeLocally() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val vault = SessionVault(context); vault.clear()
        vault.update { it.put("cookies", "session-secret-for-test") }
        assertEquals("session-secret-for-test", SessionVault(context).read().getString("cookies"))
        val raw = context.getSharedPreferences("native_session",0).getString("sealed", "")!!
        assertFalse(raw.contains("session-secret-for-test"))
        vault.clear(); assertFalse(vault.read().has("cookies"))
    }
}
