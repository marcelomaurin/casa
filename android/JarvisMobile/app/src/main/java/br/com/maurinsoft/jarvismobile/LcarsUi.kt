package br.com.maurinsoft.jarvismobile

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp

object LcarsColors {
    val Background = Color(0xFFF2EADC)
    val Surface = Color(0xFFFFFAF0)
    val Ink = Color(0xFF221F22)
    val Muted = Color(0xFF665F62)
    val Orange = Color(0xFFE58A55)
    val Salmon = Color(0xFFD96F78)
    val Lavender = Color(0xFF9B83AD)
    val Blue = Color(0xFF6F8CA8)
    val Gold = Color(0xFFD3A04D)
    val Green = Color(0xFF6F987E)
}

@Composable
fun LcarsFrame(
    title: String,
    subtitle: String,
    user: String,
    canBack: Boolean,
    onBack: () -> Unit,
    onHome: () -> Unit,
    onLogout: () -> Unit,
    content: @Composable ColumnScope.() -> Unit
) {
    val context = LocalContext.current
    Surface(color = LcarsColors.Background, modifier = Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize().safeDrawingPadding().padding(12.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text(subtitle, style = MaterialTheme.typography.bodySmall)
            if (user.isNotBlank()) Text(user, style = MaterialTheme.typography.labelSmall)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                if (canBack) TextButton(onClick = onBack) { Text("Voltar") }
                TextButton(onClick = onHome) { Text("Início") }
                if (user.isNotBlank()) TextButton(onClick = {
                    context.startActivity(Intent(context, DashboardActivity::class.java))
                }) { Text("Painel") }
                if (user.isNotBlank()) TextButton(onClick = {
                    if (MobileAuth.hasInstallationLink(context)) (context as? android.app.Activity)?.finish()
                    else onLogout()
                }) { Text("Fechar") }
            }
            Column(Modifier.fillMaxWidth().weight(1f), verticalArrangement = Arrangement.spacedBy(10.dp), content = content)
        }
    }
}

@Composable
private fun LcarsRailButton(text: String, color: Color, onClick: () -> Unit) {
    Box(
        Modifier.width(82.dp).height(52.dp).background(color, RoundedCornerShape(topEnd = 22.dp, bottomEnd = 22.dp))
            .clickable { onClick() },
        contentAlignment = Alignment.Center
    ) {
        Text(text, color = LcarsColors.Ink, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, style = MaterialTheme.typography.labelSmall)
    }
}

@Composable
fun LcarsMenuButton(
    title: String,
    subtitle: String = "",
    color: Color,
    onClick: () -> Unit
) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 66.dp).background(color, RoundedCornerShape(28.dp, 8.dp, 8.dp, 28.dp))
            .clickable { onClick() }.padding(horizontal = 18.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.width(8.dp).fillMaxHeight().background(LcarsColors.Ink.copy(alpha = 0.18f), RoundedCornerShape(4.dp)))
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(title.uppercase(), fontWeight = FontWeight.Black, color = LcarsColors.Ink)
            if (subtitle.isNotBlank()) Text(subtitle, style = MaterialTheme.typography.bodySmall, color = LcarsColors.Ink.copy(alpha = 0.78f))
        }
        Text("›", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black, color = LcarsColors.Ink)
    }
}

@Composable
fun LcarsSectionLabel(text: String, color: Color = LcarsColors.Lavender) {
    Row(Modifier.fillMaxWidth().height(34.dp)) {
        Box(Modifier.width(72.dp).fillMaxHeight().background(color, RoundedCornerShape(topEnd = 16.dp, bottomEnd = 16.dp)))
        Spacer(Modifier.width(8.dp))
        Box(Modifier.weight(1f).fillMaxHeight(), contentAlignment = Alignment.CenterStart) {
            Text(text.uppercase(), fontWeight = FontWeight.Bold, color = LcarsColors.Ink)
        }
    }
}