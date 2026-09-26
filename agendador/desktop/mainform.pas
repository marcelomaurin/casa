unit mainform;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes,SysUtils,Forms,Controls,StdCtrls,ComCtrls,ExtCtrls,Dialogs,DateUtils,fpjson,jsonparser,store,jobs;
type
 TMainForm=class(TForm)
 private
  DB:TStore;Pages:TPageControl;Devices,Messages,Schedules,History:TListView;
  Prefix:TEdit;AutoScan:TCheckBox;Status:TLabel;Timer:TTimer;Job:TJob;LastScan:QWord;
  procedure RefreshLists;
  procedure StartJob(const Kind:string;Args:TJSONObject);
  procedure JobDone(Sender:TObject);
  procedure Tick(Sender:TObject);
  procedure Scan(Sender:TObject);
  procedure NewDevice(Sender:TObject);
  procedure EditDevice(Sender:TObject);
  procedure DeleteDevice(Sender:TObject);
  procedure Provision(Sender:TObject);
  procedure NewMessage(Sender:TObject);
  procedure EditMessage(Sender:TObject);
  procedure DeleteMessage(Sender:TObject);
  procedure SendMessageNow(Sender:TObject);
  procedure NewSchedule(Sender:TObject);
  procedure CancelSchedule(Sender:TObject);
  procedure ViewDevice(Sender:TObject);
  procedure CloseCheck(Sender:TObject;var CanClose:Boolean);
  procedure EditDeviceRecord(ID:Integer);
  procedure EditMessageRecord(ID:Integer);
  procedure SendTask(DeviceID:Integer;Message:TJSONObject;ScheduleID:Integer;const RequestID:string);
  procedure Log(DeviceID:Integer;const LogText:string);
 public
  constructor Create(AOwner:TComponent);override;
  destructor Destroy;override;
  procedure StopBackground;
 end;
var MainWindow:TMainForm;
implementation
uses epd_protocol,wifi_windows,Graphics;
function Stamp:string;begin Result:=FormatDateTime('yyyy-mm-dd hh:nn:ss',Now);end;
function SelectedID(L:TListView):Integer;
begin if L.Selected=nil then raise Exception.Create('Selecione um registro na lista');Result:=StrToInt(L.Selected.Caption);end;
function FormFields(const Title:string;const Labels:array of string;var Values:array of string;const Secrets:array of Integer):Boolean;
var F:TForm;E:array of TEdit;L:TLabel;B:TButton;I,K:Integer;
begin
 F:=TForm.CreateNew(nil);try
 F.Caption:=Title;F.Position:=poScreenCenter;F.BorderStyle:=bsDialog;F.ClientWidth:=560;F.ClientHeight:=Length(Labels)*58+65;
 SetLength(E,Length(Labels));
 for I:=0 to High(Labels) do begin
  L:=TLabel.Create(F);L.Parent:=F;L.Caption:=Labels[I];L.SetBounds(16,10+I*58,525,18);
  E[I]:=TEdit.Create(F);E[I].Parent:=F;E[I].SetBounds(16,30+I*58,525,26);E[I].Text:=Values[I];
  for K:=0 to High(Secrets) do if Secrets[K]=I then E[I].PasswordChar:='*';
 end;
 B:=TButton.Create(F);B.Parent:=F;B.Caption:='Salvar';B.ModalResult:=mrOK;B.Default:=True;B.SetBounds(330,F.ClientHeight-46,100,30);
 B:=TButton.Create(F);B.Parent:=F;B.Caption:='Cancelar';B.ModalResult:=mrCancel;B.Cancel:=True;B.SetBounds(438,F.ClientHeight-46,100,30);
 Result:=F.ShowModal=mrOK;if Result then for I:=0 to High(E) do Values[I]:=E[I].Text;
 finally F.Free;end;
