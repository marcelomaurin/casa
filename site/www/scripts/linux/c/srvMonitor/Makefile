#HEADERS = program.h headers.h

default: all

all: clean 
	gcc -o srvMonitor srvMonitor.c
	cp srvMonitor /sbin/

clean:
	-rm -f srvMonitor

