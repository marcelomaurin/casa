unit jobs;
{$mode objfpc}{$H+}
interface
uses Classes,SysUtils,fpjson,jsonparser,epd_protocol,wifi_windows;
type
 TJob=class(TThread)
 public
  Kind,Error,Output:string;
  Args:TJSONObject;
  constructor Create(const AKind:string;AArgs:TJSONObject);
  destructor Destroy;override;
  procedure Execute;override;
 end;
implementation
type
 TScanPart=class(TThread)
 public Prefix:string;StartHost:Integer;Found:TJSONArray;
  constructor Create(const P:string;First:Integer);
  destructor Destroy;override;
  procedure Execute;override;
 end;
constructor TScanPart.Create(const P:string;First:Integer);
begin inherited Create(True);Prefix:=P;StartHost:=First;Found:=TJSONArray.Create;end;
destructor TScanPart.Destroy;begin Found.Free;inherited Destroy;end;
procedure TScanPart.Execute;
var I:Integer;P,R:TJSONObject;IP:string;
begin
 I:=StartHost;P:=TJSONObject.Create(['op','discover']);
 try
 while I<=254 do begin
  IP:=Prefix+'.'+IntToStr(I);
  try
   R:=Request(IP,P,650);
   if (R.Get('product','')='FATEC_EPD') and (R.Get('protocol',0)=2) and (R.Get('id','')<>'') then begin
    R.Add('observed_ip',IP);Found.Add(R);
   end else R.Free;
  except end;
  Inc(I,16);
 end;
 finally P.Free;end;
end;
constructor TJob.Create(const AKind:string;AArgs:TJSONObject);
begin inherited Create(True);FreeOnTerminate:=True;Kind:=AKind;Args:=AArgs;end;
destructor TJob.Destroy;begin Args.Free;inherited Destroy;end;
procedure TJob.Execute;
var Workers:array[0..15] of TScanPart;I,J:Integer;A:TJSONArray;R,P,Dev,Msg:TJSONObject;
 W:TWifiSession;IP,RestoreError,Expected:string;
begin
 try
  if Kind='scan' then begin
   if not ValidIPv4(Args.Get('prefix','')+'.1') then raise Exception.Create('Informe uma faixa como 192.168.1');
   A:=TJSONArray.Create;FillChar(Workers,SizeOf(Workers),0);
   try
    for I:=0 to 15 do begin Workers[I]:=TScanPart.Create(Args.Get('prefix',''),I+1);Workers[I].Start;end;
    for I:=0 to 15 do begin Workers[I].WaitFor;for J:=0 to Workers[I].Found.Count-1 do A.Add(Workers[I].Found.Items[J].Clone);end;
    Output:=A.AsJSON;
   finally for I:=0 to 15 do if Assigned(Workers[I]) then begin Workers[I].WaitFor;Workers[I].Free;end;A.Free;end;
  end else if Kind='send' then begin
   Dev:=Args.Objects['device'];Msg:=Args.Objects['message'];
   IP:=Dev.Get('ip','');P:=TJSONObject.Create(['op','discover']);
   try R:=Request(IP,P,4000);finally P.Free;end;
   try
    if (R.Get('product','')<>'FATEC_EPD') or (R.Get('protocol',0)<>2) or (R.Get('id','')<>Dev.Get('hardware_id','')) then raise Exception.Create('O IP pertence a outro equipamento. Execute Buscar equipamentos.');
   finally R.Free;end;
   P:=TJSONObject.Create(['op','set','token',UnprotectSecret(Dev.Get('token','')),'request_id',Args.Get('request_id','')]);
   P.Add('lines',TJSONArray.Create([Msg.Get('line1',''),Msg.Get('line2',''),Msg.Get('line3',''),Msg.Get('line4','')]));P.Add('qr',Msg.Get('qr',''));
   try R:=Request(IP,P,8000);try Output:=R.AsJSON;finally R.Free;end;finally P.Free;end;
  end else if Kind='provision' then begin
   Dev:=Args.Objects['device'];W:=TWifiSession.Create;
   try
    try
     W.JoinSetup(Dev.Get('ap_ssid',''),UnprotectSecret(Dev.Get('ap_password','')));
     P:=TJSONObject.Create(['op','discover']);R:=nil;
     try
      for I:=1 to 15 do begin try R:=Request('192.168.4.1',P,1500);Break;except if I=15 then raise;Sleep(1000);end;end;
     finally P.Free;end;
     try
      if (R.Get('product','')<>'FATEC_EPD') or (R.Get('protocol',0)<>2) then raise Exception.Create('Firmware incompativel; grave o firmware v2');
      Expected:=Dev.Get('hardware_id','');
      if (Expected<>'') and (Expected<>R.Get('id','')) then raise Exception.Create('Identidade do ESP32 diferente do cadastro');
     finally R.Free;end;
     P:=TJSONObject.Create(['op','configure','name',Dev.Get('name',''),'ssid',Dev.Get('ssid',''),
      'password',UnprotectSecret(Dev.Get('password','')),'token',UnprotectSecret(Dev.Get('token','')),'new_token',UnprotectSecret(Dev.Get('token',''))]);
     try R:=Request('192.168.4.1',P,8000);finally P.Free;end;
     try Output:=R.AsJSON;finally R.Free;end;
     P:=TJSONObject.Create(['op','status']);
     try
      for I:=1 to 35 do begin
       Sleep(1000);
       try R:=Request('192.168.4.1',P,1500);except Continue;end;
       try Output:=R.AsJSON;if R.Get('connected',False) then Break;finally R.Free;end;
      end;
     finally P.Free;end;
     R:=TJSONObject(GetJSON(Output));try if not R.Get('connected',False) then raise Exception.Create('Configuracao salva, mas sem IP DHCP confirmado. Confira senha/rede e tente Buscar equipamentos.');finally R.Free;end;
    finally
     try W.Restore;except on E:Exception do begin RestoreError:='Falha ao restaurar Wi-Fi: '+E.Message; if Error='' then Error:=RestoreError;end;end;
    end;
   finally W.Free;end;
  end;
 except on E:Exception do if Error='' then Error:=E.Message else Error:=E.Message+' / '+Error;end;
end;
end.