end;
function ChooseDevice(DB:TStore):Integer;
var F:TForm;C:TComboBox;B:TButton;A:TJSONArray;I:Integer;
begin
 Result:=0;A:=DB.Rows('SELECT id,name,ip FROM devices ORDER BY name');F:=TForm.CreateNew(nil);
 try
 if A.Count=0 then raise Exception.Create('Cadastre um equipamento primeiro');
 F.Caption:='Equipamento de destino';F.Position:=poScreenCenter;F.BorderStyle:=bsDialog;F.ClientWidth:=500;F.ClientHeight:=110;
 C:=TComboBox.Create(F);C.Parent:=F;C.Style:=csDropDownList;C.SetBounds(16,16,468,28);
 for I:=0 to A.Count-1 do C.Items.Add(A.Objects[I].Get('name','')+' — '+A.Objects[I].Get('ip',''));
 C.ItemIndex:=0;
 B:=TButton.Create(F);B.Parent:=F;B.Caption:='Selecionar';B.ModalResult:=mrOK;B.SetBounds(250,65,110,30);
 B:=TButton.Create(F);B.Parent:=F;B.Caption:='Cancelar';B.ModalResult:=mrCancel;B.SetBounds(370,65,110,30);
 if F.ShowModal=mrOK then Result:=StrToInt(A.Objects[C.ItemIndex].Get('id','0'));
 finally F.Free;A.Free;end;
end;
procedure AddButton(Parent:TWinControl;const Text:string;Click:TNotifyEvent);
var B:TButton;
begin B:=TButton.Create(Parent);B.Parent:=Parent;B.Caption:=Text;B.AutoSize:=True;B.BorderSpacing.Around:=4;B.OnClick:=Click;end;
function MakeTab(Pages:TPageControl;const Caption:string;const Columns:array of string;out Bar:TFlowPanel):TListView;
var Tab:TTabSheet;I:Integer;
begin
 Tab:=TTabSheet.Create(Pages);Tab.PageControl:=Pages;Tab.Caption:=Caption;
 Bar:=TFlowPanel.Create(Tab);Bar.Parent:=Tab;Bar.Align:=alTop;Bar.Height:=46;Bar.BevelOuter:=bvNone;
 Result:=TListView.Create(Tab);Result.Parent:=Tab;Result.Align:=alClient;Result.ViewStyle:=vsReport;Result.ReadOnly:=True;Result.RowSelect:=True;
 for I:=0 to High(Columns) do with Result.Columns.Add do begin Caption:=Columns[I];Width:=140;if I=0 then Width:=50;end;
