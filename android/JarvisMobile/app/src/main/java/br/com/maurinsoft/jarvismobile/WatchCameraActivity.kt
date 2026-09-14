package br.com.maurinsoft.jarvismobile

import android.content.Intent
import android.graphics.Bitmap
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import java.io.File
import java.io.FileOutputStream

class WatchCameraActivity : ComponentActivity() {

    companion object {
        const val ACTION_CAMERA_RESULT = "br.com.maurinsoft.jarvismobile.CAMERA_RESULT"
        const val EXTRA_CAMERA_OK = "camera_ok"
        const val EXTRA_CAMERA_PATH = "camera_path"
    }

    private val cameraLauncher = registerForActivityResult(ActivityResultContracts.TakePicturePreview()) { bitmap ->
        if (bitmap != null) {
            val file = File(cacheDir, "watch_camera_${System.currentTimeMillis()}.jpg")
            runCatching {
                FileOutputStream(file).use { out ->
                    bitmap.compress(Bitmap.CompressFormat.JPEG, 88, out)
                }
            }
            reportResult(true, file.absolutePath)
        } else {
            reportResult(false, "")
        }
        finish()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        cameraLauncher.launch(null)
    }

    private fun reportResult(ok: Boolean, path: String) {
        val service = Intent(this, JarvisConnectionService::class.java).apply {
            action = ACTION_CAMERA_RESULT
            putExtra(EXTRA_CAMERA_OK, ok)
            putExtra(EXTRA_CAMERA_PATH, path)
        }
        ContextCompat.startForegroundService(this, service)
    }
}
