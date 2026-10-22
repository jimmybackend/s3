package com.arcadecloud.drive
import androidx.compose.foundation.layout.Column
import androidx.compose.material3.Text
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.arcadecloud.drive.ui.*
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
@RunWith(AndroidJUnit4::class)
class NativeBrowserTest {
    @get:Rule val compose=createComposeRule()
    @Test fun navigationHasFourDestinationsInRequestedOrder() {
        var route=""
        compose.setContent { ArcadeTheme { DriveNavigationBar("files"){route=it} } }
        val labels=listOf("Archivos","Buscar","Tareas","Cuenta")
        val bounds=labels.map { compose.onNodeWithText(it).assertIsDisplayed().fetchSemanticsNode().boundsInRoot.left }
        assertTrue(bounds.zipWithNext().all{it.first<it.second})
        compose.onNodeWithText("Buscar").performClick();assertEquals("search",route)
        compose.onNodeWithText("Tareas").performClick();assertEquals("tasks",route)
    }
    @Test fun uploadsStayBelowPaginationAndCallbacksUseCurrentPage() {
        var page=0;var upload=false
        compose.setContent { ArcadeTheme { BrowserFooter(1,2,1,false,false,{p,_->page=p},{upload=true},{},{}) } }
        val pager=compose.onNodeWithText("1 / 2").fetchSemanticsNode().boundsInRoot
        val button=compose.onNodeWithContentDescription("Subir archivos").assertIsDisplayed().fetchSemanticsNode().boundsInRoot
        assertTrue(button.top>pager.bottom)
        compose.onNodeWithText("Siguiente").performClick();assertEquals(2,page)
        compose.onNodeWithContentDescription("Subir archivos").performClick();assertTrue(upload)
        compose.onNodeWithText("Buscar en esta carpeta").assertDoesNotExist()
    }
    @Test fun searchIsAnExplicitSeparateForm() {
        var query="";var type="";var requested=false
        compose.setContent { ArcadeTheme { SearchControls("","",{query=it},{type=it},{requested=true},{},false) } }
        compose.onNodeWithText("Buscar en esta carpeta").performTextInput("contrato")
        compose.onNodeWithText("Extensión: pdf, jpg…").performTextInput("pdf")
        assertEquals("contrato",query);assertEquals("pdf",type);assertFalse(requested)
        compose.onNodeWithContentDescription("Ejecutar búsqueda").performClick();assertTrue(requested)
    }
}