end;
constructor TMainForm.Create(AOwner:TComponent);
var Bar,TopBar:TFlowPanel;L:TLabel;Path:string;
begin
 inherited CreateNew(AOwner);Caption:='Agendador FATEC — Equipamentos e-paper';Width:=1160;Height:=690;Position:=poScreenCenter;
 Font.Name:='Segoe UI';Font.Size:=10;OnCloseQuery:=@CloseCheck;
 Path:=GetEnvironmentVariable('FATEC_AGENDADOR_DB');
 if Path='' then Path:=IncludeTrailingPathDelimiter(GetEnvironmentVariable('LOCALAPPDATA'))+'FatecAgendador'+DirectorySeparator+'agendador.sqlite3';
 DB:=TStore.Create(Path);
 TopBar:=TFlowPanel.Create(Self);TopBar.Parent:=Self;TopBar.Align:=alTop;TopBar.Height:=48;TopBar.BevelOuter:=bvNone;
 L:=TLabel.Create(TopBar);L.Parent:=TopBar;L.Caption:='Faixa IPv4 (/24):';L.BorderSpacing.Around:=10;
 Prefix:=TEdit.Create(TopBar);Prefix.Parent:=TopBar;Prefix.Text:=NetworkPrefix;Prefix.Width:=140;Prefix.BorderSpacing.Around:=6;
 AddButton(TopBar,'Buscar equipamentos',@Scan);
 AutoScan:=TCheckBox.Create(TopBar);AutoScan.Parent:=TopBar;AutoScan.Caption:='Atualizar a rede a cada 60 s';AutoScan.Checked:=True;AutoScan.BorderSpacing.Around:=10;
 Status:=TLabel.Create(Self);Status.Parent:=Self;Status.Align:=alBottom;Status.BorderSpacing.Around:=10;Status.Caption:='Pronto. Banco local: '+Path;
 Pages:=TPageControl.Create(Self);Pages.Parent:=Self;Pages.Align:=alClient;
 Devices:=MakeTab(Pages,'Equipamentos',['ID','Nome','IP DHCP','Rede Wi-Fi','Estado','Último contato','Wi-Fi de configuração'],Bar);
 AddButton(Bar,'Novo',@NewDevice);AddButton(Bar,'Editar',@EditDevice);AddButton(Bar,'Excluir',@DeleteDevice);AddButton(Bar,'Configurar via Wi-Fi',@Provision);AddButton(Bar,'Detalhes',@ViewDevice);
 Messages:=MakeTab(Pages,'Mensagens',['ID','Título','Linha 1','Linha 2','Linha 3','Linha 4','QR Code'],Bar);
 AddButton(Bar,'Nova',@NewMessage);AddButton(Bar,'Editar',@EditMessage);AddButton(Bar,'Excluir',@DeleteMessage);AddButton(Bar,'Enviar agora...',@SendMessageNow);AddButton(Bar,'Agendar...',@NewSchedule);
 Schedules:=MakeTab(Pages,'Agendamentos',['ID','Equipamento','Mensagem','Data / hora local','Situação','Tentativas','Último erro'],Bar);AddButton(Bar,'Cancelar selecionado',@CancelSchedule);
 History:=MakeTab(Pages,'Histórico',['ID','Data / hora','Equipamento ID','Resultado'],Bar);History.Columns[3].Width:=700;
 RefreshLists;LastScan:=GetTickCount64;
 Timer:=TTimer.Create(Self);Timer.Interval:=1000;Timer.OnTimer:=@Tick;Timer.Enabled:=True;
end;
destructor TMainForm.Destroy;begin DB.Free;inherited Destroy;end;
procedure TMainForm.StopBackground;begin Timer.Enabled:=False;AutoScan.Checked:=False;end;
procedure TMainForm.CloseCheck(Sender:TObject;var CanClose:Boolean);
begin CanClose:=Job=nil;if not CanClose then MessageDlg('Aguarde a operação de rede terminar. A configuração restaura o Wi-Fi antes de fechar.',mtInformation,[mbOK],0);end;
procedure TMainForm.RefreshLists;
 procedure Fill(L:TListView;const SQL:string;const Fields:array of string);
 var A:TJSONArray;I,K,Old:Integer;Item:TListItem;S:string;
 begin
  Old:=0;if L.Selected<>nil then Old:=StrToIntDef(L.Selected.Caption,0);A:=DB.Rows(SQL);L.Items.BeginUpdate;
  try L.Items.Clear;for I:=0 to A.Count-1 do begin Item:=L.Items.Add;Item.Caption:=A.Objects[I].Get('id','');
   for K:=0 to High(Fields) do begin S:=A.Objects[I].Get(Fields[K],'');if Fields[K]='online' then if S='1' then S:='Conectado' else S:='Não confirmado';Item.SubItems.Add(S);end;
   if StrToIntDef(Item.Caption,0)=Old then Item.Selected:=True;
  end;finally L.Items.EndUpdate;A.Free;end;
 end;
begin
 Fill(Devices,'SELECT * FROM devices ORDER BY name',['name','ip','ssid','online','last_seen','ap_ssid']);
 Fill(Messages,'SELECT * FROM messages ORDER BY title',['title','line1','line2','line3','line4','qr']);
 Fill(Schedules,'SELECT s.*,d.name AS device_name FROM schedules s LEFT JOIN devices d ON d.id=s.device_id ORDER BY s.due DESC',['device_name','message_id','due','status','attempts','error']);
 Fill(History,'SELECT * FROM history ORDER BY id DESC LIMIT 200',['at','device_id','message']);
