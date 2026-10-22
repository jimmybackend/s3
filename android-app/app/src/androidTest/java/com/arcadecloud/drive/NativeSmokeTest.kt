package com.arcadecloud.drive
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
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
        var attempts = 0
        compose.setContent { ArcadeTheme { LoginScreen(false,null) { node,email,password -> attempts++; submitted = "$node|$email|$password" } } }
        compose.onNodeWithText("Iniciar sesión").assertIsDisplayed()
        compose.onNodeWithText("Correo o identificador de acceso").performTextInput("native@example.test")
        compose.onNodeWithText("Contraseña").performTextInput(" aB+&%ñ= 123 ")
        assertEquals("",submitted)
        compose.onNodeWithContentDescription("Mostrar contraseña").performClick()
        compose.onNodeWithText(" aB+&%ñ= 123 ").assertIsDisplayed()
        compose.onNodeWithContentDescription("Ocultar contraseña").performClick()
        compose.onNodeWithText("Iniciar sesión").performScrollTo().performClick()
        assertEquals("https://drive.esforzados.com/|native@example.test| aB+&%ñ= 123 ", submitted)
        // A rejected request must allow checking/retrying the same exact input.
        compose.onNodeWithText("Iniciar sesión").performClick()
        assertEquals(2, attempts)
        assertEquals("https://drive.esforzados.com/|native@example.test| aB+&%ñ= 123 ", submitted)
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
