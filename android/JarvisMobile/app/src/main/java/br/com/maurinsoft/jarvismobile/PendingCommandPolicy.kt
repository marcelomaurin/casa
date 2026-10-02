package br.com.maurinsoft.jarvismobile

/** Comandos de resultado desconhecido exigem revisão, mesmo após reconectar. */
object PendingCommandPolicy {
    fun isFresh(createdAt: Long, now: Long): Boolean =
        createdAt > 0 && now >= createdAt && now - createdAt <= 5 * 60 * 1000L
}