end;
procedure TMainForm.Log(DeviceID:Integer;const LogText:string);
begin DB.Exec('INSERT INTO history(at,device_id,message) VALUES('+Q(Stamp)+','+IntToStr(DeviceID)+','+Q(LogText)+')');end;
procedure TMainForm.StartJob(const Kind:string;Args:TJSONObject);
begin
 if Job<>nil then begin Args.Free;raise Exception.Create('Aguarde a operação atual');end;
 Job:=TJob.Create(Kind,Args);Job.OnTerminate:=@JobDone;
 if Kind='scan' then Status.Caption:='Buscando equipamentos na rede...'
 else if Kind='send' then Status.Caption:='Enviando mensagem...'
 else Status.Caption:='Configurando ESP32 e aguardando DHCP; o Wi-Fi será restaurado ao terminar...';
 Job.Start;
end;
procedure TMainForm.Scan(Sender:TObject);
begin LastScan:=GetTickCount64;StartJob('scan',TJSONObject.Create(['prefix',Trim(Prefix.Text)]));end;
procedure TMainForm.EditDeviceRecord(ID:Integer);
var O:TJSONObject;V:array[0..6] of string;I:Integer;SQL:string;
begin
 if Job<>nil then raise Exception.Create('Aguarde a operação atual');
 V[0]:='EPD-TESTE';V[1]:='';V[2]:='';V[3]:='FATEC-EPD-';V[4]:='fatec1234';V[5]:=NewToken;V[6]:='';
 if ID>0 then begin O:=DB.Row('SELECT * FROM devices WHERE id='+IntToStr(ID));try
  V[0]:=O.Get('name','');V[1]:=O.Get('ssid','');V[2]:=UnprotectSecret(O.Get('password',''));V[3]:=O.Get('ap_ssid','');V[4]:=UnprotectSecret(O.Get('ap_password',''));V[5]:=UnprotectSecret(O.Get('token',''));V[6]:=O.Get('ip','');
 finally O.Free;end;end;
 if not FormFields('Cadastro do equipamento',['Nome (letras, números e hífen; até 32)','Rede Wi-Fi de destino (2,4 GHz)','Senha da rede de destino (vazia para rede aberta)','SSID de configuração mostrado pelo ESP32','Senha do Wi-Fi do ESP32','Chave de acesso (32 caracteres; guarde para recuperar o cadastro)','Último IP conhecido (opcional, atribuído por DHCP)'],V,[2,4,5]) then Exit;
 if (Length(V[0])<1) or (Length(V[0])>32) then raise Exception.Create('Nome deve ter 1 a 32 caracteres');
 for I:=1 to Length(V[0]) do if not (V[0][I] in ['a'..'z','A'..'Z','0'..'9','-']) then raise Exception.Create('Nome aceita letras, números e hífen');
 if (V[0][1]='-') or (V[0][Length(V[0])]='-') then raise Exception.Create('Nome não pode iniciar ou terminar com hífen');
 if Length(V[1])>32 then raise Exception.Create('SSID: máximo 32 bytes');
 if (V[2]<>'') and ((Length(V[2])<8) or (Length(V[2])>63)) then raise Exception.Create('Senha da rede: 8 a 63 caracteres');
 if Length(V[5])<>32 then raise Exception.Create('Chave deve ter 32 caracteres');
 if (V[6]<>'') and not ValidIPv4(V[6]) then raise Exception.Create('IP inválido');
 SQL:='name='+Q(V[0])+',ssid='+Q(V[1])+',password='+Q(ProtectSecret(V[2]))+',ap_ssid='+Q(V[3])+',ap_password='+Q(ProtectSecret(V[4]))+',token='+Q(ProtectSecret(V[5]))+',ip='+Q(V[6]);
 if ID=0 then begin DB.Exec('INSERT INTO devices(name) VALUES('+Q(V[0])+')');ID:=DB.LastID;end;
 DB.Exec('UPDATE devices SET '+SQL+' WHERE id='+IntToStr(ID));RefreshLists;
