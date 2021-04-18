
//Criado por Marcelo Maurin Martins
function atualizaSensor(value) 
{
	$('#gaugeSensor .gauge-arrow').trigger('updateGauge', value);
	alert("Sensor");
	return 0;
};

function atualizaHumidade(value) 
{
	$('#gaugeHumidade .gauge-arrow').trigger('updateGauge', value);
	alert("Humidade");
	return 0;
};


function atualizaValores()
{
	valoralpha1 = $('#valHumidade').text();
	valor1 = parseInt(valoralpha1);
	$('#gaugeHumidade .gauge-arrow').trigger('updateGauge',valor1);
	$('#gaugeHumidade .gauge-arrow').cmGauge();
	
	valoralpha2 = $('#valSensor').text();
	valor2 = parseInt(valoralpha2);
	$('#gaugeSensor .gauge-arrow').trigger('updateGauge',valor2);
	$('#gaugeSensor .gauge-arrow').cmGauge();

}
					