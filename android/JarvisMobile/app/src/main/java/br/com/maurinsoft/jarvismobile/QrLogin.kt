package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.journeyapps.barcodescanner.ScanOptions
import com.google.zxing.BinaryBitmap
import com.google.zxing.MultiFormatReader
import com.google.zxing.RGBLuminanceSource
import com.google.zxing.common.HybridBinarizer
import org.json.JSONObject

/**
 * Acesso ao Casa Mobile por QR Code.
 *
 * Formatos aceitos:
 *  - Pareamento (padrão): {"t":"casa-pair","v":1,"url":...,"code":...}, gerado em
 *    Segurança › Acessos pessoais. Código de uso único que vira uma credencial do celular.
 *  - Legado: chave de API em JSON {"url","token","name"}, URL com ?token= ou texto puro.
 */
object QrLogin {
    fun scanOptions(): ScanOptions = ScanOptions().apply {
        setPrompt("Posicione o QR Code da CASA dentro do quadrado")
        setBeepEnabled(true)
        setBarcodeImageEnabled(true)
        setOrientationLocked(false)
        setDesiredBarcodeFormats(ScanOptions.QR_CODE)
        setCaptureActivity(QrCaptureActivity::class.java)
    }

    data class Result(val session: MobileAuth.Session, val baseUrl: String, val paired: Boolean)

    /** Lê um QR Code de uma imagem da galeria. Executar fora da thread principal. */
    fun decodeImage(context: Context, uri: Uri): String? = runCatching {
        context.contentResolver.openInputStream(uri)?.use { stream ->
            val bitmap = BitmapFactory.decodeStream(stream) ?: return@use null
            val pixels = IntArray(bitmap.width * bitmap.height)
            bitmap.getPixels(pixels, 0, bitmap.width, 0, 0, bitmap.width, bitmap.height)
            val source = RGBLuminanceSource(bitmap.width, bitmap.height, pixels)
            MultiFormatReader().decode(BinaryBitmap(HybridBinarizer(source)))?.text?.takeIf { it.isNotBlank() }
        }
    }.getOrNull()

    /** Autentica a partir do conteúdo do QR. Executar fora da thread principal. */
    fun login(context: Context, scannedText: String, fallbackUrl: String): Result {
        check(!MobileAuth.hasInstallationLink(context)) {
            "Este celular já está vinculado. Use Ajustes para desvincular antes de ler outro QR Code."
        }
        MobileAuth.parsePairingQr(scannedText)?.let { qr ->
            return Result(MobileAuth.pairWithCode(context, qr), qr.baseUrl, true)
        }
        var url = fallbackUrl
        var token: String
        var name = ""
        val text = scannedText.trim()
        when {
            text.startsWith("{") && text.endsWith("}") -> {
                val json = JSONObject(text)
                url = json.optString("url", url)
                token = json.optString("token", "")
                name = json.optString("name", "")
            }
            text.startsWith("http://") || text.startsWith("https://") -> {
                val uri = Uri.parse(text)
                token = uri.getQueryParameter("token").orEmpty()
                require(token.isNotBlank()) {
                    "Este QR Code é apenas um link. Gere o QR de acesso em Segurança › Acessos pessoais."
                }
                url = "${uri.scheme}://${uri.host}" + (if (uri.port != -1) ":${uri.port}" else "") +
                    (if (uri.path?.contains("/casa") == true) "/casa" else "")
            }
            else -> token = text
        }
        require(token.isNotBlank()) { "QR Code não contém um código de acesso válido." }
        return Result(MobileAuth.loginWithApiKey(context, url, token, name), url, false)
    }
}

/** Botões de acesso por QR Code da tela de login. */
@Composable
fun QrAccessSection(busy: Boolean, onScan: () -> Unit, onPickImage: () -> Unit) {
    LcarsSectionLabel("ACESSO RÁPIDO VIA QR CODE", LcarsColors.Orange)
    LcarsMenuButton(
        title = "LER QR CODE DA CASA",
        subtitle = "No site: Segurança › Acessos pessoais › Gerar QR Code",
        color = LcarsColors.Orange
    ) { if (!busy) onScan() }
    Spacer(Modifier.height(4.dp))
    Button(
        onClick = { if (!busy) onPickImage() },
        modifier = Modifier.fillMaxWidth(),
        colors = ButtonDefaults.buttonColors(containerColor = LcarsColors.Blue),
        enabled = !busy
    ) {
        Text("IMPORTAR QR CODE DA GALERIA / FOTO", style = MaterialTheme.typography.labelSmall)
    }
}