end;
procedure TMainForm.NewDevice(Sender:TObject);begin EditDeviceRecord(0);end;
procedure TMainForm.EditDevice(Sender:TObject);begin EditDeviceRecord(SelectedID(Devices));end;
procedure TMainForm.DeleteDevice(Sender:TObject);
var ID:Integer;O:TJSONObject;
begin
 if Job<>nil then raise Exception.Create('Aguarde a operação atual');ID:=SelectedID(Devices);
 O:=DB.Row('SELECT id FROM schedules WHERE device_id='+IntToStr(ID)+' AND status IN (''pending'',''sending'') LIMIT 1');
 if O<>nil then begin O.Free;raise Exception.Create('Cancele os agendamentos pendentes antes de excluir');end;
 if MessageDlg('Excluir este cadastro e suas credenciais locais?',mtConfirmation,[mbYes,mbNo],0)=mrYes then begin DB.Exec('DELETE FROM devices WHERE id='+IntToStr(ID));RefreshLists;end;
end;
procedure TMainForm.Provision(Sender:TObject);
var O,A:TJSONObject;
begin
 O:=DB.Row('SELECT * FROM devices WHERE id='+IntToStr(SelectedID(Devices)));
 if (O.Get('ssid','')='') or (O.Get('ap_ssid','')='') then begin O.Free;raise Exception.Create('Edite o cadastro e preencha as duas redes Wi-Fi');end;
 if MessageDlg('O computador será conectado temporariamente ao Wi-Fi do ESP32. A rede anterior será restaurada ao terminar. Continuar?',mtConfirmation,[mbYes,mbNo],0)<>mrYes then begin O.Free;Exit;end;
 A:=TJSONObject.Create;A.Add('device',O);StartJob('provision',A);
end;
procedure TMainForm.EditMessageRecord(ID:Integer);
var V:array[0..5] of string;O:TJSONObject;SQL:string;I:Integer;
begin
 V[0]:='Nova mensagem';V[1]:='FATEC-RP';V[2]:='Equipamento de teste';V[3]:='';V[4]:='';V[5]:='FATEC-RP';
 if ID>0 then begin O:=DB.Row('SELECT * FROM messages WHERE id='+IntToStr(ID));try V[0]:=O.Get('title','');for I:=1 to 4 do V[I]:=O.Get('line'+IntToStr(I),'');V[5]:=O.Get('qr','');finally O.Free;end;end;
 if not FormFields('Mensagem — quatro linhas e QR Code',['Título do cadastro','Linha 1 (até 26 caracteres)','Linha 2 (até 26 caracteres)','Linha 3 (até 26 caracteres)','Linha 4 (até 26 caracteres)','QR Code (texto ou URL; até 53 bytes UTF-8)'],V,[]) then Exit;
 if Trim(V[0])='' then raise Exception.Create('Informe o título');ValidateMessage([V[1],V[2],V[3],V[4]],V[5]);
 SQL:='title='+Q(V[0]);for I:=1 to 4 do SQL:=SQL+',line'+IntToStr(I)+'='+Q(NormalizeLine(V[I]));SQL:=SQL+',qr='+Q(V[5]);
 if ID=0 then begin DB.Exec('INSERT INTO messages(title,line1,line2,line3,line4,qr) VALUES('+Q(V[0])+','+Q(V[1])+','+Q(V[2])+','+Q(V[3])+','+Q(V[4])+','+Q(V[5])+')');ID:=DB.LastID;end;
 DB.Exec('UPDATE messages SET '+SQL+' WHERE id='+IntToStr(ID));RefreshLists;
