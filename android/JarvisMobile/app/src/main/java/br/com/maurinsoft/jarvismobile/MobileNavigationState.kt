package br.com.maurinsoft.jarvismobile

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * Estado de navegação do JARVIS Mobile.
 *
 * Mantém a pilha fora da Activity para que a navegação possa ser testada
 * sem misturar regras de fluxo com a renderização Compose.
 */
class MobileNavigationState(initial: MobileRoute = MobileRoute.HOME) {
    var stack by mutableStateOf(listOf(initial))
        private set

    val route: MobileRoute
        get() = stack.last()

    val canBack: Boolean
        get() = stack.size > 1

    fun open(route: MobileRoute) {
        if (stack.lastOrNull() != route) stack = stack + route
    }

    fun back() {
        if (stack.size > 1) stack = stack.dropLast(1)
    }

    fun home() {
        stack = listOf(MobileRoute.HOME)
    }

    fun title(): String = when (route) {
        MobileRoute.HOME -> "JARVIS Mobile"
        MobileRoute.CASA_MENU -> "CASA"
        MobileRoute.JARVIS_MENU -> "JARVIS"
        MobileRoute.WATCH_MENU -> "Watch"
        MobileRoute.DEVICES_MENU -> "Devices"
        MobileRoute.SYSTEM_MENU -> "Sistema"
        MobileRoute.OPERATIONS -> "Operações"
        MobileRoute.VOICE -> "Voz"
        MobileRoute.WATCH_STATUS -> "Watch / Estado"
        MobileRoute.DEVICES_LIST -> "Devices / Lista"
        MobileRoute.CONFIG -> "Sistema / Configuração"
    }
}
