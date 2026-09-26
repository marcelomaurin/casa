unit selftests;
{$mode objfpc}{$H+}{$codepage utf8}
interface
procedure RunTests(const Dir:string;ProtocolTests:Boolean);
implementation
uses Classes,SysUtils,Windows,store,wifi_windows,epd_protocol,fpjson,jobs,mainform;
procedure Check(OK:Boolean;const Why:string);
begin if not OK then raise Exception.Create('TEST FAILED: '+Why);end;
procedure RunTests(const Dir:string;ProtocolTests:Boolean);
var DB:TStore;O,P,M,D,A:TJSONObject;F:TMainForm;Report:TStringList;S,Path:string;I:Integer;Failed:Boolean;J:TJob;
begin
 ForceDirectories(Dir);Report:=TStringList.Create;
 try
 Check(NormalizeLine('Ribeirão Preto')='Ribeirao Preto','normalizar acentos');
 ValidateMessage(['FATEC-RP','Equipamento de teste','',''],'https://fatecrp.cps.sp.gov.br');
 Failed:=False;try ValidateMessage([StringOfChar('A',27),'','',''],'');except Failed:=True;end;Check(Failed,'rejeitar linha longa');
 Failed:=False;try ValidateMessage(['','','',''],StringOfChar('A',54));except Failed:=True;end;Check(Failed,'rejeitar QR longo');
 Check(ValidIPv4('192.168.4.1') and not ValidIPv4('192.168.4.999'),'validar IP');
 S:=ProtectSecret('senha-test-ç-"<&');Check(S<>'senha-test-ç-"<&','senha protegida');Check(UnprotectSecret(S)='senha-test-ç-"<&','DPAPI roundtrip');
 Report.Add('PASS: validacao de texto, QR, IP e criptografia DPAPI');
 Path:=IncludeTrailingPathDelimiter(Dir)+'test-'+NewToken+'.sqlite3';DB:=TStore.Create(Path);
 try
 DB.Exec('INSERT INTO devices(name,token) VALUES('+Q('Sala ''Teste''')+','+Q(S)+')');
 DB.Exec('INSERT INTO messages(title,line1,line2,line3,line4,qr) VALUES('+Q('Título')+','+Q('a')+','+Q('b')+','+Q('c')+','+Q('d')+','+Q('https://example.com')+')');
 O:=DB.Row('SELECT * FROM devices WHERE id=1');try Check(O.Get('name','')='Sala ''Teste''','CRUD com aspas');finally O.Free;end;
 DB.Exec('UPDATE messages SET title='+Q('Editada')+' WHERE id=1');O:=DB.Row('SELECT title FROM messages WHERE id=1');try Check(O.Get('title','')='Editada','update');finally O.Free;end;
 DB.Exec('INSERT INTO schedules(device_id,message_id,due,request_id,payload) VALUES(1,1,''2026-01-01 10:00:00'',''test-id'',''{}'')');
 O:=DB.ClaimDue('2026-01-01 09:00:00');Check(O=nil,'nao enviar antes da hora');
 for I:=1 to 5 do begin
  O:=DB.ClaimDue('2026-01-01 11:00:00');Check(O<>nil,'encontrar agendamento');O.Free;
  O:=DB.ClaimDue('2026-01-01 11:00:00');Check(O=nil,'nao duplicar envio em andamento');
  DB.CompleteSchedule(1,False,'offline','2026-01-01 10:30:00');
 end;
 O:=DB.Row('SELECT * FROM schedules WHERE id=1');try Check(O.Get('status','')='failed','limite de tentativas');Check(O.Get('attempts','')='5','cinco tentativas');finally O.Free;end;
 DB.Exec('UPDATE schedules SET status=''sending'' WHERE id=1');
 finally DB.Free;end;
 DB:=TStore.Create(Path);try O:=DB.ClaimDue('2026-01-01 11:00:00');Check(O<>nil,'recuperar envio interrompido');O.Free;DB.CompleteSchedule(1,True,'','');
 O:=DB.Row('SELECT status FROM schedules WHERE id=1');try Check(O.Get('status','')='sent','confirmacao');finally O.Free;end;
 DB.Exec('DELETE FROM messages WHERE id=1');O:=DB.Row('SELECT id FROM messages');Check(O=nil,'delete');finally DB.Free;end;
 Report.Add('PASS: SQLite CRUD, hora programada, exclusao mutua, retries e recuperacao apos reinicio');
 SetEnvironmentVariable('FATEC_AGENDADOR_DB',PChar(IncludeTrailingPathDelimiter(Dir)+'ui-'+NewToken+'.sqlite3'));
 F:=TMainForm.Create(nil);try F.StopBackground;Check((F.ComponentCount>=4) and (F.ControlCount=3),'interface construida');finally F.Free;end;
 Report.Add('PASS: construcao da interface Lazarus sem exibir janelas ou mudar Wi-Fi');
 if ProtocolTests then begin
  P:=TJSONObject.Create(['op','discover']);try O:=Request('127.0.0.1',P);try Check(O.Get('product','')='FATEC_EPD','resposta TCP fragmentada');finally O.Free;end;finally P.Free;end;
  for I:=0 to 1 do begin
   D:=TJSONObject.Create(['id','1','hardware_id','SIM-01','ip','127.0.0.1','token',ProtectSecret('01234567890123456789012345678901')]);
   if I=1 then begin D.Delete('hardware_id');D.Add('hardware_id','OUTRO');end;
   M:=TJSONObject.Create(['line1','FATEC-RP','line2','Equipamento de teste','line3','Sala 1','line4','10:00','qr','https://example.com']);
   A:=TJSONObject.Create(['request_id','sim-test','schedule_id',0]);A.Add('device',D);A.Add('message',M);
   J:=TJob.Create('send',A);J.FreeOnTerminate:=False;try J.Start;J.WaitFor;
    if I=0 then Check(J.Error='','envio real pelo worker: '+J.Error) else Check(J.Error<>'','bloquear IP de outro equipamento');
   finally J.Free;end;
  end;
  P:=TJSONObject.Create(['op','invalid_test']);try Failed:=False;try O:=Request('127.0.0.1',P);O.Free;except Failed:=True;end;Check(Failed,'rejeitar erro remoto');finally P.Free;end;
  Report.Add('PASS: TCP fragmentado, envio autenticado de quatro linhas/QR e bloqueio de identidade errada');
 end;
 Report.Add('ALL TESTS PASSED');Report.SaveToFile(IncludeTrailingPathDelimiter(Dir)+'result.txt');
 finally Report.Free;end;
end;
end.