end;
procedure TMainForm.NewMessage(Sender:TObject);begin EditMessageRecord(0);end;
procedure TMainForm.EditMessage(Sender:TObject);begin EditMessageRecord(SelectedID(Messages));end;
procedure TMainForm.DeleteMessage(Sender:TObject);
begin if MessageDlg('Excluir a mensagem? Agendamentos existentes preservam o conteúdo salvo.',mtConfirmation,[mbYes,mbNo],0)=mrYes then begin DB.Exec('DELETE FROM messages WHERE id='+IntToStr(SelectedID(Messages)));RefreshLists;end;end;
procedure TMainForm.SendTask(DeviceID:Integer;Message:TJSONObject;ScheduleID:Integer;const RequestID:string);
var A,D:TJSONObject;
begin
 D:=DB.Row('SELECT * FROM devices WHERE id='+IntToStr(DeviceID));if D=nil then raise Exception.Create('Equipamento não cadastrado');
 A:=TJSONObject.Create(['schedule_id',ScheduleID,'request_id',RequestID]);A.Add('device',D);A.Add('message',Message.Clone);StartJob('send',A);
end;
procedure TMainForm.SendMessageNow(Sender:TObject);
var D:Integer;M:TJSONObject;
begin
 M:=DB.Row('SELECT * FROM messages WHERE id='+IntToStr(SelectedID(Messages)));
 try D:=ChooseDevice(DB);if D>0 then SendTask(D,M,0,NewToken);finally M.Free;end;
end;
procedure TMainForm.NewSchedule(Sender:TObject);
var D,MID:Integer;M:TJSONObject;WhenText:string;Due:TDateTime;
begin
 MID:=SelectedID(Messages);D:=ChooseDevice(DB);if D=0 then Exit;
 WhenText:=FormatDateTime('yyyy-mm-dd hh:nn',IncMinute(Now,5));
 if not InputQuery('Agendar envio','Data e hora local (AAAA-MM-DD HH:MM):',WhenText) then Exit;
 Due:=ScanDateTime('yyyy-mm-dd hh:nn',WhenText);
 if Due<=Now then raise Exception.Create('Escolha uma data futura');
 M:=DB.Row('SELECT * FROM messages WHERE id='+IntToStr(MID));try
 DB.Exec('INSERT INTO schedules(device_id,message_id,due,request_id,payload) VALUES('+IntToStr(D)+','+IntToStr(MID)+','+Q(FormatDateTime('yyyy-mm-dd hh:nn:ss',Due))+','+Q(NewToken)+','+Q(M.AsJSON)+')');
 finally M.Free;end;RefreshLists;Pages.ActivePageIndex:=2;
end;
procedure TMainForm.CancelSchedule(Sender:TObject);
begin
 if MessageDlg('Cancelar o envio pendente selecionado?',mtConfirmation,[mbYes,mbNo],0)=mrYes then begin
 DB.Exec('UPDATE schedules SET status=''cancelled'' WHERE id='+IntToStr(SelectedID(Schedules))+' AND status IN (''pending'',''failed'')');RefreshLists;end;
end;
procedure TMainForm.ViewDevice(Sender:TObject);
var O:TJSONObject;
begin O:=DB.Row('SELECT * FROM devices WHERE id='+IntToStr(SelectedID(Devices)));try
 MessageDlg('Equipamento', 'Nome: '+O.Get('name','')+LineEnding+'Identidade: '+O.Get('hardware_id','')+LineEnding+'IP DHCP: '+O.Get('ip','')+LineEnding+'Último contato: '+O.Get('last_seen','')+LineEnding+'TCP: 8090'+LineEnding+'Wi-Fi para configurar: '+O.Get('ap_ssid',''),mtInformation,[mbOK],0);
 finally O.Free;end;end;
