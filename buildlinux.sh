#!/bin/bash

PathSite="/home/mmm/Documentos/casa"
Pacote="site"

ARQUITETURA=$(uname -m)
case $(uname -m) in
	i386) 	ARQUITETURA="i386";;
	i686) 	ARQUITETURA="i386";;
	x86_64)	ARQUITETURA="amd64";;
	arm) 	ARQUITETURA="arm";;
esac

echo $ARQUITETURA

if [ $ARQUITETURA = 'amd64' ]; 
then
	echo "AMD64 Script"
	echo "Preparando binarios"
	#cp ./src/site ./mnote2/usr/bin/mnote2
	#chmod 777 ./mnote2/usr/bin/mnote2
	#cp ./mysql/*.sql ./mnote2/usr/share/site/mysql/
	#cp -Rf ./site/www/*.* ./mnote2/var/www/html/casa/
	#cp ./mnote2.desktop_arm ./mnote2/usr/share/applications/mnote2.desktop
	#ln -s /usr/bin/MNote2 ./mnote2/usr/share/applications/mnote2
	echo "Empacotando na Pasta $PathSite"
	dpkg-deb --build $PathSite/$Pacote
	echo "Movendo para pasta repositorio $PathSite"
	#mv $PathSite/site.deb $PathSite/$Pacote
	cp $PathSite/$Pacote $PathSite/bin/
	exit 1;
fi

if [ $ARQUITETURA = 'i686' ];
then
	echo "i686 Script"
	echo "Preparando binarios"
	#cp ./src/MNote2 ./mnote2/usr/bin/mnote2
	#chmod 777 ./mnote2/usr/bin/mnote2
	#cp ./mysql/*.sql ./mnote2/usr/share/site/mysql/
	#cp -Rf ./site/www/*.* ./mnote2/var/www/html/casa/
	#cp ./mnote2.desktop_arm ./mnote2/usr/share/applications/mnote2.desktop
	#ln -s /usr/bin/MNote2 ./mnote2/usr/share/applications/mnote2
	echo "Empacotando"
	dpkg-deb --build site
	echo "Movendo para pasta repositorio"
	mv site.deb $(Pacote)
	cp ./$(Pacote) ./bin/
	exit 1;
fi

if [ $ARQUITETURA = 'i386' ];
then
	echo "i386 Script"
	echo "Preparando binarios"
	#cp ./src/MNote2 ./mnote2/usr/bin/mnote2
	#chmod 777 ./mnote2/usr/bin/mnote2
	#cp ./mysql/*.sql ./mnote2/usr/share/site/mysql/
	#cp -Rf ./site/www/*.* ./mnote2/var/www/html/casa/
	#cp ./mnote2.desktop_arm ./mnote2/usr/share/applications/mnote2.desktop
	#ln -s /usr/bin/MNote2 ./mnote2/usr/share/applications/mnote2
	echo "Empacotando"
	dpkg-deb --build site
	echo "Movendo para pasta repositorio"
	mv site.deb $(Pacote)
	cp ./$(Pacote) ./bin/
	exit 1;
fi

if [ $ARQUITETURA =  'armv7l' ]; then
	echo "ARM Script"
	echo "Preparando binarios"
	#cp ./src/MNote2 ./mnote2/usr/bin/mnote2
	#chmod 777 ./mnote2/usr/bin/mnote2
	#ln -s /usr/bin/MNote2 ./mnote2/usr/bin/mnote2
	cp ./mysql/*.sql ./mnote2/usr/share/site/mysql/
	cp -Rf ./site/www/*.* ./mnote2/var/www/html/casa/
	#cp ./mnote2.desktop_arm ./mnote2/usr/share/applications/mnote2.desktop
	echo "Empacotando"
	dpkg-deb --build site
	echo "Movendo para pasta repositorio"
	mv site.deb $(Pacote)
	cp $(Pacote) ./bin/	
	exit 1;
fi
