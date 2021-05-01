<SCRIPT src="libs.js" type=text/javascript></SCRIPT>
//Biblioteca de funcoes de JavaScript
//Criado por Marcelo Maurin Martins
//20/02/2006

//Mostra mensagem alertando algo
function Showmessage(var info)
{
	alert(info);
	return(1);
}

//Se Confirma operação executa ação
function Show_Confirm(msg)
{
  if (confirm(msg))
  {
		return true;
  }
   else
  {
		return false;
  }
}

//Mostra mensagem de alerta
function Show_Warning(msg)
{
	alert("Atenção! "+msg);
}

//Mostra mensagem de alerta
function Show_Erro(msg)
{
	alert("Erro! "+msg);
}
 

//Abre a janela do browser
function openurl(var url)
{
	//window.open(url);
	location.replace(url);
	return (1);
}

/*Função que autoprograma checkbox, tipo seleciona tudo*/
function MudaCkb(Formulario)
{
		
		for(var a=0;a < Formulario.elements.length;a++)	{
		   
			if( Formulario.elements[a].type=="checkbox")
			{
			 
			 	Formulario.elements[a].checked = Formulario.SelCkb.checked
			}
		}	 		
}	

/*valida o cpf*/
/*
	<form name=frmCli> 
	<input type=text name=txtCpf size=11 maxlength=11 onblur="return validacpf()">CPF 
   Para validar o cpf, basta seguir o exemplo
*/
function validacpf(txtCpf)
{ 
	
	var i; 
	s = txtCpf.value; 
	if(s!='')
	{
		var c = s.substr(0,9); 
		var dv = s.substr(9,2); 
		var d1 = 0; 
		
		for (i = 0; i < 9; i++) 
		{ 
			d1 += c.charAt(i)*(10-i); 
		} 
	  
		if (d1 == 0)
		{   
			alert("CPF Invalido") 
			txtCpf.focus();
			return false; 
		} 
	  
		d1 = 11 - (d1 % 11); 
	  
		if (d1 > 9) 
			d1 = 0; 
	  
		if (dv.charAt(0) != d1) 
		{ 
			alert("CPF Invalido");
			txtCpf.focus();
			return false; 
		} 
	
		d1 *= 2; 
		for (i = 0; i < 9; i++) 
		{ 
			d1 += c.charAt(i)*(11-i); 
		} 
	  
		d1 = 11 - (d1 % 11);   
		if (d1 > 9) d1 = 0; 
	  
		if (dv.charAt(1) != d1)  
		{ 
			alert("CPF Invalido");
			txtCpf.focus(); 
	   	return false; 
		} 
	}  
	return true; 
} 

/*
function validacpf()
{
	var i;
	s = document.frmCli.txtCpf.value;
	var c = s.substr(0,9);
	var dv = s.substr(9,2);
	var d1 = 0;
	for (i = 0; i < 9; i++)
	{
		d1 += c.charAt(i)*(10-i);
	}
	if (d1 == 0)
	{
		Showmessage("CPF Invalido")
		return false;
	}
	
	d1 = 11 - (d1 % 11);
	if (d1 > 9) d1 = 0;
	if (dv.charAt(0) != d1)
	{
		Showmessage("CPF Invalido")
		return false;
	}
	d1 *= 2;
	for (i = 0; i < 9; i++)
	{
		d1 += c.charAt(i)*(11-i);
	}
	
	d1 = 11 - (d1 % 11);
	if (d1 > 9) d1 = 0;
	if (dv.charAt(1) != d1)
	{
		Showmessage("CPF Invalido")
		return false;
	}
	return true;
} 
*/


/*Função de mascara a data*/
function mascara_data(data, edit)
{ 
              var mydata = ''; 
              mydata = mydata + data; 
              if (mydata.length == 2)
				  { 
                  mydata = mydata + '/'; 
                  edit.value = mydata; 
              } 
              if (mydata.length == 5)
				  { 
                  mydata = mydata + '/'; 
                  edit.value = mydata; 
              } 
              if (mydata.length == 10)
				  { 
                  verifica_data(edit); 
              } 
} 