procedure TMainForm.Tick(Sender:TObject);
var S,M:TJSONObject;ID:Integer;
begin
 if (Job<>nil) or (Application.ModalLevel>0) then Exit;
 S:=DB.ClaimDue(Stamp);
 if S<>nil then begin
  try
   ID:=StrToInt(S.Get('id','0'));M:=TJSONObject(GetJSON(S.Get('payload','{}')));
   try
    try SendTask(StrToInt(S.Get('device_id','0')),M,ID,S.Get('request_id',''));
    except on E:Exception do DB.Exec('UPDATE schedules SET status=''failed'',error='+Q(E.Message)+' WHERE id='+IntToStr(ID));end;
   finally M.Free;end;
  finally S.Free;end;RefreshLists;Exit;
 end;
 if AutoScan.Checked and (GetTickCount64-LastScan>=60000) then Scan(nil);
end;
procedure TMainForm.JobDone(Sender:TObject);
var J:TJob;A:TJSONArray;R,D,O:TJSONObject;I,ID,SID:Integer;PrefixValue,Hardware,IP,ResultText:string;
begin
 J:=TJob(Sender);Job:=nil;
 try
  if J.Kind='scan' then begin
   if J.Error<>'' then raise Exception.Create(J.Error);
   A:=TJSONArray(GetJSON(J.Output));try
    PrefixValue:=J.Args.Get('prefix','');DB.Exec('UPDATE devices SET online=0 WHERE ip LIKE '+Q(PrefixValue+'.%'));
    for I:=0 to A.Count-1 do begin
     R:=A.Objects[I];Hardware:=R.Get('id','');IP:=R.Get('observed_ip','');O:=DB.Row('SELECT * FROM devices WHERE hardware_id='+Q(Hardware));
     if O=nil then begin
      DB.Exec('INSERT INTO devices(hardware_id,name,ip,ssid,token,ap_ssid,ap_password) VALUES('+Q(Hardware)+','+Q(R.Get('name',''))+','+Q(IP)+','+Q(R.Get('ssid',''))+','+Q(ProtectSecret(NewToken))+','+Q(R.Get('ap_ssid',''))+','+Q(ProtectSecret('fatec1234'))+')');ID:=DB.LastID;
     end else begin ID:=StrToInt(O.Get('id','0'));O.Free;end;
     DB.Exec('UPDATE devices SET online=1,ip='+Q(IP)+',last_seen='+Q(Stamp)+' WHERE id='+IntToStr(ID));
    end;
    Status.Caption:=Format('Busca concluída: %d equipamento(s) na faixa %s.0/24',[A.Count,PrefixValue]);
   finally A.Free;end;
  end else begin
   D:=J.Args.Objects['device'];ID:=StrToInt(D.Get('id','0'));SID:=J.Args.Get('schedule_id',0);
   if (J.Kind='provision') and (J.Output<>'') then begin
    R:=TJSONObject(GetJSON(J.Output));try
     Hardware:=R.Get('id','');O:=DB.Row('SELECT id FROM devices WHERE hardware_id='+Q(Hardware)+' AND id<>'+IntToStr(ID));
     if O<>nil then begin O.Free;raise Exception.Create('Equipamento já cadastrado com outra identificação. Edite o cadastro existente.');end;
     DB.Exec('UPDATE devices SET hardware_id='+Q(Hardware)+',ip='+Q(R.Get('ip',''))+',online=0 WHERE id='+IntToStr(ID));
    finally R.Free;end;
   end;
   if J.Error='' then begin
    ResultText:='Envio aceito pelo ESP32';if J.Kind='provision' then ResultText:='Configuração salva e DHCP confirmado; rede anterior restaurada. Execute Buscar equipamentos.';
    if SID>0 then DB.CompleteSchedule(SID,True,'','');
   end else begin
    ResultText:=J.Error;
    if SID>0 then DB.CompleteSchedule(SID,False,ResultText,FormatDateTime('yyyy-mm-dd hh:nn:ss',IncSecond(Now,30)));
   end;
   Log(ID,ResultText);Status.Caption:=ResultText;
   if (J.Error<>'') and (SID=0) then MessageDlg(ResultText,mtError,[mbOK],0);
  end;
 except on E:Exception do begin Status.Caption:=E.Message;MessageDlg(E.Message,mtError,[mbOK],0);end;end;
 RefreshLists;
end;
end.
