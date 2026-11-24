package br.com.maurinsoft.jarvismobile

/** Comandos de resultado desconhecido exigem revisão, mesmo após reconectar. */
object PendingCommandPolicy {
    const val MAX_AGE_MS = 5 * 60 * 1000L

    fun isFresh(createdAt: Long, now: Long): Boolean =
        createdAt > 0 && now >= createdAt && now - createdAt <= MAX_AGE_MS

    /** Posições da fila cujos comandos não podem mais ser enviados. */
    fun expiredIndices(createdAt: List<Long>, now: Long): Set<Int> =
        createdAt.indices.filterTo(linkedSetOf()) { !isFresh(createdAt[it], now) }
}
