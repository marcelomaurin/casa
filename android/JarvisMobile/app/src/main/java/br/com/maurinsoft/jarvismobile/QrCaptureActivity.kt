package br.com.maurinsoft.jarvismobile

import android.content.pm.PackageManager
import android.view.View
import android.widget.Button
import com.journeyapps.barcodescanner.CaptureActivity
import com.journeyapps.barcodescanner.DecoratedBarcodeView

class QrCaptureActivity : CaptureActivity(), DecoratedBarcodeView.TorchListener {
    private lateinit var barcodeScannerView: DecoratedBarcodeView
    private var btnFlash: Button? = null
    private var isTorchOn = false

    override fun initializeContent(): DecoratedBarcodeView {
        setContentView(R.layout.activity_qr_capture)
        barcodeScannerView = findViewById(R.id.zxing_barcode_scanner)

        // Desativa a linha laser vermelha típica de leitor de código de barras 1D
        barcodeScannerView.viewFinder?.setLaserVisibility(false)

        // Habilita foco contínuo e medição para facilitar leitura em telas pequenas
        barcodeScannerView.barcodeView?.cameraSettings?.let { settings ->
            settings.isAutoFocusEnabled = true
            settings.isContinuousFocusEnabled = true
            settings.isMeteringEnabled = true
            settings.isBarcodeSceneModeEnabled = true
        }

        findViewById<View>(R.id.btn_close)?.setOnClickListener {
            finish()
        }

        btnFlash = findViewById(R.id.btn_flash)
        if (!hasFlash()) {
            btnFlash?.visibility = View.GONE
        } else {
            barcodeScannerView.setTorchListener(this)
            btnFlash?.setOnClickListener {
                if (isTorchOn) {
                    barcodeScannerView.setTorchOff()
                } else {
                    barcodeScannerView.setTorchOn()
                }
            }
        }

        return barcodeScannerView
    }

    private fun hasFlash(): Boolean {
        return applicationContext.packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_FLASH)
    }

    override fun onTorchOn() {
        isTorchOn = true
        btnFlash?.text = "🔦 APAGAR"
    }

    override fun onTorchOff() {
        isTorchOn = false
        btnFlash?.text = "🔦 LANTERNA"
    }
}
