#HEADERS = program.h headers.h

MYSQLCFLAGS= -I/usr/include/mysql -DBIG_JOINS=1 -fno-strict-aliasing -g
MYSQLLIBS= -L/usr/lib64/mysql -lmysqlclient  -lpthread -lz -lm -lrt -ldl

default: all

all: clean 
	gcc -o srvCorrente srvCorrente.c $(MYSQLCFLAGS) $(MYSQLLIBS)
	

clean:
	-rm -f srvCorrente