/*Realiza a verificação da data*/           
function verifica_data(edit) 
{ 
			
            dia = (edit.value.substring(0,2)); 
            mes = (edit.value.substring(3,5)); 
            ano = (edit.value.substring(6,10)); 

            situacao = true; 
				if( edit.value != "")
				{
	            // verifica o dia valido para cada mes 
	            if ((dia < 01)||(dia < 01 || dia > 30) && (  mes == 04 || mes == 06 || mes == 09 || mes == 11 ) || dia > 31) { 
	                situacao = false; 
	            } 
	
	            // verifica se o mes e valido 
	            if (mes < 01 || mes > 12 ) { 
	                situacao = false; 
	            } 
	
	            // verifica se e ano bissexto 
	            if (mes == 2 && ( dia < 01 || dia > 29 || ( dia > 28 && (parseInt(ano / 4) != ano / 4)))) { 
	                situacao = false; 
	            } 
	    
	            if (edit.value == "") { 
	                situacao = false; 
	            } 
	    
	            if (situacao == false) { 
	                
						Show_Erro("Data Inválida! Formato da data: dd/mm/yyyy"); 
	                edit.focus(); 
	            } 
				}
				return situacao;
} 

/*Mascara hora*/
function mascara_hora(hora, edit)
{ 
              var myhora = ''; 
              myhora = myhora + hora; 
              if (myhora.length == 2){ 
                  myhora = myhora + ':'; 
                  edit.value = myhora; 
              } 
              if(myhora.length == 5){ 
                  verifica_hora(edit); 
              } 
} 


/*função de range numerico*/           
function checa_range(edit,inicio,fim)
{ 
               
              situacao = true; 

				  if(Number(edit.value) == NaN) 
				  {
                  situacao = false; 
						alert('Valor não é um número');
                  edit.focus(); 
              } 
				   else
				  {
				  	   var Nro = Number(edit.value)
						if((Nro < inicio) || (Nro > fim))
						{
	                  situacao = false; 
		               alert('Valor fora da faixa!'); 
      		         edit.focus(); 
						}
   				
              } 
				  return situacao;

} 


//Mostra um hint em uma caixa de texto especificada
function view_hint(edit,Msg)
{
 		edit.value = Msg;
}


//Limpa um item selecionado do combobox
function limpa_item(combo)
{
	for(a=0;a<=combo.options.length-1;a++)
	{
		if(combo.options[a].selected!=false)
		{
		 	//combo.options[a] = null;
			//combo.options.remove(a);
			combo.remove(a);

		}
	}
}

//Testa item verificando se o mesmo ja existe
function testa_item(combo,item)
{
	//alert(item.text);
	var boExiste = false;
 	for(ab=0;ab<=combo.options.length-1;ab++)
	{
		//alert(combo.options[ab].text);
	 	if(combo.options[ab].text==item.text)
		{
			boExiste = true;
			alert('Item ja existe:'+ item.text);
			
		}
	}
	//alert(boExiste);
	return boExiste;

}

//verifica o navegador
sAgent = navigator.userAgent;
bIsIE = sAgent.indexOf("MSIE") > -1;
bIsNav = sAgent.indexOf("Mozilla") > -1 && !bIsIE;

//setando as variaveis de controle de eventos do mouse
var xmouse = 0;
var ymouse = 0;
document.onmousemove = MouseMove;

//funcoes de controle de eventos do mouse:
function MouseMove(e){
if (e) { MousePos(e); } else { MousePos();}
}

function MousePos(e) {
if (bIsNav){
 xmouse = e.pageX;
 ymouse = e.pageY;
} 
if (bIsIE) {
 xmouse = document.body.scrollLeft + event.x;
 ymouse = document.body.scrollTop + event.y;
}
}

//funcao que mostra e esconde o hint
function Hint(action, info){
//action = 1 -> Esconder
//action = 2 -> Mover
objNome = 'link';

if (bIsIE) {
 objHint = document.all[objNome]; 
}
if (bIsNav) {
 objHint = document.getElementById(objNome);
 event = objHint;
}
objHint.innerHTML = info;
switch (action){
 case 1: //Esconder
  objHint.style.visibility = "hidden";
  break;
 case 2: //Mover
  objHint.style.visibility = "visible";
  objHint.style.left = xmouse + 15;
  objHint.style.top = ymouse + 15;

  break;
}

}


</script>
