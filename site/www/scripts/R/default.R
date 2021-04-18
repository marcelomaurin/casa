# Criado por Marcelo Maurin Martins
# Script para geração de Graficos dinâmicos em R
# Analise de informações estatísticas


#install.packages(“dbConnect”,type=”source”)
library(RMySQL)
library(dbConnect)

con = dbConnect(MySQL(), user="root", password="226468", dbname="casadb", host="192.168.1.211")

dbListTables(con) 


args <- commandArgs(TRUE)

#Captura os parametros
param1 <- args[1]
param2 <- args[2]
param3 <- args[3]

#Cria sql
myQuery = "select * from jobs"

#my_d_query=paste(“select devvalue, dtupdate from logdevpar where devparname="dev3" and iddevice="2" order by dtupdate and dtupdate >= ‘”,ne,”‘ “)
my_d_query=paste("select devvalue, dtupdate from logdevpar where devparname='dev3' and iddevice='2' order by dtupdate limit 1000")

dsDefault= dbGetQuery(con,my_d_query)

dbWriteTable(con,"out_df",out_df, overwrite=TRUE, append=FALSE)
dbDisconnect(con)

#Le a informação do banco armazenando em Dataset
#dsDefault <- rnorm(N,0,1)

png(filename="/var/www/html/casa/img/default1.png", width=500, height=500)
hist(dsDefault, col="lightblue")
dev.off()